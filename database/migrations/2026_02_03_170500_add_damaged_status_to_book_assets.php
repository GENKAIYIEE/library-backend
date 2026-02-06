<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddDamagedStatusToBookAssets extends Migration
{
    /**
     * Run the migrations.
     * Adds 'damaged' to the status enum for book_assets table.
     */
    public function up()
    {
        // For MySQL, we need to modify the enum column
        DB::statement("ALTER TABLE book_assets MODIFY COLUMN status ENUM('available', 'borrowed', 'maintenance', 'lost', 'damaged') DEFAULT 'available'");
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        // Remove 'damaged' from enum (revert to original)
        DB::statement("ALTER TABLE book_assets MODIFY COLUMN status ENUM('available', 'borrowed', 'maintenance', 'lost') DEFAULT 'available'");
    }
}
