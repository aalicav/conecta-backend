<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\HealthPlan;
use App\Models\BillingBatch;

class BillingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $type;
    protected $healthPlan;
    protected $billingBatch;
    protected $daysUntilDue;
    protected $amount;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        string $type,
        HealthPlan $healthPlan,
        BillingBatch $billingBatch = null,
        int $daysUntilDue = null,
        float $amount = null
    ) {
        $this->type = $type;
        $this->healthPlan = $healthPlan;
        $this->billingBatch = $billingBatch;
        $this->daysUntilDue = $daysUntilDue;
        $this->amount = $amount;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $message = new MailMessage;
        
        switch ($this->type) {
            case 'billing_generated':
                $message->subject('Nova Fatura Gerada - ' . $this->healthPlan->name)
                    ->greeting('Olá!')
                    ->line('Uma nova fatura foi gerada para ' . $this->healthPlan->name)
                    ->line('Valor: R$ ' . number_format($this->amount, 2, ',', '.'))
                    ->line('Vencimento: ' . $this->billingBatch->due_date->format('d/m/Y'))
                    ->action('Ver Fatura', url('/billing/' . $this->billingBatch->id));
                break;

            case 'payment_due':
                $message->subject('Lembrete de Vencimento - ' . $this->healthPlan->name)
                    ->greeting('Olá!')
                    ->line('A fatura de ' . $this->healthPlan->name . ' vencerá em ' . $this->daysUntilDue . ' dias')
                    ->line('Valor: R$ ' . number_format($this->amount, 2, ',', '.'))
                    ->line('Vencimento: ' . $this->billingBatch->due_date->format('d/m/Y'))
                    ->action('Ver Fatura', url('/billing/' . $this->billingBatch->id));
                break;

            case 'payment_late':
                $message->subject('Fatura Vencida - ' . $this->healthPlan->name)
                    ->greeting('Olá!')
                    ->line('A fatura de ' . $this->healthPlan->name . ' está vencida')
                    ->line('Valor: R$ ' . number_format($this->amount, 2, ',', '.'))
                    ->line('Vencimento: ' . $this->billingBatch->due_date->format('d/m/Y'))
                    ->line('Por favor, regularize o pagamento o mais breve possível para evitar multas adicionais.')
                    ->action('Ver Fatura', url('/billing/' . $this->billingBatch->id));
                break;

            case 'early_payment_discount':
                $message->subject('Desconto Disponível - ' . $this->healthPlan->name)
                    ->greeting('Olá!')
                    ->line('Você tem um desconto disponível para pagamento antecipado da fatura de ' . $this->healthPlan->name)
                    ->line('Valor Original: R$ ' . number_format($this->amount, 2, ',', '.'))
                    ->line('Vencimento: ' . $this->billingBatch->due_date->format('d/m/Y'))
                    ->action('Ver Fatura', url('/billing/' . $this->billingBatch->id));
                break;
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => $this->type,
            'health_plan_id' => $this->healthPlan->id,
            'health_plan_name' => $this->healthPlan->name,
            'billing_batch_id' => $this->billingBatch?->id,
            'amount' => $this->amount,
            'days_until_due' => $this->daysUntilDue,
            'due_date' => $this->billingBatch?->due_date,
        ];
    }
} 