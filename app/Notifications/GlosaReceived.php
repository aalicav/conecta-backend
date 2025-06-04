<?php

namespace App\Notifications;

use App\Models\PaymentGloss;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class GlosaReceived extends Notification implements ShouldQueue
{
    use Queueable;

    protected $glosa;

    /**
     * Create a new notification instance.
     */
    public function __construct(PaymentGloss $glosa)
    {
        $this->glosa = $glosa;
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
        $billingItem = $this->glosa->billingItem;
        $batch = $billingItem->billingBatch;

        return (new MailMessage)
            ->subject('Nova Glosa Registrada')
            ->greeting('Olá!')
            ->line('Uma nova glosa foi registrada para um item do lote de faturamento.')
            ->line("Lote de referência: {$batch->id}")
            ->line("Tipo de glosa: {$this->glosa->glosa_type}")
            ->line("Código da glosa: {$this->glosa->glosa_code}")
            ->line("Valor original: R$ " . number_format($this->glosa->original_amount, 2, ',', '.'))
            ->line("Valor glosado: R$ " . number_format($this->glosa->amount, 2, ',', '.'))
            ->line("Motivo: {$this->glosa->description}")
            ->line("Prazo para recurso: {$this->glosa->appeal_deadline_at->format('d/m/Y')}")
            ->action('Analisar Glosa', url("/billing/glosses/{$this->glosa->id}"))
            ->line('Por favor, analise a glosa e providencie a documentação necessária caso deseje recorrer.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'glosa_id' => $this->glosa->id,
            'billing_item_id' => $this->glosa->billing_item_id,
            'glosa_type' => $this->glosa->glosa_type,
            'glosa_code' => $this->glosa->glosa_code,
            'original_amount' => $this->glosa->original_amount,
            'glosa_amount' => $this->glosa->amount,
            'appeal_deadline' => $this->glosa->appeal_deadline_at,
            'type' => 'glosa_received',
            'message' => 'Nova glosa registrada para item de faturamento',
            'priority' => 'medium'
        ];
    }
} 