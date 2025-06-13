<?php

namespace App\Notifications;

use App\Models\Solicitation;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

class ProfessionalSchedulingRequest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @return void
     */
    public function __construct(Solicitation $solicitation)
    {
        $this->solicitation = $solicitation;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database', WhatsAppChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $patient = $this->solicitation->patient;
        $procedure = $this->solicitation->tuss;
        
        return (new MailMessage)
            ->subject('Nova Solicitação de Agendamento')
            ->greeting('Olá ' . $notifiable->name)
            ->line('Você recebeu uma nova solicitação de agendamento.')
            ->line('Paciente: ' . $patient->name)
            ->line('Procedimento: ' . $procedure->name)
            ->line('Período Preferencial: ' . $this->solicitation->preferred_date_start->format('d/m/Y') . ' até ' . $this->solicitation->preferred_date_end->format('d/m/Y'))
            ->action('Responder Solicitação', url('/appointments/respond/' . $this->solicitation->id))
            ->line('Por favor, responda com suas datas disponíveis o mais breve possível.');
    }

    /**
     * Get the database representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\DatabaseMessage
     */
    public function toDatabase($notifiable)
    {
        $patient = $this->solicitation->patient;
        $procedure = $this->solicitation->tuss;
        
        return new DatabaseMessage([
            'title' => 'Nova Solicitação de Agendamento',
            'message' => "Você recebeu uma nova solicitação de agendamento para {$procedure->name} do paciente {$patient->name}.",
            'solicitation_id' => $this->solicitation->id,
            'type' => 'scheduling_request',
            'action_url' => '/appointments/respond/' . $this->solicitation->id
        ]);
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toWhatsApp($notifiable)
    {
        $patient = $this->solicitation->patient;
        $procedure = $this->solicitation->tuss;
        
        return [
            'template' => 'scheduling_request',
            'variables' => [
                '1' => $notifiable->name, // Professional name
                '2' => $patient->name,
                '3' => $procedure->name,
                '4' => $this->solicitation->preferred_date_start->format('d/m/Y'),
                '5' => $this->solicitation->preferred_date_end->format('d/m/Y'),
                '6' => $this->solicitation->id
            ]
        ];
    }
} 