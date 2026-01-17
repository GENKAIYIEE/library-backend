<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class OverdueBookNotification extends Notification
{
    use Queueable;

    protected $data;

    /**
     * Create a new notification instance.
     *
     * @param array $data ['transaction_id', 'student_name', 'book_title', 'days_overdue']
     */
    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database']; // Add 'mail' here later if SMTP is configured
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'type' => 'overdue_alert', // specific type identifier
            'transaction_id' => $this->data['transaction_id'],
            'student_name' => $this->data['student_name'],
            'book_title' => $this->data['book_title'],
            'days_overdue' => $this->data['days_overdue'],
            'message' => "Book '{$this->data['book_title']}' borrowed by {$this->data['student_name']} is {$this->data['days_overdue']} days overdue."
        ];
    }
}
