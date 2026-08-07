<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
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
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('postings');
    }
};
