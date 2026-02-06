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
        Schema::create('monthly_statistics', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month'); // 1-12
            $table->integer('range_start'); // 0, 100, 200, ... 900
            $table->integer('range_end'); // 99, 199, 299, ... 999
            $table->integer('count')->default(0);
            $table->timestamps();

            // Ensure unique combination
            $table->unique(['year', 'month', 'range_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_statistics');
    }
};
