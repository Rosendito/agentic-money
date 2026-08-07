<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Journal transactions and postings are one coherent schema slice: a posting always belongs to
     * exactly one journal transaction and cannot exist without it
     * (docs/09-domain-architecture.md, migration grouping).
     */
    public function up(): void
    {
        Schema::create('journal_transactions', function (Blueprint $table) {
            $table->id();
            // Core ledger history must survive a book deletion (ACC-005, LIF-007): restrict, never
            // cascade.
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->string('status');
            $table->timestamp('effective_at');
            $table->string('description')->nullable();
            $table->string('idempotency_key');
            $table->foreignId('reverses_transaction_id')->nullable();
            $table->string('correction_group_id')->nullable();
            $table->timestamps();

            // Idempotency scope for manual posting commands is the book (LIF-008).
            $table->unique(['book_id', 'idempotency_key']);

            // Composite unique index required as the FK target for postings(journal_transaction_id,
            // book_id) and for this table's own reversal self-reference.
            $table->unique(['id', 'book_id']);

            // Cross-book integrity (LIF-016): a transaction can only reverse another transaction in
            // the same book.
            $table->foreign(['reverses_transaction_id', 'book_id'])
                ->references(['id', 'book_id'])
                ->on('journal_transactions');
        });

        // Closed-vocabulary lifecycle validity (07-integrity-and-lifecycle.md,
        // "database-enforceable facts"): `status` must be one of the values below, matching
        // `App\Domain\Ledger\Enums\TransactionStatus` as of this migration (migrations do not
        // depend on application enum classes, see `.ai/rules/migrations.md`). Also LIF-004: a
        // reversal is a new event, so a transaction may never reference itself as its own reversal.
        // Applied before `postings` is created below: SQLite's CHECK-splicing technique drops and
        // recreates the table, which cannot happen once another table holds a foreign key into it.
        $statuses = ['Draft', 'Pending', 'Posted', 'Cancelled'];

        $this->addSqliteCheckConstraints('journal_transactions', [
            'status IN ('.$this->quotedList($statuses).')',
            'reverses_transaction_id IS NULL OR reverses_transaction_id <> id',
        ]);

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE journal_transactions ADD CONSTRAINT journal_transactions_status_check CHECK (status IN ('.$this->quotedList($statuses).'))');
            DB::statement('ALTER TABLE journal_transactions ADD CONSTRAINT journal_transactions_not_self_reversal_check CHECK (reverses_transaction_id IS NULL OR reverses_transaction_id <> id)');
        }

        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_transaction_id');
            $table->foreignId('account_id');
            $this->monetaryColumn($table, 'native_quantity');
            $this->monetaryColumn($table, 'functional_amount');
            $table->string('memo')->nullable();
            $table->timestamps();

            // Cross-book integrity (LIF-016): a posting's transaction and account must belong to the
            // same book as the posting itself.
            $table->foreign(['journal_transaction_id', 'book_id'])
                ->references(['id', 'book_id'])
                ->on('journal_transactions');

            $table->foreign(['account_id', 'book_id'])
                ->references(['id', 'book_id'])
                ->on('accounts');
        });

        // ADR-001 (amended): SQLite's NUMERIC affinity silently coerces decimal-literal text to an
        // 8-byte float, so `native_quantity`/`functional_amount` are stored as TEXT-affinity
        // columns there (see `monetaryColumn()`) and protected by a CHECK enforcing canonical
        // decimal syntax (optional sign, digits, optional fractional part) and a maximum scale of
        // 18. MySQL/PostgreSQL enforce this natively through `DECIMAL(38, 18)` and need no CHECK.
        $this->addSqliteCheckConstraints('postings', [
            $this->canonicalDecimalCheck('native_quantity'),
            $this->canonicalDecimalCheck('functional_amount'),
        ]);
    }

    /**
     * Declare a `DECIMAL(38, 18)` monetary column (ADR-001).
     *
     * On SQLite, `decimal()` compiles to a `numeric` column, which SQLite gives NUMERIC type
     * affinity. NUMERIC affinity silently coerces well-formed decimal literals into 8-byte IEEE
     * floats and truncates precision beyond roughly 15 significant digits — even when the value is
     * bound as a string — which breaks the lossless round-trip ADR-001 and LIF-011 require for
     * 18-fractional-digit crypto quantities. A `varchar` column keeps SQLite's TEXT affinity, so the
     * exact decimal string is stored and read back unchanged. Real `DECIMAL(38, 18)` enforcement
     * applies on MySQL/PostgreSQL, which do not coerce numeric-looking text this way.
     */
    private function monetaryColumn(Blueprint $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // Sign + up to 20 integer digits + dot + 18 fractional digits, with headroom.
            $table->string($column, 45);

            return;
        }

        $table->decimal($column, total: 38, places: 18);
    }

    /**
     * Build a SQLite CHECK expression enforcing canonical decimal syntax on a TEXT-affinity monetary
     * column: an optional leading `+`/`-` sign, one or more digits, and an optional `.` followed by
     * 1 to 18 fractional digits. No other characters are permitted.
     */
    private function canonicalDecimalCheck(string $column): string
    {
        return <<<SQL
            {$column} NOT GLOB '*[^0-9.+-]*'
            AND substr({$column}, 2) NOT GLOB '*[+-]*'
            AND substr({$column}, 1, 1) GLOB '[0-9+-]'
            AND (substr({$column}, 1, 1) NOT GLOB '[+-]' OR substr({$column}, 2, 1) GLOB '[0-9]')
            AND (length({$column}) - length(replace({$column}, '.', ''))) <= 1
            AND {$column} NOT GLOB '*.'
            AND (instr({$column}, '.') = 0 OR (length({$column}) - instr({$column}, '.')) <= 18)
            SQL;
    }

    /**
     * Reverse the migrations, in reverse dependency order.
     */
    public function down(): void
    {
        Schema::dropIfExists('postings');
        Schema::dropIfExists('journal_transactions');
    }

    /**
     * Quote and join enum values for a SQL `IN (...)` list.
     *
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        return collect($values)->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")->implode(', ');
    }

    /**
     * Add table-level CHECK constraints to a SQLite table after Blueprint has created it.
     *
     * SQLite only accepts CHECK constraints as part of `CREATE TABLE`; it has no `ALTER TABLE ADD
     * CONSTRAINT` support. Rather than hand-duplicating the column, index, and foreign-key
     * definitions Blueprint already compiled (a drift risk), this reads the table's own compiled
     * `CREATE TABLE` SQL back from `sqlite_master`, drops the freshly created (still empty) table,
     * and reissues that same SQL with the CHECK clauses appended before the closing parenthesis.
     * Named indexes created separately from the `CREATE TABLE` statement are dropped along with the
     * table and must be reissued afterward.
     *
     * @param  list<string>  $checks  Boolean SQL expressions, one per CHECK clause.
     */
    private function addSqliteCheckConstraints(string $table, array $checks): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        $definition = DB::selectOne(
            "select sql from sqlite_master where type = 'table' and name = ?",
            [$table]
        )->sql;

        $indexes = DB::select(
            "select sql from sqlite_master where type = 'index' and tbl_name = ? and sql is not null",
            [$table]
        );

        DB::statement("drop table {$table}");

        $checkClauses = collect($checks)->map(fn (string $check) => "CHECK ({$check})")->implode(', ');
        DB::statement(Str::beforeLast($definition, ')').", {$checkClauses})");

        foreach ($indexes as $index) {
            DB::statement($index->sql);
        }
    }
};
