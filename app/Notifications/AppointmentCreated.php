<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    protected $appointment;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Appointment  $appointment
     * @return void
     */
    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
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
        return (new MailMessage)
            ->subject('Novo Compromisso Agendado')
            ->greeting('Olá ' . $notifiable->name . '!')
            ->line('Um novo compromisso foi agendado para você.')
            ->line('Data: ' . $this->appointment->scheduled_at->format('d/m/Y'))
            ->line('Horário: ' . $this->appointment->scheduled_at->format('H:i'))
            ->line('Tipo: ' . $this->appointment->type)
            ->action('Ver Detalhes', url('/appointments/' . $this->appointment->id))
            ->line('Obrigado por usar nosso aplicativo!');
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return string
     */
    public function toWhatsApp($notifiable)
    {
        return 'Olá ' . $notifiable->name . '! Um novo compromisso foi agendado para você no dia ' . 
            $this->appointment->scheduled_at->format('d/m/Y') . ' às ' . 
            $this->appointment->scheduled_at->format('H:i') . '. ' .
            'Tipo: ' . $this->appointment->type . '. ' .
            'Para mais detalhes, acesse nosso app.';
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
            'appointment_id' => $this->appointment->id,
            'scheduled_at' => $this->appointment->scheduled_at->toIso8601String(),
            'type' => $this->appointment->type,
            'message' => 'Um novo compromisso foi agendado',
            'action_url' => '/appointments/' . $this->appointment->id,
        ];
    }
} 