<?php

namespace App\Notifications;

use App\Models\Professional;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
        $channels = ['database'];
        
        if ($notifiable->notificationChannelEnabled('email')) {
            $channels[] = 'mail';
        }
        
        if ($notifiable->notificationChannelEnabled('whatsapp')) {
            $channels[] = WhatsAppChannel::class;
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Novo Cadastro de Prestador para Validação')
            ->greeting('Olá!')
            ->line('Um novo cadastro de prestador foi submetido e está aguardando validação.')
            ->line('Detalhes do prestador:')
            ->line("Nome: {$this->professional->name}")
            ->line("Tipo: {$this->professional->professional_type}")
            ->line("Especialidade: {$this->professional->specialty}")
            ->line("Conselho: {$this->professional->council_type} {$this->professional->council_number}")
            ->action('Validar Cadastro', url("/professionals/{$this->professional->id}"))
            ->line('Por favor, revise os dados e documentos submetidos para validação.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Novo Cadastro de Prestador',
            'body' => "O prestador {$this->professional->name} foi cadastrado e aguarda validação.",
            'action_link' => "/professionals/{$this->professional->id}",
            'icon' => 'user-plus',
            'professional_id' => $this->professional->id,
            'professional_name' => $this->professional->name,
            'professional_type' => $this->professional->professional_type,
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template' => 'professional_registration_submitted',
            'params' => [
                'professional_name' => $this->professional->name,
                'professional_type' => $this->professional->professional_type,
                'action_url' => url("/professionals/{$this->professional->id}")
            ]
        ];
    }
} 