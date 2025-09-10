<?php

namespace App\Notifications;

use App\Models\AppointmentRescheduling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentReschedulingRequested extends Notification
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
        $newDate = $this->rescheduling->new_scheduled_date->format('d/m/Y H:i');
        $patientName = $this->rescheduling->originalAppointment->solicitation->patient->name;

        return (new MailMessage)
            ->subject('Novo Reagendamento Solicitado')
            ->greeting('Olá!')
            ->line('Um novo reagendamento foi solicitado e precisa de sua aprovação.')
            ->line("**Paciente:** {$patientName}")
            ->line("**Data original:** {$originalDate}")
            ->line("**Nova data:** {$newDate}")
            ->line("**Motivo:** {$this->rescheduling->reason_label}")
            ->line("**Descrição:** {$this->rescheduling->reason_description}")
            ->action('Ver Reagendamento', url("/appointment-reschedulings/{$this->rescheduling->id}"))
            ->line('Por favor, revise e aprove ou rejeite o reagendamento.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $originalDate = $this->rescheduling->original_scheduled_date->format('d/m/Y H:i');
        $newDate = $this->rescheduling->new_scheduled_date->format('d/m/Y H:i');
        $patientName = $this->rescheduling->originalAppointment->solicitation->patient->name;

        return [
            'type' => 'appointment_rescheduling_requested',
            'rescheduling_id' => $this->rescheduling->id,
            'rescheduling_number' => $this->rescheduling->rescheduling_number,
            'patient_name' => $patientName,
            'original_date' => $originalDate,
            'new_date' => $newDate,
            'reason' => $this->rescheduling->reason_label,
            'reason_description' => $this->rescheduling->reason_description,
            'financial_impact' => $this->rescheduling->financial_impact,
            'provider_changed' => $this->rescheduling->provider_changed,
            'requested_by' => $this->rescheduling->requestedBy->name,
            'created_at' => $this->rescheduling->created_at->toISOString(),
        ];
    }
}