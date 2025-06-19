<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Appointment;
use App\Models\Professional;

class AppointmentConfirmed extends Notification
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
        
        // Add the WhatsApp channel if the notifiable has a WhatsApp delivery method
        if ($notifiable->routeNotificationFor('whatsapp')) {
            $channels[] = 'whatsapp';
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
        if (!$this->professional) {
            return (new MailMessage)
                ->subject('Consulta Confirmada')
                ->greeting('Olá!')
                ->line('Sua consulta foi confirmada!');
        }
        
        $appointmentDate = \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = \Carbon\Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        
        return (new MailMessage)
            ->subject('Consulta Confirmada')
            ->greeting('Olá!')
            ->line('Sua consulta foi confirmada!')
            ->line("Data: {$appointmentDate}")
            ->line("Horário: {$appointmentTime}")
            ->line("Profissional: {$this->professional->name}")
            ->line('Chegue com 15 minutos de antecedência e, caso precise cancelar, avise-nos o quanto antes.')
            ->action('Ver Detalhes', url('/appointments/view/' . $this->appointment->id));
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
        
        $message = "Sua consulta agendada para {$appointmentDate} às {$appointmentTime}";
        if ($this->professional) {
            $message .= " com {$this->professional->name}";
        }
        $message .= " foi confirmada.";
        
        return [
            'appointment_id' => $this->appointment->id,
            'type' => 'appointment_confirmed',
            'title' => 'Consulta Confirmada',
            'message' => $message,
            'confirmed_at' => now(),
            'detail_url' => '/appointments/view/' . $this->appointment->id
        ];
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
            return null;
        }
        
        $appointmentDate = \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = \Carbon\Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        $professionalName = $this->professional ? $this->professional->name : 'Profissional não especificado';
        
        $message = new \App\Notifications\Messages\WhatsAppMessage();
        $message->to(trim($notifiable->phone));
        $message->templateName = 'appointment_confirmed';
        $message->variables = [
            '1' => $notifiable->name,
            '2' => $appointmentDate,
            '3' => $appointmentTime,
            '4' => $professionalName,
            '5' => (string) $this->appointment->id
        ];
        
        return $message;
    }
} 