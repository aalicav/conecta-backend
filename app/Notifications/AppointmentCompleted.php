<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Appointment;
use App\Models\Professional;

class AppointmentCompleted extends Notification
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
            $channels[] = \App\Notifications\Channels\WhatsAppChannel::class;
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
                ->subject('Consulta Concluída')
                ->greeting('Olá!')
                ->line('Sua consulta foi concluída com sucesso.')
                ->action('Avaliar Atendimento', url('/appointments/' . $this->appointment->id . '/feedback'))
                ->line('Obrigado por utilizar nossos serviços!');
        }
        
        $appointmentDate = \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        
        return (new MailMessage)
            ->subject('Consulta Concluída')
            ->greeting('Olá!')
            ->line('Sua consulta foi concluída com sucesso.')
            ->line("Data: {$appointmentDate}")
            ->line("Profissional: {$this->professional->name}")
            ->line('Gostaríamos de saber a sua opinião sobre o atendimento.')
            ->action('Avaliar Atendimento', url('/appointments/' . $this->appointment->id . '/feedback'))
            ->line('Obrigado por utilizar nossos serviços!');
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
        
        $message = "Sua consulta de {$appointmentDate}";
        if ($this->professional) {
            $message .= " com {$this->professional->name}";
        }
        $message .= " foi concluída.";
        
        return [
            'appointment_id' => $this->appointment->id,
            'type' => 'appointment_completed',
            'title' => 'Consulta Concluída',
            'message' => $message,
            'completed_at' => $this->appointment->completed_at,
            'feedback_url' => '/appointments/' . $this->appointment->id . '/feedback'
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
        
        // We're sending a survey after completion
        if (!$this->professional || !$this->appointment->solicitation->patient) {
            return null;
        }
        
        $message = new \App\Notifications\Messages\WhatsAppMessage();
        $message->to(trim($notifiable->phone));
        $message->templateName = 'nps_survey';
        $message->variables = [
            '1' => $notifiable->name ?? $this->appointment->solicitation->patient->name,
            '2' => \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y'),
            '3' => $this->professional->name,
            '4' => $this->professional->specialty ?? '',
            '5' => (string) $this->appointment->id
        ];
        
        return $message;
    }
} 