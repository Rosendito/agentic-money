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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_id')->nullable();
            $table->string('name');
            $table->string('type');
            $table->foreignId('native_instrument_id')->constrained('instruments');
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
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
