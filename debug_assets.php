<?php

use App\Models\BookAsset;
use App\Models\BookTitle;

echo "--- Book Titles ---\n";
$titles = BookTitle::withCount('assets')->get();
foreach ($titles as $t) {
    echo "ID: {$t->id} | Title: {$t->title} | Reported Assets: {$t->assets_count}\n";
}

echo "\n--- Book Assets (Active) ---\n";
$assets = BookAsset::with('bookTitle')->get();
foreach ($assets as $a) {
    echo "ID: {$a->id} | Code: {$a->asset_code} | Status: {$a->status} | Title ID: {$a->book_title_id} \n";
}

echo "\n--- Book Assets (Trashed) ---\n";
$trashed = BookAsset::onlyTrashed()->get();
foreach ($trashed as $a) {
    echo "ID: {$a->id} | Code: {$a->asset_code} | DELETED \n";
}

echo "\n--- Total Counts ---\n";
echo "Active Count: " . BookAsset::count() . "\n";
echo "Trashed Count: " . BookAsset::onlyTrashed()->count() . "\n";
