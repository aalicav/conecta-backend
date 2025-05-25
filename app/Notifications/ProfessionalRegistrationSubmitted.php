<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Professional;

class ProfessionalRegistrationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    protected $professional;

    /**
     * Create a new notification instance.
     */
    public function __construct(Professional $professional)
    {
        $this->professional = $professional;
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
            ->subject('Novo Profissional Cadastrado - Análise Necessária')
            ->greeting('Olá!')
            ->line('Um novo profissional foi cadastrado e requer análise comercial.')
            ->line('Detalhes do profissional:')
            ->line("Nome: {$this->professional->name}")
            ->line("Especialidade: {$this->professional->specialty}")
            ->line("Tipo: {$this->professional->professional_type}")
            ->line("Conselho: {$this->professional->council_type} {$this->professional->council_number}")
            ->action('Ver Profissional', url("/professionals/{$this->professional->id}"))
            ->line('Por favor, analise os documentos e inicie o processo de negociação.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Novo Profissional Cadastrado',
            'message' => "O profissional {$this->professional->name} foi cadastrado e requer análise",
            'action_link' => "/professionals/{$this->professional->id}",
            'professional_id' => $this->professional->id,
            'professional_name' => $this->professional->name,
            'professional_type' => $this->professional->professional_type,
            'specialty' => $this->professional->specialty,
            'icon' => 'user-plus'
        ];
    }
} 