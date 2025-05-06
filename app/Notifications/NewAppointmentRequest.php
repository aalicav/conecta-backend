<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Solicitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewAppointmentRequest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The appointment request instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $appointmentRequest;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $appointmentRequest
     * @return void
     */
    public function __construct(Solicitation $appointmentRequest)
    {
        $this->appointmentRequest = $appointmentRequest;
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
        $preferredDates = $this->appointmentRequest->preferred_dates_formatted;
        
        return (new MailMessage)
            ->subject('Nova Solicitação de Agendamento')
            ->greeting('Olá ' . $notifiable->name . '!')
            ->line('Uma nova solicitação de agendamento foi recebida.')
            ->line('Tipo: ' . $this->appointmentRequest->type)
            ->line('Solicitante: ' . $this->appointmentRequest->requester->name)
            ->line('Datas Preferidas: ' . $preferredDates)
            ->line('Observações: ' . $this->appointmentRequest->notes)
            ->action('Ver Detalhes', url('/admin/appointment-requests/' . $this->appointmentRequest->id))
            ->line('Obrigado por utilizar nossa plataforma!');
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    public function toWhatsApp($notifiable)
    {
        $preferredDates = $this->appointmentRequest->preferred_dates_formatted;
        
        return 'Olá ' . $notifiable->name . '! Uma nova solicitação de agendamento foi recebida. ' . 
               'Tipo: ' . $this->appointmentRequest->type . '. ' .
               'Solicitante: ' . $this->appointmentRequest->requester->name . '. ' .
               'Datas Preferidas: ' . $preferredDates . '. ' .
               'Para mais detalhes, acesse o sistema.';
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'request_id' => $this->appointmentRequest->id,
            'requester_id' => $this->appointmentRequest->requester_id,
            'requester_name' => $this->appointmentRequest->requester->name,
            'type' => $this->appointmentRequest->type,
            'message' => 'Nova solicitação de agendamento recebida',
            'action_url' => '/admin/appointment-requests/' . $this->appointmentRequest->id,
        ];
    }
} 