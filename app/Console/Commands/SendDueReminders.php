<?php

namespace App\Console\Commands;

use App\Mail\BookDueNotification;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendDueReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'library:send-reminders';

    /**
     * The console command description.
     */
    protected $description = 'Send email reminders for books due tomorrow and overdue books';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting due reminder process...');

        $today = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        // 1. Find books due TOMORROW (Due Soon)
        $dueSoon = Transaction::whereNull('returned_at')
            ->whereDate('due_date', $tomorrow)
            ->with(['user', 'bookAsset.bookTitle'])
            ->get();

        $this->info("Found {$dueSoon->count()} books due tomorrow.");

        foreach ($dueSoon as $transaction) {
            $student = $transaction->user;
            if ($student && $student->email) {
                try {
                    Mail::to($student->email)->send(new BookDueNotification($student, $transaction, 'due_soon'));
                    Log::info("Due Soon reminder sent to: {$student->email} for book: {$transaction->bookAsset->bookTitle->title}");
                    $this->line("  ✓ Sent to {$student->name} ({$student->email})");
                } catch (\Exception $e) {
                    Log::error("Failed to send due soon reminder: {$e->getMessage()}");
                    $this->error("  ✗ Failed for {$student->email}: {$e->getMessage()}");
                }
            }
        }

        // 2. Find OVERDUE books (due date is before today)
        $overdue = Transaction::whereNull('returned_at')
            ->whereDate('due_date', '<', $today)
            ->with(['user', 'bookAsset.bookTitle'])
            ->get();

        $this->info("Found {$overdue->count()} overdue books.");

        foreach ($overdue as $transaction) {
            $student = $transaction->user;
            if ($student && $student->email) {
                try {
                    Mail::to($student->email)->send(new BookDueNotification($student, $transaction, 'overdue'));
                    Log::info("Overdue reminder sent to: {$student->email} for book: {$transaction->bookAsset->bookTitle->title}");
                    $this->line("  ✓ Overdue notice sent to {$student->name} ({$student->email})");
                } catch (\Exception $e) {
                    Log::error("Failed to send overdue reminder: {$e->getMessage()}");
                    $this->error("  ✗ Failed for {$student->email}: {$e->getMessage()}");
                }
            }
        }

        $this->info('Reminder process complete!');
        return Command::SUCCESS;
    }
}
