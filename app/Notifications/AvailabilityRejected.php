<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\ProfessionalAvailability;

class AvailabilityRejected extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The professional availability that was rejected.
     *
     * @var \App\Models\ProfessionalAvailability
     */
    public $availability;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\ProfessionalAvailability  $availability
     * @return void
     */
    public function __construct(ProfessionalAvailability $availability)
    {
        $this->availability = $availability;
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
        $procedure = $solicitation->tuss;
        
        $availableDate = \Carbon\Carbon::parse($this->availability->available_date)->format('d/m/Y');
        $availableTime = $this->availability->available_time;
        
        return (new MailMessage)
            ->subject('Disponibilidade Não Selecionada')
            ->greeting("Olá {$notifiable->name}!")
            ->line("Infelizmente sua disponibilidade não foi selecionada para esta solicitação.")
            ->line("**Detalhes da Solicitação:**")
            ->line("• **Paciente:** {$patient->name}")
            ->line("• **Procedimento:** {$procedure->description} ({$procedure->code})")
            ->line("• **Sua Disponibilidade:** {$availableDate} às {$availableTime}")
            ->line("• **Plano de Saúde:** {$solicitation->healthPlan->name}")
            ->when($this->availability->notes, function ($message) {
                return $message->line("• **Suas Observações:** {$this->availability->notes}");
            })
            ->line('Não se preocupe! Continue registrando suas disponibilidades para futuras solicitações.')
            ->line('Obrigado por fazer parte da nossa rede de profissionais!')
            ->action('Ver Novas Solicitações', url("/solicitations"));
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
            'title' => 'Disponibilidade Não Selecionada',
            'body' => "Sua disponibilidade para {$patient->name} - {$procedure->description} não foi selecionada",
            'action_url' => "/solicitations",
            'action_text' => 'Ver Novas Solicitações',
            'icon' => 'calendar-x',
            'type' => 'availability_rejected',
            'data' => [
                'availability_id' => $this->availability->id,
                'solicitation_id' => $solicitation->id,
                'patient_name' => $patient->name,
                'procedure_name' => $procedure->description,
                'procedure_code' => $procedure->code,
                'available_date' => $this->availability->available_date,
                'available_time' => $this->availability->available_time,
                'health_plan_name' => $solicitation->healthPlan->name,
            ]
        ];
    }
} 