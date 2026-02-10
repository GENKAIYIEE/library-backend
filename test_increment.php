<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    echo "Simulating increment via script...\n";
    $success = App\Models\MonthlyStatistic::incrementForCallNumber('123', 'faculty');

    if ($success) {
        echo "Increment returned TRUE.\n";
    } else {
        echo "Increment returned FALSE.\n";
    }

    echo "Checking DB...\n";
    $stats = App\Models\MonthlyStatistic::where('user_type', 'faculty')->get();
    if ($stats->isEmpty()) {
        echo "No faculty stats found.\n";
    } else {
        foreach ($stats as $stat) {
            echo "Found: Range {$stat->range_start}-{$stat->range_end}, Count: {$stat->count}\n";
        }
    }
} catch (\Throwable $e) {
    echo "ERROR CAUGHT:\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . ":" . $e->getLine() . "\n";
}
