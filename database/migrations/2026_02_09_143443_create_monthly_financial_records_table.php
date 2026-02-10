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
        Schema::create('monthly_financial_records', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month'); // 1-12
            $table->decimal('total_fines', 10, 2)->default(0);
            $table->decimal('total_collected', 10, 2)->default(0);
            $table->decimal('total_pending', 10, 2)->default(0);
            $table->integer('late_returns')->default(0);
            $table->timestamp('finalized_at')->nullable(); // null = current/active month
            $table->timestamps();

            // Ensure one record per month
            $table->unique(['year', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_financial_records');
    }
};
