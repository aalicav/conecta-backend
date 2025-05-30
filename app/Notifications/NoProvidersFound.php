<?php

namespace App\Notifications;

use App\Models\Solicitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NoProvidersFound extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * The notification data.
     *
     * @var array
     */
    protected $data;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @param  array  $data
     * @return void
     */
    public function __construct(Solicitation $solicitation, array $data)
    {
        $this->solicitation = $solicitation;
        $this->data = $data;
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
        $tuss = $this->solicitation->tuss;
        $patient = $this->solicitation->patient;
        $healthPlan = $this->solicitation->healthPlan;

        return (new MailMessage)
            ->subject('Profissional não encontrado - Solicitação #' . $this->solicitation->id)
            ->greeting('Olá!')
            ->line('Não foi possível encontrar um profissional disponível para a seguinte solicitação:')
            ->line('Solicitação #' . $this->solicitation->id)
            ->line('Paciente: ' . $patient->name)
            ->line('Plano de Saúde: ' . $healthPlan->name)
            ->line('Procedimento: ' . $tuss->code . ' - ' . $tuss->description)
            ->action('Ver Solicitação', url('/solicitations/' . $this->solicitation->id))
            ->line('Por favor, verifique a disponibilidade de profissionais para este procedimento.')
            ->line('Esta solicitação requer sua atenção imediata.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return $this->data;
    }
} 