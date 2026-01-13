<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBookAssetsTable extends Migration
{
    public function up()
    {
        Schema::create('book_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_title_id')->constrained('book_titles')->onDelete('cascade');
            $table->string('asset_code')->unique(); 
            $table->string('building')->nullable();
            $table->string('aisle')->nullable();
            $table->string('shelf')->nullable();
            $table->enum('status', ['available', 'borrowed', 'maintenance', 'lost'])->default('available');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('book_assets');
    }
}