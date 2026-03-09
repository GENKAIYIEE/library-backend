<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->string('college')->nullable()->after('category');
        });
    }

    public function down()
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->dropColumn('college');
        });
    }
};
