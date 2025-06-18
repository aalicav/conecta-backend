<?php

namespace App\Notifications;

use App\Models\Solicitation;
use App\Models\SolicitationInvite;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppTemplateBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class SolicitationInviteCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * The invite instance.
     *
     * @var \App\Models\SolicitationInvite
     */
    protected $invite;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @param  \App\Models\SolicitationInvite  $invite
     * @return void
     */
    public function __construct(Solicitation $solicitation, SolicitationInvite $invite)
    {
        $this->solicitation = $solicitation;
        $this->invite = $invite;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
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
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $procedure = $this->solicitation->procedure;
        $patient = $this->solicitation->patient;
        
        return (new MailMessage)
            ->subject('Nova Solicitação de Agendamento')
            ->greeting('Olá ' . $notifiable->name . '!')
            ->line('Você recebeu uma nova solicitação de agendamento.')
            ->line("Procedimento: {$procedure->name}")
            ->line("Paciente: {$patient->name}")
            ->line("Data Preferencial: " . $this->solicitation->preferred_date->format('d/m/Y'))
            ->action('Ver Solicitação', url("/solicitations/{$this->solicitation->id}"))
            ->line('Por favor, acesse o sistema para responder a esta solicitação.');
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \App\Notifications\Messages\WhatsAppMessage|null
     */
    public function toWhatsApp($notifiable)
    {
        // Check if notifiable has a phone number
        if (!$notifiable || !$notifiable->phone || empty(trim($notifiable->phone))) {
            Log::warning('Cannot send WhatsApp notification: no phone number available', [
                'notifiable_id' => $notifiable->id ?? 'unknown',
                'notifiable_type' => get_class($notifiable),
                'phone' => $notifiable->phone ?? 'null'
            ]);
            return null;
        }
        
        $procedure = $this->solicitation->procedure;
        $patient = $this->solicitation->patient;
        $provider = $this->invite->provider;
        
        $message = new WhatsAppMessage();
        $message->to(trim($notifiable->phone));
        $message->templateName = 'solicitation_invite';
        $message->variables = [
            '1' => $provider->user->name,
            '2' => $procedure->description,
            '3' => $patient->user->name,
            '4' => $this->solicitation->preferred_date_start,
            '5' => $this->solicitation->id
        ];
        
        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $procedure = $this->solicitation->procedure;
        $patient = $this->solicitation->patient;
        
        return [
            'title' => 'Nova Solicitação de Agendamento',
            'body' => "Você recebeu uma nova solicitação de agendamento para {$procedure->name}",
            'action_link' => "/solicitations/{$this->solicitation->id}",
            'action_text' => 'Ver Solicitação',
            'icon' => 'calendar',
            'type' => 'solicitation_invite',
            'data' => [
                'solicitation_id' => $this->solicitation->id,
                'invite_id' => $this->invite->id,
                'procedure_name' => $procedure->name,
                'patient_name' => $patient->name,
                'preferred_date' => $this->solicitation->preferred_date->format('Y-m-d')
            ]
        ];
    }
} 