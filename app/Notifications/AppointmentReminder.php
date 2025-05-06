<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Professional;
use Carbon\Carbon;

class AppointmentReminder extends Notification
{
    use \Illuminate\Notifications\Notifiable;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    protected $appointment;

    /**
     * The patient instance.
     *
     * @var \App\Models\Patient
     */
    protected $patient;

    /**
     * The professional instance.
     *
     * @var \App\Models\Professional
     */
    protected $professional;

    /**
     * The clinic address.
     *
     * @var string
     */
    protected $clinicAddress;

    /**
     * Hours remaining until the appointment.
     *
     * @var int
     */
    protected $hoursRemaining;

    /**
     * The appointment token for WhatsApp actions.
     *
     * @var string|null
     */
    protected $appointmentToken;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Appointment  $appointment
     * @param  int  $hoursRemaining
     * @return void
     */
    public function __construct(Appointment $appointment, int $hoursRemaining = 24)
    {
        $this->appointment = $appointment;
        $this->hoursRemaining = $hoursRemaining;
        
        // Load patient from the appointment
        $this->patient = $appointment->solicitation->patient ?? null;
        
        // Load professional from the appointment if it exists
        if ($appointment->provider_type === 'App\\Models\\Professional') {
            $this->professional = \App\Models\Professional::find($appointment->provider_id);
        } else {
            $this->professional = null;
        }
        
        // Get clinic address
        $this->clinicAddress = $this->getClinicAddress($appointment);
        
        // Generate token if needed for WhatsApp
        if ($hoursRemaining == 24) {
            $this->appointmentToken = null; // Will be generated if needed in toWhatsApp
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
        // and it's 24 hours before the appointment
        if ($notifiable->routeNotificationFor('whatsapp') && $this->hoursRemaining == 24) {
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
        if (!$this->professional || !$this->patient) {
            return (new MailMessage)
                ->subject('Lembrete de Consulta')
                ->greeting('Olá!')
                ->line('Este é um lembrete de sua consulta agendada.');
        }
        
        $appointmentDate = Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        
        $providerTitle = $this->professional->professional_type === 'doctor' ? 'Dr.' : 'Especialista';
        
        return (new MailMessage)
            ->subject('Lembrete de Consulta - ' . $appointmentDate . ' ' . $appointmentTime)
            ->greeting('Olá, ' . $this->patient->name . '!')
            ->line('Este é um lembrete de sua consulta agendada.')
            ->line('Data: ' . $appointmentDate)
            ->line('Horário: ' . $appointmentTime)
            ->line('Profissional: ' . $providerTitle . ' ' . $this->professional->name)
            ->line('Especialidade: ' . $this->professional->specialty)
            ->line('Endereço: ' . $this->clinicAddress)
            ->line('Por favor, chegue com 15 minutos de antecedência.')
            ->line('Caso precise reagendar ou cancelar, entre em contato o mais rápido possível.')
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
        $appointmentDate = Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        
        $message = "Lembrete: Você tem uma consulta agendada para {$appointmentDate} às {$appointmentTime}";
        if ($this->professional) {
            $message .= " com {$this->professional->name}";
        }
        $message .= ".";
        
        $data = [
            'appointment_id' => $this->appointment->id,
            'title' => 'Lembrete de Consulta',
            'message' => $message,
            'sent_at' => now(),
            'detail_url' => '/appointments/view/' . $this->appointment->id
        ];
        
        if ($this->patient) {
            $data['patient_id'] = $this->patient->id;
        }
        
        if ($this->professional) {
            $data['professional_id'] = $this->professional->id;
        }
        
        return $data;
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array|null
     */
    public function toWhatsApp($notifiable)
    {
        // Only send WhatsApp reminder if it's 24 hours before the appointment
        if ($this->hoursRemaining != 24 || !$this->professional || !$this->patient) {
            return null;
        }
        
        // Generate token for appointment actions
        $token = $this->generateAppointmentToken();
        
        $appointmentDate = Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($this->appointment->scheduled_time)->format('H:i');
        
        $providerTitle = $this->professional->professional_type === 'doctor' ? 'Dr.' : 'Especialista';
        
        // Return template format for WhatsApp channel
        return [
            'template' => 'agendamento_cliente',
            'variables' => [
                '1' => $this->patient->name,
                '2' => "{$providerTitle} {$this->professional->name}",
                '3' => $this->professional->specialty ?? '',
                '4' => $appointmentDate,
                '5' => $appointmentTime,
                '6' => $this->clinicAddress ?? config('app.clinic_address', 'Endereço da clínica'),
                '7' => $token
            ]
        ];
    }

    /**
     * Get clinic address from appointment.
     *
     * @param Appointment $appointment
     * @return string|null
     */
    protected function getClinicAddress(Appointment $appointment): ?string
    {
        if ($appointment->provider_type === 'App\\Models\\Clinic') {
            $clinic = \App\Models\Clinic::find($appointment->provider_id);
            if ($clinic) {
                return $clinic->address . ', ' . $clinic->city . ' - ' . $clinic->state;
            }
        } elseif ($appointment->provider_type === 'App\\Models\\Professional') {
            $professional = \App\Models\Professional::find($appointment->provider_id);
            if ($professional && $professional->clinic) {
                return $professional->clinic->address . ', ' . $professional->clinic->city . ' - ' . $professional->clinic->state;
            }
        }
        
        return config('app.clinic_address', null);
    }
    
    /**
     * Generate a secure appointment token
     *
     * @return string
     */
    protected function generateAppointmentToken(): string
    {
        $payload = [
            'exp' => time() + (86400 * 30), // 30 days
            'agendamento_id' => $this->appointment->id
        ];
        
        return jwt_encode($payload, config('app.key'));
    }
} 