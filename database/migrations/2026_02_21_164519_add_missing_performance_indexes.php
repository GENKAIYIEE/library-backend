<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingPerformanceIndexes extends Migration
{
    /**
     * Run the migrations.
    public function up()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'returned_at'], 'idx_user_returned');
            $table->index(['book_asset_id', 'returned_at'], 'idx_asset_returned');
            $table->index('payment_status', 'idx_payment_status');
        });

        Schema::table('book_assets', function (Blueprint $table) {
            $table->index('status', 'idx_asset_status');
            $table->index('book_title_id', 'idx_asset_title_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('student_id', 'idx_user_student_id');
            $table->index('role', 'idx_user_role');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('idx_user_returned');
            $table->dropIndex('idx_asset_returned');
            $table->dropIndex('idx_payment_status');
        });

        Schema::table('book_assets', function (Blueprint $table) {
            $table->dropIndex('idx_asset_status');
            $table->dropIndex('idx_asset_title_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_user_student_id');
            $table->dropIndex('idx_user_role');
        });
    }
}
