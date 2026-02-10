<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$output = "";

try {
    $output .= "Simulating increment via script...\n";
    $success = App\Models\MonthlyStatistic::incrementForCallNumber('123', 'faculty');

    if ($success) {
        $output .= "Increment returned TRUE.\n";
    } else {
        $output .= "Increment returned FALSE.\n";
    }

    $output .= "Checking DB...\n";
    $stats = App\Models\MonthlyStatistic::where('user_type', 'faculty')->get();
    if ($stats->isEmpty()) {
        $output .= "No faculty stats found.\n";
    } else {
        foreach ($stats as $stat) {
            $output .= "Found: Range {$stat->range_start}-{$stat->range_end}, Count: {$stat->count}\n";
        }
    }
} catch (\Throwable $e) {
    $output .= "ERROR CAUGHT:\n";
    $output .= $e->getMessage() . "\n";
    $output .= $e->getFile() . ":" . $e->getLine() . "\n";
}

file_put_contents('output_debug.txt', $output);
