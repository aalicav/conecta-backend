<?php

namespace App\Notifications;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ContractExpirationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $document;
    protected $isRecurring;

    /**
     * Create a new notification instance.
     */
    public function __construct(Document $document, bool $isRecurring = false)
    {
        $this->document = $document;
        $this->isRecurring = $isRecurring;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $healthPlan = $this->document->documentable;
        $expirationDate = $this->document->expiration_date;
        $daysUntilExpiration = now()->diffInDays($expirationDate);

        $message = (new MailMessage)
            ->subject($this->isRecurring ? 'Urgent: Contract Expiration Reminder' : 'Contract Expiration Alert')
            ->greeting('Hello ' . $notifiable->name)
            ->line('This is a ' . ($this->isRecurring ? 'reminder' : 'notification') . ' about an expiring contract.')
            ->line('Health Plan: ' . $healthPlan->name)
            ->line('Contract Description: ' . $this->document->description)
            ->line('Expiration Date: ' . $expirationDate->format('d/m/Y'))
            ->line('Days until expiration: ' . $daysUntilExpiration);

        if ($this->isRecurring) {
            $message->line('This is a recurring alert because the contract has not been renewed yet.');
        }

        $message->action('View Contract', url('/health-plans/' . $healthPlan->id . '/documents/' . $this->document->id))
            ->line('Please take necessary action to renew the contract before it expires.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $healthPlan = $this->document->documentable;
        $expirationDate = $this->document->expiration_date;
        $daysUntilExpiration = now()->diffInDays($expirationDate);

        return [
            'type' => 'contract_expiration',
            'document_id' => $this->document->id,
            'health_plan_id' => $healthPlan->id,
            'health_plan_name' => $healthPlan->name,
            'document_description' => $this->document->description,
            'expiration_date' => $expirationDate->format('Y-m-d'),
            'days_until_expiration' => $daysUntilExpiration,
            'is_recurring' => $this->isRecurring,
            'created_at' => now(),
        ];
    }
} 