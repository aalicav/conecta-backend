<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use App\Models\Solicitation;

class SolicitationUpdated extends Notification
{
    use \Illuminate\Notifications\Notifiable;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * The changes made to the solicitation.
     *
     * @var array
     */
    protected $changes;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @param  array  $changes
     * @return void
     */
    public function __construct(Solicitation $solicitation, array $changes = [])
    {
        $this->solicitation = $solicitation;
        $this->changes = $changes;
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
        $mailMessage = (new MailMessage)
            ->subject('Solicitação Atualizada: #' . $this->solicitation->id)
            ->greeting('Olá!')
            ->line('Uma solicitação foi atualizada no sistema.');

        // Add solicitation details
        $mailMessage->line('Detalhes da solicitação:')
            ->line('ID: ' . $this->solicitation->id)
            ->line('Status: ' . $this->solicitation->status)
            ->line('Paciente: ' . optional($this->solicitation->patient)->name)
            ->line('Plano de Saúde: ' . optional($this->solicitation->healthPlan)->name)
            ->line('Procedimento: ' . optional($this->solicitation->tuss)->description);
        
        // Add changes if available
        if (!empty($this->changes)) {
            $mailMessage->line('Alterações realizadas:');
            foreach ($this->changes as $field => $value) {
                $mailMessage->line($field . ': ' . $value);
            }
        }

        $mailMessage->action('Ver Solicitação', url('/solicitations/' . $this->solicitation->id));

        return $mailMessage;
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
            'id' => $this->solicitation->id,
            'type' => 'solicitation_updated',
            'title' => 'Solicitação Atualizada',
            'message' => 'A solicitação #' . $this->solicitation->id . ' foi atualizada.',
            'status' => $this->solicitation->status,
            'patient_name' => optional($this->solicitation->patient)->name,
            'health_plan_name' => optional($this->solicitation->healthPlan)->name,
            'tuss_code' => optional($this->solicitation->tuss)->code,
            'tuss_description' => optional($this->solicitation->tuss)->description,
            'updated_at' => now(),
            'detail_url' => '/solicitations/' . $this->solicitation->id
        ];

        // Add changes data if available
        if (!empty($this->changes)) {
            $data['changes'] = $this->changes;
        }

        return $data;
    }
} 