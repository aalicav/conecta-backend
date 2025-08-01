<?php

namespace App\Notifications;

use App\Models\Solicitation;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SchedulingFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * The failure reason.
     *
     * @var string
     */
    protected $reason;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @param  string  $reason
     * @return void
     */
    public function __construct(Solicitation $solicitation, string $reason)
    {
        $this->solicitation = $solicitation;
        $this->reason = $reason;
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
        $patient = $this->solicitation->patient;
        $tuss = $this->solicitation->tuss;
        $healthPlan = $this->solicitation->healthPlan;
        $requestedBy = $this->solicitation->requestedBy;

        $message = (new MailMessage)
            ->subject('[URGENTE] Falha no Agendamento Automático - Solicitação #' . $this->solicitation->id)
            ->greeting('Olá ' . $notifiable->name . '!')
            ->line('Houve uma falha no processo de agendamento automático que requer sua atenção.')
            ->line('**Detalhes da Solicitação:**')
            ->line("- **Número da Solicitação:** #{$this->solicitation->id}");

        if ($patient) {
            $message->line("- **Paciente:** {$patient->name}");
        }

        if ($healthPlan) {
            $message->line("- **Plano de Saúde:** {$healthPlan->name}");
        }

        if ($tuss) {
            $message->line("- **Especialidade/Procedimento:** {$tuss->code} - {$tuss->description}");
        }

        if ($requestedBy) {
            $message->line("- **Solicitado por:** {$requestedBy->name}");
        }

        $message->line("- **Data da Solicitação:** " . $this->solicitation->created_at->format('d/m/Y H:i'))
            ->line("\n**Motivo da Falha:**")
            ->line($this->reason)
            ->line("\n**Ações Necessárias:**")
            ->line('1. Verificar a disponibilidade de prestadores para esta especialidade')
            ->line('2. Realizar o agendamento manualmente ou')
            ->line('3. Tentar o agendamento automático novamente')
            ->action('Ver Solicitação', url("/solicitations/{$this->solicitation->id}"))
            ->line('Por favor, tome as providências necessárias para garantir o atendimento do paciente.');

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
        $data = [
            'solicitation_id' => $this->solicitation->id,
            'failure_reason' => $this->reason,
            'type' => 'scheduling_failed'
        ];

        if ($this->solicitation->patient) {
            $data['patient_name'] = $this->solicitation->patient->name;
        }

        if ($this->solicitation->healthPlan) {
            $data['health_plan_name'] = $this->solicitation->healthPlan->name;
        }

        if ($this->solicitation->tuss) {
            $data['tuss_code'] = $this->solicitation->tuss->code;
            $data['tuss_description'] = $this->solicitation->tuss->description;
        }

        return $data;
    }
} 