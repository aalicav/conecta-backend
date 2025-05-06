<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Appointment;
use App\Models\Professional;

class AppointmentMissed extends Notification
{
    use \Illuminate\Notifications\Notifiable;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    protected $appointment;

    /**
     * The professional instance.
     *
     * @var \App\Models\Professional
     */
    protected $professional;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Appointment  $appointment
     * @return void
     */
    public function __construct(Appointment $appointment)
    {
        $this->appointment = $appointment;
        
        // Load professional from the appointment if it exists
        if ($appointment->provider_type === 'App\\Models\\Professional') {
            $this->professional = \App\Models\Professional::find($appointment->provider_id);
        } else {
            $this->professional = null;
        }
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
        
        // Check if the notifiable has email notifications enabled
        if ($notifiable->routeNotificationFor('mail')) {
            $channels[] = 'mail';
        }
        
        // Remove WhatsApp channel since we don't have a template for this notification
        
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
        if (!$this->professional) {
            return (new MailMessage)
                ->subject('Consulta Não Comparecida')
                ->greeting('Olá!')
                ->line('Notamos que você não compareceu à sua consulta agendada.')
                ->action('Reagendar Consulta', url('/appointments/schedule'));
        }
        
        $appointmentDate = \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = \Carbon\Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        
        return (new MailMessage)
            ->subject('Consulta Não Comparecida')
            ->greeting('Olá!')
            ->line('Notamos que você não compareceu à sua consulta agendada.')
            ->line("Data: {$appointmentDate}")
            ->line("Horário: {$appointmentTime}")
            ->line("Profissional: {$this->professional->name}")
            ->line('Se deseja reagendar, entre em contato conosco ou agende pelo aplicativo.')
            ->action('Reagendar Consulta', url('/appointments/schedule'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $appointmentDate = \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = \Carbon\Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        
        $message = "Você não compareceu à sua consulta agendada em {$appointmentDate} às {$appointmentTime}";
        if ($this->professional) {
            $message .= " com {$this->professional->name}";
        }
        $message .= ".";
        
        return [
            'appointment_id' => $this->appointment->id,
            'type' => 'appointment_missed',
            'title' => 'Consulta Não Comparecida',
            'message' => $message,
            'missed_at' => now(),
            'detail_url' => '/appointments/schedule'
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array|null
     */
    public function toWhatsApp($notifiable)
    {
        // No matching template in WhatsAppService for missed appointments
        return null;
    }
} 