<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class SeedStatisticsRangesSetting extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $ranges = [];
        for ($i = 0; $i < 10; $i++) {
            $start = $i * 100;
            $end = $start + 99;
            $ranges[] = [
                'start' => $start,
                'end' => $end,
                'label' => sprintf("%03d-%03d", $start, $end)
            ];
        }

        \Illuminate\Support\Facades\DB::table('library_settings')->insert([
            'key' => 'statistics_ranges',
            'value' => json_encode($ranges), // Store as JSON string
            'type' => 'json',
            'group' => 'general',
            'description' => 'Configurable call number ranges for statistics reports',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
