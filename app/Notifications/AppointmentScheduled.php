<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class AppointmentScheduled extends Notification implements ShouldQueue
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
        $solicitation = $this->appointment->solicitation;
        $patient = $solicitation->patient;
        $provider = $this->appointment->provider;
        $providerName = $provider->name ?? 'Prestador não especificado';
        $providerType = class_basename($this->appointment->provider_type);
        $providerTypeText = $providerType === 'Clinic' ? 'Clínica' : 'Profissional';
        
        $mail = (new MailMessage)
            ->subject('Agendamento Realizado')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('Um agendamento foi realizado para ' . $patient->name . '.')
            ->line('Data: ' . $this->appointment->scheduled_date->format('d/m/Y H:i'))
            ->line($providerTypeText . ': ' . $providerName);
        
        if ($this->appointment->scheduled_automatically) {
            $mail->line('Este agendamento foi realizado automaticamente pelo sistema.');
        }
        
        return $mail->action('Ver Detalhes', url('/appointments/' . $this->appointment->id))
            ->line('Obrigado por usar nossa plataforma!');
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
        
        $solicitation = $this->appointment->solicitation;
        $patient = $solicitation->patient;
        $provider = $this->appointment->provider;
        $providerName = $provider->name ?? 'Prestador não especificado';
        $providerType = class_basename($this->appointment->provider_type);
        $providerTypeText = $providerType === 'Clinic' ? 'Clínica' : 'Profissional';
        
        $message = new WhatsAppMessage();
        $message->to(trim($notifiable->phone));
        $message->templateName = 'appointment_scheduled';
        $message->variables = [
            '1' => $notifiable->name,
            '2' => $patient->name,
            '3' => $this->appointment->scheduled_date->format('d/m/Y H:i'),
            '4' => $providerTypeText,
            '5' => $providerName,
            '6' => $this->appointment->scheduled_automatically ? 'Sim' : 'Não',
            '7' => (string) $this->appointment->id
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
        $solicitation = $this->appointment->solicitation;
        $patient = $solicitation->patient;
        $provider = $this->appointment->provider;
        $providerName = $provider->name ?? 'Prestador não especificado';
        $providerType = class_basename($this->appointment->provider_type);
        $providerTypeText = $providerType === 'Clinic' ? 'Clínica' : 'Profissional';
        
        return [
            'title' => 'Agendamento Realizado',
            'icon' => 'calendar-check',
            'type' => 'appointment_scheduled',
            'appointment_id' => $this->appointment->id,
            'solicitation_id' => $solicitation->id,
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'provider_name' => $providerName,
            'provider_type' => $providerTypeText,
            'scheduled_date' => $this->appointment->scheduled_date->format('Y-m-d H:i:s'),
            'auto_scheduled' => $this->appointment->scheduled_automatically ?? false,
            'message' => "Agendamento realizado para {$patient->name} em " . $this->appointment->scheduled_date->format('d/m/Y H:i'),
            'link' => "/appointments/{$this->appointment->id}"
        ];
    }
} 