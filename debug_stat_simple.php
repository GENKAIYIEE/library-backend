<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$tx = App\Models\FacultyTransaction::latest('id')->with('bookAsset.bookTitle')->first();
if ($tx) {
    echo "Call Number: '" . ($tx->bookAsset->bookTitle->call_number ?? 'N/A') . "'\n";
    $callNumber = $tx->bookAsset->bookTitle->call_number;
    if (preg_match('/^(\d{1,3})/', trim($callNumber), $matches)) {
        echo "Regex Match: " . $matches[1] . "\n";
    } else {
        echo "Regex Failed\n";
    }
} else {
    echo "No transactions.\n";
}
