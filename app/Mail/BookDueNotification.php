<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookDueNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $student;
    public $transaction;
    public $type; // 'due_soon' or 'overdue'
    public $bookTitle;
    public $dueDate;
    public $fineAmount;

    /**
     * Create a new message instance.
     */
    public function __construct($student, $transaction, $type = 'due_soon')
    {
        $this->student = $student;
        $this->transaction = $transaction;
        $this->type = $type;
        $this->bookTitle = $transaction->bookAsset->bookTitle->title ?? 'Unknown Book';
        $this->dueDate = $transaction->due_date;
        $this->fineAmount = $transaction->penalty_amount ?? 0;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        $subject = $this->type === 'overdue'
            ? '⚠️ OVERDUE: Your Library Book is Past Due!'
            : '📚 Reminder: Library Book Due Tomorrow';

        return $this->subject($subject)
            ->view('emails.book_due')
            ->with([
                'studentName' => $this->student->name,
                'bookTitle' => $this->bookTitle,
                'dueDate' => $this->dueDate,
                'isOverdue' => $this->type === 'overdue',
                'fineAmount' => $this->fineAmount
            ]);
    }
}
