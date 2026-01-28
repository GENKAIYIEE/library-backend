<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * Adds extended catalog fields to book_titles table.
     */
    public function up(): void
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->string('accession_no')->nullable()->after('isbn');
            $table->string('lccn')->nullable()->after('accession_no');
            $table->string('issn')->nullable()->after('lccn');
            $table->decimal('book_penalty', 8, 2)->nullable()->after('language');
            $table->string('place_of_publication')->nullable()->after('publisher');
            $table->string('physical_description')->nullable()->after('pages');
            $table->string('edition')->nullable()->after('physical_description');
            $table->year('copyright_year')->nullable()->after('published_year');
            $table->string('series')->nullable()->after('edition');
            $table->string('volume')->nullable()->after('series');
            $table->decimal('price', 10, 2)->nullable()->after('volume');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_titles', function (Blueprint $table) {
            $table->dropColumn([
                'accession_no',
                'lccn',
                'issn',
                'book_penalty',
                'place_of_publication',
                'physical_description',
                'edition',
                'copyright_year',
                'series',
                'volume',
                'price'
            ]);
        });
    }
};
