<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tx = App\Models\FacultyTransaction::latest('id')->with('bookAsset.bookTitle')->first();
echo "Latest Transaction:\n";
if ($tx) {
    echo "ID: " . $tx->id . "\n";
    echo "Faculty: " . $tx->faculty_id . "\n";
    echo "Book Title: " . ($tx->bookAsset->bookTitle->title ?? 'N/A') . "\n";
    echo "Call Number: '" . ($tx->bookAsset->bookTitle->call_number ?? 'N/A') . "'\n";
    
    $callNumber = $tx->bookAsset->bookTitle->call_number;
    echo "Regex Check: ";
    if (preg_match('/^(\d{1,3})/', trim($callNumber), $matches)) {
        echo "MATCH: " . $matches[1] . "\n";
    } else {
        echo "FAILED TO PARSE\n";
    }
} else {
    echo "No transactions found.\n";
}

echo "\nLatest Statistic Record:\n";
$stat = App\Models\MonthlyStatistic::latest('updated_at')->first();
print_r($stat ? $stat->toArray() : 'None');

echo "\nLibrary Settings (Ranges):\n";
print_r(App\Models\LibrarySetting::getValue('statistics_ranges'));
