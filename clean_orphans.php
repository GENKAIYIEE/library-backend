<?php

use App\Models\BookAsset;
use App\Models\BookTitle;

echo "--- Cleaning Orphaned Assets ---\n";

// Get all assets where the related bookTitle does not exist
$orphans = BookAsset::doesntHave('bookTitle')->get();

if ($orphans->isEmpty()) {
    echo "No orphaned assets found.\n";
} else {
    foreach ($orphans as $orphan) {
        echo "Deleting Orphan Asset ID: {$orphan->id} | Code: {$orphan->asset_code} | Title ID: {$orphan->book_title_id}\n";
        $orphan->forceDelete(); // Hard delete to completely remove them
    }
    echo "Deleted " . $orphans->count() . " orphaned assets.\n";
}

echo "New Total Count: " . BookAsset::count() . "\n";
