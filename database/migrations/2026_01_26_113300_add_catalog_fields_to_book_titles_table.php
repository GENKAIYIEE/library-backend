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
            if (!Schema::hasColumn('book_titles', 'accession_no')) {
                $table->string('accession_no')->nullable()->after('isbn');
            }
            if (!Schema::hasColumn('book_titles', 'lccn')) {
                $table->string('lccn')->nullable()->after('accession_no');
            }
            if (!Schema::hasColumn('book_titles', 'issn')) {
                $table->string('issn')->nullable()->after('lccn');
            }
            if (!Schema::hasColumn('book_titles', 'book_penalty')) {
                $table->decimal('book_penalty', 8, 2)->nullable()->after('language');
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
