<?php

namespace App\Notifications;

use App\Models\Professional;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProfessionalRegistrationReviewed extends Notification implements ShouldQueue
{
    use Queueable;

    protected $professional;
    protected $approved;
    protected $rejectionReason;

    /**
     * Create a new notification instance.
     */
    public function __construct(Professional $professional, bool $approved, ?string $rejectionReason = null)
    {
        $this->professional = $professional;
        $this->approved = $approved;
        $this->rejectionReason = $rejectionReason;
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
        $mail = (new MailMessage)
            ->subject($this->approved ? 'Cadastro de Prestador Aprovado' : 'Cadastro de Prestador Reprovado')
            ->greeting('Olá!');

        if ($this->approved) {
            $mail->line('O cadastro do prestador foi aprovado com sucesso.')
                ->line('Detalhes do prestador:')
                ->line("Nome: {$this->professional->name}")
                ->line("Tipo: {$this->professional->professional_type}")
                ->line("Especialidade: {$this->professional->specialty}")
                ->line('O prestador agora está pronto para ser vinculado a contratos e ter seus procedimentos habilitados.')
                ->action('Ver Detalhes', url("/professionals/{$this->professional->id}"));
        } else {
            $mail->line('O cadastro do prestador foi reprovado.')
                ->line('Detalhes do prestador:')
                ->line("Nome: {$this->professional->name}")
                ->line("Tipo: {$this->professional->professional_type}")
                ->line('Motivo da reprovação:')
                ->line($this->rejectionReason)
                ->action('Ver Detalhes', url("/professionals/{$this->professional->id}"))
                ->line('Por favor, corrija as pendências e submeta o cadastro novamente.');
        }

        return $mail;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $status = $this->approved ? 'aprovado' : 'reprovado';
        
        return [
            'title' => "Cadastro de Prestador {$status}",
            'body' => $this->approved 
                ? "O cadastro do prestador {$this->professional->name} foi aprovado."
                : "O cadastro do prestador {$this->professional->name} foi reprovado. Motivo: {$this->rejectionReason}",
            'action_link' => "/professionals/{$this->professional->id}",
            'icon' => $this->approved ? 'check-circle' : 'x-circle',
            'professional_id' => $this->professional->id,
            'professional_name' => $this->professional->name,
            'professional_type' => $this->professional->professional_type,
            'approved' => $this->approved,
            'rejection_reason' => $this->rejectionReason,
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp(object $notifiable): array
    {
        return [
            'template' => $this->approved ? 'professional_registration_approved' : 'professional_registration_rejected',
            'params' => [
                'professional_name' => $this->professional->name,
                'professional_type' => $this->professional->professional_type,
                'rejection_reason' => $this->rejectionReason,
                'action_url' => url("/professionals/{$this->professional->id}")
            ]
        ];
    }
} 