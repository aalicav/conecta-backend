<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\BillingItem;

class BillingCustomNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $billingItem;
    protected $message;
    protected $type;

    /**
     * Create a new notification instance.
     */
    public function __construct(BillingItem $billingItem, string $message, string $type)
    {
        $this->billingItem = $billingItem;
        $this->message = $message;
        $this->type = $type;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        $channels = ['database'];
        
        if ($notifiable->notificationChannelEnabled('email')) {
            $channels[] = 'mail';
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $batch = $this->billingItem->billingBatch;
        $patientName = $this->billingItem->appointment?->solicitation?->patient?->name ?? 'Paciente';
        
        return (new MailMessage)
            ->subject('Notificação de Cobrança')
            ->greeting("Olá, {$notifiable->name}!")
            ->line($this->message)
            ->line("Paciente: {$patientName}")
            ->line("Valor: R$ " . number_format($this->billingItem->total_amount, 2, ',', '.'))
            ->line("Lote: #{$batch->id}")
            ->action('Ver Cobrança', url("/billing/batches/{$batch->id}"))
            ->line('Esta é uma notificação personalizada do sistema de faturamento.');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $batch = $this->billingItem->billingBatch;
        $patientName = $this->billingItem->appointment?->solicitation?->patient?->name ?? 'Paciente';
        
        return [
            'title' => 'Notificação de Cobrança',
            'body' => $this->message,
            'action_url' => "/billing/batches/{$batch->id}",
            'action_text' => 'Ver Cobrança',
            'icon' => 'dollar-sign',
            'priority' => 'normal',
            'type' => $this->type,
            'billing_item_id' => $this->billingItem->id,
            'billing_batch_id' => $batch->id,
            'patient_name' => $patientName,
            'amount' => $this->billingItem->total_amount,
            'created_at' => now()->toISOString(),
        ];
    }
} 