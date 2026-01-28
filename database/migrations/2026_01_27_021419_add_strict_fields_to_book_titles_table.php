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
            $table->string('subtitle')->nullable()->after('title');
            $table->string('accession_no')->nullable()->after('category'); // Or after proper field
            $table->string('lccn')->nullable()->after('isbn');
            $table->string('issn')->nullable()->after('lccn');
            $table->string('place_of_publication')->nullable()->after('publisher');
            $table->string('physical_description')->nullable()->after('pages'); // Assuming pages exists from prev migration
            $table->string('edition')->nullable()->after('physical_description');
            $table->year('copyright_year')->nullable()->after('published_year');
            $table->string('series')->nullable()->after('edition');
            $table->string('volume')->nullable()->after('series');
            $table->decimal('price', 10, 2)->nullable()->after('volume');
            $table->decimal('book_penalty', 10, 2)->nullable()->after('price');
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
