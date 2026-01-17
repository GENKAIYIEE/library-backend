<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\OverdueBookNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CheckOverdueBooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'library:check-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for overdue books and notify admins';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Checking for overdue books...');

        // Find active transactions where due_date is in the past
        $overdueTransactions = Transaction::whereNull('returned_at')
            ->where('due_date', '<', Carbon::now())
            ->with(['user', 'bookAsset.bookTitle'])
            ->get();

        if ($overdueTransactions->isEmpty()) {
            $this->info('No overdue books found.');
            return 0;
        }

        $admins = User::where('role', 'admin')->get();
        $count = 0;

        foreach ($overdueTransactions as $txn) {
            // Calculate days overdue
            $dueDate = Carbon::parse($txn->due_date);
            $daysOverdue = Carbon::now()->diffInDays($dueDate);

            // Skip if 0 days (due today but not strictly "overdue" by full day logic depending on policy, but let's be strict: if < now, it's overdue)
            if ($daysOverdue < 1)
                continue;

            // Prevent Spam: Check if we already notified about this Transaction ID today
            // For MVP, since we don't have a notification_logs table, we can query the 'notifications' table on the User model
            // But checking JSON data in DB is heavy.
            // SIMPLIFICATION FOR MVP: 
            // We assume this command runs daily. We just notify. 
            // Ideally we'd tag the transaction or have a log. 

            foreach ($admins as $admin) {
                // Check if this admin already has an unread notification for this transaction
                $exists = $admin->unreadNotifications()
                    ->where('data->transaction_id', $txn->id)
                    ->exists();

                if (!$exists) {
                    $admin->notify(new OverdueBookNotification([
                        'transaction_id' => $txn->id,
                        'student_name' => $txn->user->name ?? 'Unknown Student',
                        'book_title' => $txn->bookAsset->bookTitle->title ?? 'Unknown Book',
                        'days_overdue' => (int) $daysOverdue
                    ]));
                    $count++;
                }
            }
        }

        $this->info("Sent {$count} notifications for " . $overdueTransactions->count() . " overdue books.");
        return 0;
    }
}
