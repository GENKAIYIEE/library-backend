<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTransactionsTable extends Migration
{
    public function up()
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users'); 
            $table->foreignId('book_asset_id')->constrained('book_assets'); 
            $table->foreignId('processed_by')->constrained('users'); 
            $table->timestamp('borrowed_at');
            $table->date('due_date');
            $table->timestamp('returned_at')->nullable();
            $table->decimal('fine_amount', 8, 2)->default(0);
            $table->string('signature_url')->nullable(); 
            $table->text('notes')->nullable(); 
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('transactions');
    }
}