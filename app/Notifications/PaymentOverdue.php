<?php

namespace App\Notifications;

use App\Models\BillingBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentOverdue extends Notification implements ShouldQueue
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
            ->subject('Pagamento em Atraso - Lote de Faturamento')
            ->greeting('Olá!')
            ->line('O pagamento do lote de faturamento está em atraso.')
            ->line("Período de referência: {$period}")
            ->line("Valor pendente: R$ " . number_format((float) $this->batch->total_amount, 2, ',', '.'))
            ->line("Data de vencimento: " . $this->batch->due_date->format('d/m/Y'))
            ->line("Dias em atraso: {$this->batch->days_late}")
            ->action('Regularizar Pagamento', url("/billing/batches/{$this->batch->id}/pay"))
            ->line('Por favor, regularize o pagamento o mais breve possível para evitar possíveis penalidades.')
            ->line('Em caso de dúvidas, entre em contato com nossa equipe financeira.');
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
            'due_date' => $this->batch->due_date,
            'days_late' => $this->batch->days_late,
            'total_amount' => $this->batch->total_amount,
            'type' => 'payment_overdue',
            'message' => 'Pagamento do lote de faturamento em atraso',
            'priority' => 'high'
        ];
    }
} 