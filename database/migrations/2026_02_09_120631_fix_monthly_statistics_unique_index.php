<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class FixMonthlyStatisticsUniqueIndex extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('monthly_statistics', function (Blueprint $table) {
            // Drop old unique index
            $table->dropUnique(['year', 'month', 'range_start']);

            // Create new unique index
            $table->unique(['year', 'month', 'range_start', 'user_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('monthly_statistics', function (Blueprint $table) {
            $table->dropUnique(['year', 'month', 'range_start', 'user_type']);
            $table->unique(['year', 'month', 'range_start']);
        });
    }
}
