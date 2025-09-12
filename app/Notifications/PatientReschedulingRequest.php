<?php

namespace App\Notifications;

use App\Models\AppointmentRescheduling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PatientReschedulingRequest extends Notification implements ShouldQueue
{
    use Queueable;

    protected $rescheduling;

    /**
     * Create a new notification instance.
     */
    public function __construct(AppointmentRescheduling $rescheduling)
    {
        $this->rescheduling = $rescheduling;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $originalDate = $this->rescheduling->original_scheduled_date->format('d/m/Y H:i');
        $patientName = $this->rescheduling->originalAppointment->solicitation->patient->name;
        $patientPhone = $this->rescheduling->originalAppointment->solicitation->patient->phone ?? 'Não informado';

        $appointmentCreator = $this->rescheduling->originalAppointment->createdBy;
        $creatorInfo = $appointmentCreator ? $appointmentCreator->name . ' (' . $appointmentCreator->email . ')' : 'Sistema';

        $message = (new MailMessage)
            ->subject('Paciente Solicitou Reagendamento')
            ->greeting('Olá!')
            ->line('Um paciente solicitou reagendamento e precisa de atenção.')
            ->line("**Paciente:** {$patientName}")
            ->line("**Telefone:** {$patientPhone}")
            ->line("**Data atual:** {$originalDate}")
            ->line("**Procedimento:** {$this->rescheduling->originalAppointment->solicitation->tuss->description}")
            ->line("**Profissional atual:** {$this->rescheduling->originalAppointment->provider->name}")
            ->line("**Agendamento criado por:** {$creatorInfo}")
            ->line("**Observação:** {$this->rescheduling->reason_description}")
            ->action('Gerenciar Reagendamento', url("/appointment-reschedulings/{$this->rescheduling->id}"))
            ->line('O paciente aguarda uma nova opção de agendamento.');

        // Add context about the request method
        if ($this->rescheduling->reason === 'patient_request') {
            $message->line('Esta solicitação foi feita pelo paciente via WhatsApp.');
        }

        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $originalDate = $this->rescheduling->original_scheduled_date->format('d/m/Y H:i');
        $patientName = $this->rescheduling->originalAppointment->solicitation->patient->name;
        $patientPhone = $this->rescheduling->originalAppointment->solicitation->patient->phone ?? 'Não informado';

        $appointmentCreator = $this->rescheduling->originalAppointment->createdBy;
        $creatorInfo = $appointmentCreator ? [
            'id' => $appointmentCreator->id,
            'name' => $appointmentCreator->name,
            'email' => $appointmentCreator->email
        ] : null;

        return [
            'type' => 'patient_rescheduling_request',
            'rescheduling_id' => $this->rescheduling->id,
            'rescheduling_number' => $this->rescheduling->rescheduling_number,
            'patient_name' => $patientName,
            'patient_phone' => $patientPhone,
            'original_date' => $originalDate,
            'procedure' => $this->rescheduling->originalAppointment->solicitation->tuss->description,
            'current_provider' => $this->rescheduling->originalAppointment->provider->name,
            'appointment_creator' => $creatorInfo,
            'reason_description' => $this->rescheduling->reason_description,
            'requested_via' => $this->rescheduling->reason === 'patient_request' ? 'WhatsApp' : 'Sistema',
            'urgent' => true, // Mark as urgent since patient is waiting
            'created_at' => $this->rescheduling->created_at->toISOString(),
        ];
    }
}
