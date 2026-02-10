<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFacultyRoleSettings extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Insert faculty-specific settings
        \DB::table('library_settings')->insert([
            [
                'key' => 'faculty_loan_days',
                'value' => '14',
                'type' => 'integer',
                'group' => 'circulation',
                'description' => 'Number of days for faculty book loans',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'key' => 'faculty_fine_per_day',
                'value' => '5',
                'type' => 'integer',
                'group' => 'circulation',
                'description' => 'Fine amount in pesos per day for overdue faculty books',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        \DB::table('library_settings')
            ->whereIn('key', ['faculty_loan_days', 'faculty_fine_per_day'])
            ->delete();
    }
}
