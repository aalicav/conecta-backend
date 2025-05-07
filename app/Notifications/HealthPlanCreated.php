<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\HealthPlan;

class HealthPlanCreated extends Notification implements ShouldQueue
{
    use Queueable;

    protected $healthPlan;

    /**
     * Create a new notification instance.
     */
    public function __construct(HealthPlan $healthPlan)
    {
        $this->healthPlan = $healthPlan;
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
        return (new MailMessage)
            ->subject('Novo Plano de Saúde Cadastrado')
            ->greeting('Olá!')
            ->line('Um novo plano de saúde foi cadastrado no sistema.')
            ->line('Detalhes do plano:')
            ->line('Nome: ' . $this->healthPlan->name)
            ->line('CNPJ: ' . $this->healthPlan->cnpj)
            ->line('Status: ' . ucfirst($this->healthPlan->status))
            ->action('Ver Plano', url('/health-plans/' . $this->healthPlan->id))
            ->line('Obrigado por usar nossa plataforma!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'health_plan_id' => $this->healthPlan->id,
            'health_plan_name' => $this->healthPlan->name,
            'status' => $this->healthPlan->status,
            'action' => 'created',
            'message' => 'Novo plano de saúde cadastrado: ' . $this->healthPlan->name
        ];
    }
} 