<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingPerformanceIndexes extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // --- Student Transactions ---
        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['user_id', 'returned_at'], 'idx_user_returned');
            $table->index(['book_asset_id', 'returned_at'], 'idx_asset_returned');
            $table->index('payment_status', 'idx_payment_status');
        });

        // --- Book Assets ---
        Schema::table('book_assets', function (Blueprint $table) {
            $table->index('status', 'idx_asset_status');
            $table->index('book_title_id', 'idx_asset_title_id');
        });

        // --- Users ---
        Schema::table('users', function (Blueprint $table) {
            $table->index('student_id', 'idx_user_student_id');
            $table->index('role', 'idx_user_role');
        });

        // --- Faculty Transactions ---
        Schema::table('faculty_transactions', function (Blueprint $table) {
            $table->index(['faculty_id', 'returned_at'], 'idx_faculty_tx_faculty_returned');
            $table->index(['book_asset_id', 'returned_at'], 'idx_faculty_tx_asset_returned');
            $table->index('payment_status', 'idx_faculty_tx_payment_status');
        });

        // --- Faculties ---
        Schema::table('faculties', function (Blueprint $table) {
            $table->index('department', 'idx_faculty_department');
            $table->index('status', 'idx_faculty_status');
        });

        // --- Book Titles ---
        Schema::table('book_titles', function (Blueprint $table) {
            $table->index('title', 'idx_book_title');
            $table->index('category', 'idx_book_category');
        });

        // --- Attendance Logs ---
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->index('logged_at', 'idx_attendance_logged_at');
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

        Schema::table('faculty_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_faculty_tx_faculty_returned');
            $table->dropIndex('idx_faculty_tx_asset_returned');
            $table->dropIndex('idx_faculty_tx_payment_status');
        });

        Schema::table('faculties', function (Blueprint $table) {
            $table->dropIndex('idx_faculty_department');
            $table->dropIndex('idx_faculty_status');
        });

        Schema::table('book_titles', function (Blueprint $table) {
            $table->dropIndex('idx_book_title');
            $table->dropIndex('idx_book_category');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropIndex('idx_attendance_logged_at');
        });
    }
}
