<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddStrictFieldsToBookTitlesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('book_titles', function (Blueprint $table) {
            if (!Schema::hasColumn('book_titles', 'subtitle')) {
                $table->string('subtitle')->nullable()->after('title');
            }
            if (!Schema::hasColumn('book_titles', 'accession_no')) {
                $table->string('accession_no')->nullable()->after('category');
            }
            if (!Schema::hasColumn('book_titles', 'lccn')) {
                $table->string('lccn')->nullable()->after('isbn');
            }
            if (!Schema::hasColumn('book_titles', 'issn')) {
                $table->string('issn')->nullable()->after('lccn');
            }
            if (!Schema::hasColumn('book_titles', 'place_of_publication')) {
                $table->string('place_of_publication')->nullable()->after('publisher');
            }
            if (!Schema::hasColumn('book_titles', 'physical_description')) {
                $table->string('physical_description')->nullable()->after('pages');
            }
            if (!Schema::hasColumn('book_titles', 'edition')) {
                $table->string('edition')->nullable()->after('physical_description');
            }
            if (!Schema::hasColumn('book_titles', 'copyright_year')) {
                $table->year('copyright_year')->nullable()->after('published_year');
            }
            if (!Schema::hasColumn('book_titles', 'series')) {
                $table->string('series')->nullable()->after('edition');
            }
            if (!Schema::hasColumn('book_titles', 'volume')) {
                $table->string('volume')->nullable()->after('series');
            }
            if (!Schema::hasColumn('book_titles', 'price')) {
                $table->decimal('price', 10, 2)->nullable()->after('volume');
            }
            if (!Schema::hasColumn('book_titles', 'book_penalty')) {
                $table->decimal('book_penalty', 10, 2)->nullable()->after('price');
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->dropColumn([
                'subtitle',
                'accession_no',
                'lccn',
                'issn',
                'place_of_publication',
                'physical_description',
                'edition',
                'copyright_year',
                'series',
                'volume',
                'price',
                'book_penalty'
            ]);
        });
    }
}
