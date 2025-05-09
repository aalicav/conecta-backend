<?php

namespace App\Notifications;

use App\Models\SchedulingException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;

class SchedulingExceptionApproved extends Notification implements ShouldQueue
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
        $approver = $exception->approvedBy;
        
        return (new MailMessage)
            ->subject('Exceção de Agendamento Aprovada')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('Sua solicitação de exceção de agendamento foi **aprovada**.')
            ->line('Detalhes da Exceção:')
            ->line('- Solicitação: #' . $solicitation->id)
            ->line('- Paciente: ' . $patient->name)
            ->line('- Procedimento: ' . $solicitation->tuss->code . ' - ' . $solicitation->tuss->description)
            ->line('- Prestador Aprovado: ' . $exception->provider_name)
            ->line('- Preço: R$ ' . number_format($exception->provider_price, 2, ',', '.'))
            ->line('- Aprovado por: ' . $approver->name)
            ->line('- Data de aprovação: ' . $exception->approved_at->format('d/m/Y H:i'))
            ->action('Ver Detalhes', url('/scheduling-exceptions/' . $exception->id))
            ->line('O agendamento será realizado com o prestador solicitado.');
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
            'approved_by' => $exception->approved_by,
            'approver_name' => $exception->approvedBy->name,
            'approved_at' => $exception->approved_at->toIso8601String(),
            'url' => '/scheduling-exceptions/' . $exception->id,
            'type' => 'scheduling_exception_approved'
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
