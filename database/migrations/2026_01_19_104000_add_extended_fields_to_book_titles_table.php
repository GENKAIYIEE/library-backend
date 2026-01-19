<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddExtendedFieldsToBookTitlesTable extends Migration
{
    /**
     * Run the migrations.
     * Adds publisher, published_year, call_number, pages, language, location, and image_path to book_titles.
     */
    public function up()
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->string('publisher')->nullable()->after('category');
            $table->year('published_year')->nullable()->after('publisher');
            $table->string('call_number')->nullable()->after('published_year');
            $table->integer('pages')->nullable()->after('call_number');
            $table->string('language')->nullable()->after('pages');
            $table->string('location')->nullable()->after('description');
            $table->string('image_path')->nullable()->after('location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->dropColumn([
                'publisher',
                'published_year',
                'call_number',
                'pages',
                'language',
                'location',
                'image_path'
            ]);
        });
    }
}
