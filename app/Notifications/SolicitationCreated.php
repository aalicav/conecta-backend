<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SolicitationCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation data.
     *
     * @var array
     */
    protected $solicitationData;

    /**
     * Create a new notification instance.
     *
     * @param array $solicitationData
     * @return void
     */
    public function __construct(array $solicitationData)
    {
        $this->solicitationData = $solicitationData;
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
        return (new MailMessage)
            ->subject('Nova Solicitação Criada')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('Uma nova solicitação foi criada para o paciente ' . $this->solicitationData['patient_name'] . '.')
            ->line('Procedimento: ' . $this->solicitationData['tuss_code'] . ' - ' . $this->solicitationData['tuss_description'])
            ->line('Prioridade: ' . ucfirst($this->solicitationData['priority']))
            ->line('Período: ' . $this->solicitationData['preferred_date_start'] . ' até ' . $this->solicitationData['preferred_date_end'])
            ->action('Ver Detalhes', url('/solicitations/' . $this->solicitationData['id']))
            ->line('Obrigado por usar nossa plataforma!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'title' => 'Nova Solicitação Criada',
            'icon' => 'file-medical',
            'type' => 'solicitation_created',
            'solicitation_id' => $this->solicitationData['id'],
            'patient_id' => $this->solicitationData['patient_id'],
            'patient_name' => $this->solicitationData['patient_name'],
            'tuss_code' => $this->solicitationData['tuss_code'],
            'tuss_description' => $this->solicitationData['tuss_description'],
            'priority' => $this->solicitationData['priority'],
            'message' => "Nova solicitação criada para {$this->solicitationData['patient_name']}",
            'link' => "/solicitations/{$this->solicitationData['id']}"
        ];
    }
} 