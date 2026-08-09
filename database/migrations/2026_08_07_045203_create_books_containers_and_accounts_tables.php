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
     * Books, containers, and accounts are one coherent schema slice: a book owns its containers
     * and accounts, and an account optionally belongs to one of the book's containers
     * (docs/09-domain-architecture.md, migration grouping).
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            // Core ledger history must survive a user deletion (ACC-005, LIF-007): restrict, never
            // cascade.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('functional_instrument_id')->constrained('instruments')->restrictOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamps();

            // Composite unique index required as the FK target for accounts(container_id, book_id),
            // enforcing that a container can only back an account within its own book (LIF-016).
            $table->unique(['id', 'book_id']);
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('container_id')->nullable();
            $table->string('name');
            $table->string('type');
            $table->foreignId('native_instrument_id')->constrained('instruments')->restrictOnDelete();
            $table->string('system_role')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // Composite unique index required as the FK target for postings(account_id, book_id).
            $table->unique(['id', 'book_id']);

            // Cross-book integrity (LIF-016): a container can only back an account in its own book.
            $table->foreign(['container_id', 'book_id'])
                ->references(['id', 'book_id'])
                ->on('containers');
        });

        // Closed-vocabulary lifecycle validity (07-integrity-and-lifecycle.md, "database-enforceable
        // facts"): `type` and `system_role` must be one of the values below. These are historical
        // schema-snapshot literals, matching `App\Domain\Ledger\Enums\AccountType` and
        // `SystemAccountRole` as of this migration; migrations do not depend on application enum
        // classes (see `.ai/rules/migrations.md`), so a future enum change requires its own
        // migration to alter this constraint. Portable across MySQL, PostgreSQL, and SQLite because
        // `CHECK (... IN (...))` is standard SQL, but SQLite can only apply a CHECK constraint as
        // part of `CREATE TABLE`, so it is spliced into the table definition already compiled by the
        // Blueprint above instead of issued as a later `ALTER TABLE` (see
        // `addSqliteCheckConstraints()`).
        $accountTypes = ['Asset', 'Liability', 'Equity', 'Income', 'Expense'];
        $systemRoles = [
            'OpeningEquity', 'IncomeControl', 'ExpenseControl', 'RealizedFxGain',
            'RealizedFxLoss', 'Fees', 'Rounding', 'CorrectionSuspense',
        ];

        $this->addSqliteCheckConstraints('accounts', [
            'type IN ('.$this->quotedList($accountTypes).')',
            'system_role IS NULL OR system_role IN ('.$this->quotedList($systemRoles).')',
        ]);

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('.$this->quotedList($accountTypes).'))');
            DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_system_role_check CHECK (system_role IS NULL OR system_role IN ('.$this->quotedList($systemRoles).'))');
        }

        // ACC-007/LIF-017: exactly one system account per role per book. A plain unique index would
        // also forbid more than one ordinary (system_role IS NULL) account per book, so this is a
        // partial index scoped to system-managed rows only. Both SQLite and PostgreSQL support a
        // `WHERE` clause on `CREATE UNIQUE INDEX` with identical syntax; Laravel's Blueprint has no
        // fluent method for a partial index.
        DB::statement('CREATE UNIQUE INDEX accounts_book_id_system_role_unique ON accounts (book_id, system_role) WHERE system_role IS NOT NULL');
    }

    /**
     * Reverse the migrations, in reverse dependency order.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('containers');
        Schema::dropIfExists('books');
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
