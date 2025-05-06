<?php

namespace App\Notifications;

use App\Models\Appointment;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    protected $appointment;

    /**
     * The previous status.
     *
     * @var string
     */
    protected $previousStatus;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Appointment  $appointment
     * @param  string  $previousStatus
     * @return void
     */
    public function __construct(Appointment $appointment, string $previousStatus)
    {
        $this->appointment = $appointment;
        $this->previousStatus = $previousStatus;
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
        
        $statusTitle = $this->getStatusTitle($this->appointment->status);
        $statusDescription = $this->getStatusDescription($this->appointment->status);
        
        return (new MailMessage)
            ->subject('Status do Agendamento Alterado: ' . $statusTitle)
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('O status do agendamento para ' . $patient->name . ' foi alterado para: ' . $statusTitle)
            ->line($statusDescription)
            ->line('Data do agendamento: ' . $this->appointment->scheduled_date->format('d/m/Y H:i'))
            ->line('Prestador: ' . $providerName)
            ->action('Ver Detalhes', url('/appointments/' . $this->appointment->id))
            ->line('Obrigado por usar nossa plataforma!');
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return string|array
     */
    public function toWhatsApp($notifiable)
    {
        $solicitation = $this->appointment->solicitation;
        $patient = $solicitation->patient;
        $provider = $this->appointment->provider;
        $providerName = $provider->name ?? 'Prestador não especificado';
        
        $statusTitle = $this->getStatusTitle($this->appointment->status);
        
        return "Status do agendamento alterado para: {$statusTitle}\n" .
               "Paciente: {$patient->name}\n" .
               "Data: " . $this->appointment->scheduled_date->format('d/m/Y H:i') . "\n" .
               "Prestador: {$providerName}";
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
        
        $statusTitle = $this->getStatusTitle($this->appointment->status);
        $icon = $this->getStatusIcon($this->appointment->status);
        
        return [
            'title' => 'Status do Agendamento Alterado',
            'icon' => $icon,
            'type' => 'appointment_status_changed',
            'appointment_id' => $this->appointment->id,
            'solicitation_id' => $solicitation->id,
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'provider_name' => $providerName,
            'scheduled_date' => $this->appointment->scheduled_date->format('Y-m-d H:i:s'),
            'previous_status' => $this->previousStatus,
            'new_status' => $this->appointment->status,
            'message' => "Agendamento de {$patient->name} alterado para {$statusTitle}",
            'link' => "/appointments/{$this->appointment->id}"
        ];
    }

    /**
     * Get the status title.
     *
     * @param  string  $status
     * @return string
     */
    protected function getStatusTitle(string $status): string
    {
        switch ($status) {
            case Appointment::STATUS_CONFIRMED:
                return 'Confirmado';
            case Appointment::STATUS_CANCELLED:
                return 'Cancelado';
            case Appointment::STATUS_COMPLETED:
                return 'Concluído';
            case Appointment::STATUS_MISSED:
                return 'Não Compareceu';
            default:
                return 'Agendado';
        }
    }

    /**
     * Get the status description.
     *
     * @param  string  $status
     * @return string
     */
    protected function getStatusDescription(string $status): string
    {
        switch ($status) {
            case Appointment::STATUS_CONFIRMED:
                return 'A presença do paciente foi confirmada.';
            case Appointment::STATUS_CANCELLED:
                $notes = $this->appointment->notes ? "Motivo: {$this->appointment->notes}" : '';
                return "O agendamento foi cancelado. {$notes}";
            case Appointment::STATUS_COMPLETED:
                return 'O atendimento foi concluído com sucesso.';
            case Appointment::STATUS_MISSED:
                return 'O paciente não compareceu ao agendamento.';
            default:
                return 'O agendamento foi marcado.';
        }
    }

    /**
     * Get the status icon.
     *
     * @param  string  $status
     * @return string
     */
    protected function getStatusIcon(string $status): string
    {
        switch ($status) {
            case Appointment::STATUS_CONFIRMED:
                return 'check-circle';
            case Appointment::STATUS_CANCELLED:
                return 'times-circle';
            case Appointment::STATUS_COMPLETED:
                return 'calendar-check';
            case Appointment::STATUS_MISSED:
                return 'user-times';
            default:
                return 'calendar';
        }
    }
} 