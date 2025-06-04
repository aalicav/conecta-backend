<?php

namespace App\Notifications;

use App\Models\BillingBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BillingBatchCreated extends Notification implements ShouldQueue
{
    use Queueable;

    protected $batch;

    /**
     * Create a new notification instance.
     */
    public function __construct(BillingBatch $batch)
    {
        $this->batch = $batch;
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
        $period = "de " . $this->batch->reference_period_start->format('d/m/Y') . 
                 " até " . $this->batch->reference_period_end->format('d/m/Y');

        return (new MailMessage)
            ->subject('Novo Lote de Faturamento Gerado')
            ->greeting('Olá!')
            ->line('Um novo lote de faturamento foi gerado para sua operadora.')
            ->line("Período de referência: {$period}")
            ->line("Total de atendimentos: {$this->batch->items_count}")
            ->line("Valor total: R$ " . number_format($this->batch->total_amount, 2, ',', '.'))
            ->action('Visualizar Lote', url("/billing/batches/{$this->batch->id}"))
            ->line('Por favor, revise os atendimentos e confirme o recebimento.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'batch_id' => $this->batch->id,
            'reference_period_start' => $this->batch->reference_period_start,
            'reference_period_end' => $this->batch->reference_period_end,
            'items_count' => $this->batch->items_count,
            'total_amount' => $this->batch->total_amount,
            'type' => 'billing_batch_created',
            'message' => 'Novo lote de faturamento gerado'
        ];
    }
} 