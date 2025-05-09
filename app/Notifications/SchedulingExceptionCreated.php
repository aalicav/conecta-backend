<?php

namespace App\Notifications;

use App\Models\SchedulingException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class SchedulingExceptionCreated extends Notification implements ShouldQueue
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
        $requester = $exception->requestedBy;
        
        return (new MailMessage)
            ->subject('Nova Exceção de Agendamento - Requer Aprovação')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('Uma nova exceção de agendamento foi solicitada e requer sua aprovação.')
            ->line('Detalhes da Exceção:')
            ->line('- Solicitação: #' . $solicitation->id)
            ->line('- Paciente: ' . $patient->name)
            ->line('- Procedimento: ' . $solicitation->tuss->code . ' - ' . $solicitation->tuss->description)
            ->line('- Prestador Solicitado: ' . $exception->provider_name)
            ->line('- Preço do Prestador: R$ ' . number_format($exception->provider_price, 2, ',', '.'))
            ->line('- Preço Recomendado: R$ ' . ($exception->recommended_provider_price ? number_format($exception->recommended_provider_price, 2, ',', '.') : 'N/A'))
            ->line('- Solicitado por: ' . $requester->name)
            ->line('- Justificativa: ' . $exception->justification)
            ->action('Revisar Exceção', url('/scheduling-exceptions/' . $exception->id))
            ->line('Esta exceção precisa ser aprovada para que o agendamento prossiga com o prestador solicitado.');
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
            'provider_price' => $exception->provider_price,
            'recommended_price' => $exception->recommended_provider_price,
            'justification' => $exception->justification,
            'requested_by' => $exception->requested_by,
            'requester_name' => $exception->requestedBy->name,
            'url' => '/scheduling-exceptions/' . $exception->id,
            'type' => 'scheduling_exception_created'
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
