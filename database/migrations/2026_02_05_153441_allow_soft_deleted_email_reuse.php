<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AllowSoftDeletedEmailReuse extends Migration
{
    /**
     * Run the migrations.
     * 
     * Drop the unique constraint on email and student_id columns
     * to allow reuse of emails/IDs from soft-deleted users.
     * Uniqueness is now enforced in application code (StudentController).
     *
     * @return void
     */
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the unique index on email
            $table->dropUnique(['email']);
            
            // Drop the unique index on student_id
            $table->dropUnique(['student_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            // Re-add the unique constraints
            $table->unique('email');
            $table->unique('student_id');
        });
    }
}

