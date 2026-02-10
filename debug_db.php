<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Configured Ranges:\n";
print_r(App\Models\LibrarySetting::getValue('statistics_ranges'));

echo "\nMonthly Statistics (Feb 2026):\n";
$stats = App\Models\MonthlyStatistic::where('year', 2026)->where('month', 2)->get();
if ($stats->isEmpty()) {
    echo "No statistics for Feb 2026.\n";
} else {
    foreach ($stats as $stat) {
        echo "Range: {$stat->range_start}-{$stat->range_end}, User: {$stat->user_type}, Count: {$stat->count}\n";
    }
}
