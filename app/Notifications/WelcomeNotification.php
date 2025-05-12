<?php

namespace App\Notifications;

use App\Models\HealthPlan;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $user;
    protected $healthPlan;
    protected $temporaryPassword;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user, HealthPlan $healthPlan)
    {
        $this->user = $user;
        $this->healthPlan = $healthPlan;
        $this->temporaryPassword = $user->temporary_password ?? null;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->user->roles->first()->name;
        $roleName = match($role) {
            'legal_representative' => 'Representante Legal',
            'operational_representative' => 'Representante Operacional',
            default => 'Usuário'
        };

        $message = (new MailMessage)
            ->subject('Bem-vindo ao Sistema - ' . $this->healthPlan->name)
            ->greeting('Olá ' . $this->user->name)
            ->line('Você foi cadastrado como ' . $roleName . ' do plano de saúde ' . $this->healthPlan->name)
            ->line('Suas credenciais de acesso são:')
            ->line('E-mail: ' . $this->user->email)
            ->line('Senha temporária: ' . $this->temporaryPassword)
            ->line('Por favor, altere sua senha no primeiro acesso.')
            ->action('Acessar o Sistema', url('/login'))
            ->line('Se você tiver alguma dúvida, entre em contato com o suporte.');

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome',
            'user_id' => $this->user->id,
            'health_plan_id' => $this->healthPlan->id,
            'role' => $this->user->roles->first()->name,
            'created_at' => now(),
        ];
    }
} 