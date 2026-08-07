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
        Schema::create('journal_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_transactions');
    }
};
