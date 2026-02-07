<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Creates the faculty_transactions table for faculty book borrowing.
     */
    public function up(): void
    {
        Schema::create('faculty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->onDelete('cascade');
            $table->foreignId('book_asset_id')->constrained('book_assets')->onDelete('cascade');
            $table->timestamp('borrowed_at')->useCurrent();
            $table->date('due_date');
            $table->timestamp('returned_at')->nullable();
            $table->unsignedBigInteger('processed_by')->nullable()->comment('Admin user who processed');
            $table->decimal('penalty_amount', 10, 2)->default(0);
            $table->enum('payment_status', ['pending', 'paid', 'waived'])->nullable();
            $table->timestamp('payment_date')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            // Indexes for common queries
            $table->index('faculty_id');
            $table->index('book_asset_id');
            $table->index('borrowed_at');
            $table->index('returned_at');
            $table->index(['faculty_id', 'returned_at']);
            $table->index('payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faculty_transactions');
    }
};
