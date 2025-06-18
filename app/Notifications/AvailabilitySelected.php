<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\ProfessionalAvailability;
use App\Models\Appointment;

class AvailabilitySelected extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The professional availability that was selected.
     *
     * @var \App\Models\ProfessionalAvailability
     */
    public $availability;

    /**
     * The appointment that was created.
     *
     * @var \App\Models\Appointment
     */
    public $appointment;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\ProfessionalAvailability  $availability
     * @param  \App\Models\Appointment  $appointment
     * @return void
     */
    public function __construct(ProfessionalAvailability $availability, Appointment $appointment)
    {
        $this->availability = $availability;
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
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $solicitation = $this->availability->solicitation;
        $patient = $solicitation->patient;
        $provider = $this->appointment->provider;
        $procedure = $solicitation->tuss;
        
        $scheduledDate = \Carbon\Carbon::parse($this->appointment->scheduled_date)->format('d/m/Y H:i');
        
        return (new MailMessage)
            ->subject('Sua Disponibilidade Foi Selecionada - Agendamento Criado')
            ->greeting("Olá {$notifiable->name}!")
            ->line("Sua disponibilidade foi selecionada e um agendamento foi criado com sucesso.")
            ->line("**Detalhes do Agendamento:**")
            ->line("• **Paciente:** {$patient->name}")
            ->line("• **Procedimento:** {$procedure->description} ({$procedure->code})")
            ->line("• **Data/Hora:** {$scheduledDate}")
            ->line("• **Plano de Saúde:** {$solicitation->healthPlan->name}")
            ->when($this->appointment->address, function ($message) {
                $address = $this->appointment->address;
                $fullAddress = "{$address->street}, {$address->number}";
                if ($address->complement) {
                    $fullAddress .= " - {$address->complement}";
                }
                $fullAddress .= ", {$address->neighborhood}, {$address->city}/{$address->state}";
                
                return $message->line("• **Local:** {$fullAddress}");
            })
            ->when($this->availability->notes, function ($message) {
                return $message->line("• **Observações:** {$this->availability->notes}");
            })
            ->action('Ver Detalhes do Agendamento', url("/appointments/{$this->appointment->id}"))
            ->line('O agendamento está confirmado e aguardando a confirmação do paciente.')
            ->line('Obrigado por fazer parte da nossa rede de profissionais!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $solicitation = $this->availability->solicitation;
        $patient = $solicitation->patient;
        $procedure = $solicitation->tuss;
        
        return [
            'title' => 'Disponibilidade Selecionada - Agendamento Criado',
            'body' => "Sua disponibilidade foi selecionada para o paciente {$patient->name} - {$procedure->description}",
            'action_url' => "/appointments/{$this->appointment->id}",
            'action_text' => 'Ver Agendamento',
            'icon' => 'calendar-check',
            'type' => 'availability_selected',
            'data' => [
                'availability_id' => $this->availability->id,
                'appointment_id' => $this->appointment->id,
                'solicitation_id' => $solicitation->id,
                'patient_name' => $patient->name,
                'procedure_name' => $procedure->description,
                'procedure_code' => $procedure->code,
                'scheduled_date' => $this->appointment->scheduled_date,
                'health_plan_name' => $solicitation->healthPlan->name,
                'address_id' => $this->appointment->address_id,
            ]
        ];
    }
} 