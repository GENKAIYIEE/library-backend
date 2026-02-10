<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RemoveFacultyFineSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        \DB::table('library_settings')
            ->where('key', 'faculty_fine_per_day')
            ->delete();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        \DB::table('library_settings')->insert([
            'key' => 'faculty_fine_per_day',
            'value' => '5',
            'type' => 'integer',
            'group' => 'circulation',
            'description' => 'Fine amount in pesos per day for overdue faculty books',
            'created_at' => now(),
            'updated_at' => now()
        ]);
    }
}
