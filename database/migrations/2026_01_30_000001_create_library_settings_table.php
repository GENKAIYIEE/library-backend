<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('library_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value');
            $table->string('type')->default('string'); // string, integer, boolean
            $table->string('group')->default('general'); // general, circulation
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Seed default settings
        DB::table('library_settings')->insert([
            [
                'key' => 'library_name',
                'value' => 'PCLU Library System',
                'type' => 'string',
                'group' => 'general',
                'description' => 'Name of the library displayed across the system',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'default_loan_days',
                'value' => '7',
                'type' => 'integer',
                'group' => 'circulation',
                'description' => 'Number of days for book loans (same for all students)',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'max_loans_per_student',
                'value' => '3',
                'type' => 'integer',
                'group' => 'circulation',
                'description' => 'Maximum number of books a student can borrow at once',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'fine_per_day',
                'value' => '5',
                'type' => 'integer',
                'group' => 'circulation',
                'description' => 'Fine amount in pesos per day for overdue books',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_settings');
    }
};
