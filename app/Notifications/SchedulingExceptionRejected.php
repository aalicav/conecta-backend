<?php

namespace App\Notifications;

use App\Models\SchedulingException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class SchedulingExceptionRejected extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The scheduling exception instance.
     *
     * @var \App\Models\SchedulingException
     */
    protected $exception;

    /**
     * Create a new notification instance.
     *
     * @param \App\Models\SchedulingException $exception
     * @return void
     */
    public function __construct(SchedulingException $exception)
    {
        $this->exception = $exception;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database', 'mail', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $exception = $this->exception;
        $solicitation = $exception->solicitation;
        $patient = $solicitation->patient;
        $rejecter = $exception->rejectedBy;
        
        return (new MailMessage)
            ->subject('Exceção de Agendamento Rejeitada')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('Sua solicitação de exceção de agendamento foi **rejeitada**.')
            ->line('Detalhes da Exceção:')
            ->line('- Solicitação: #' . $solicitation->id)
            ->line('- Paciente: ' . $patient->name)
            ->line('- Procedimento: ' . $solicitation->tuss->code . ' - ' . $solicitation->tuss->description)
            ->line('- Prestador Solicitado: ' . $exception->provider_name)
            ->line('- Rejeitado por: ' . $rejecter->name)
            ->line('- Data de rejeição: ' . $exception->rejected_at->format('d/m/Y H:i'))
            ->line('- Motivo da rejeição: ' . $exception->rejection_reason)
            ->action('Ver Detalhes', url('/scheduling-exceptions/' . $exception->id))
            ->line('O sistema tentará realizar o agendamento automático com o prestador recomendado, ou você pode solicitar uma nova exceção com outra justificativa.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $exception = $this->exception;
        $solicitation = $exception->solicitation;
        
        return [
            'scheduling_exception_id' => $exception->id,
            'solicitation_id' => $solicitation->id,
            'patient_id' => $solicitation->patient_id,
            'patient_name' => $solicitation->patient->name,
            'provider_name' => $exception->provider_name,
            'rejected_by' => $exception->rejected_by,
            'rejecter_name' => $exception->rejectedBy->name,
            'rejected_at' => $exception->rejected_at->toIso8601String(),
            'rejection_reason' => $exception->rejection_reason,
            'url' => '/scheduling-exceptions/' . $exception->id,
            'type' => 'scheduling_exception_rejected'
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage([
            'data' => $this->toArray($notifiable),
            'created_at' => now(),
            'read_at' => null
        ]);
    }
}
