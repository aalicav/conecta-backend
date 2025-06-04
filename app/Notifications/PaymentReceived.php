<?php

namespace App\Notifications;

use App\Models\BillingBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentReceived extends Notification implements ShouldQueue
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
            ->subject('Pagamento Recebido - Lote de Faturamento')
            ->greeting('Olá!')
            ->line('O pagamento do lote de faturamento foi recebido com sucesso.')
            ->line("Período de referência: {$period}")
            ->line("Valor recebido: R$ " . number_format($this->batch->total_amount, 2, ',', '.'))
            ->line("Data do pagamento: " . $this->batch->payment_received_at->format('d/m/Y'))
            ->line("Método de pagamento: {$this->batch->payment_method}")
            ->line("Referência do pagamento: {$this->batch->payment_reference}")
            ->action('Visualizar Detalhes', url("/billing/batches/{$this->batch->id}"))
            ->line('O comprovante de pagamento está disponível para download no portal.');
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
            'payment_received_at' => $this->batch->payment_received_at,
            'payment_method' => $this->batch->payment_method,
            'payment_reference' => $this->batch->payment_reference,
            'total_amount' => $this->batch->total_amount,
            'type' => 'payment_received',
            'message' => 'Pagamento do lote de faturamento recebido'
        ];
    }
} 