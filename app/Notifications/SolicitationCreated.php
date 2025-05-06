<?php

namespace App\Notifications;

use App\Models\Solicitation;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SolicitationCreated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @return void
     */
    public function __construct(Solicitation $solicitation)
    {
        $this->solicitation = $solicitation;
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
        
        return (new MailMessage)
            ->subject('Nova Solicitação Criada')
            ->greeting('Olá ' . $notifiable->name . ',')
            ->line('Uma nova solicitação foi criada para o paciente ' . $patient->name . '.')
            ->line('Procedimento: ' . $tuss->code . ' - ' . $tuss->description)
            ->line('Prioridade: ' . ucfirst($this->solicitation->priority))
            ->line('Período: ' . $this->solicitation->preferred_date_start->format('d/m/Y') . ' até ' . $this->solicitation->preferred_date_end->format('d/m/Y'))
            ->action('Ver Detalhes', url('/solicitations/' . $this->solicitation->id))
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
        $patient = $this->solicitation->patient;
        $tuss = $this->solicitation->tuss;
        
        return "Nova solicitação criada para o paciente {$patient->name}.\n" .
               "Procedimento: {$tuss->code} - {$tuss->description}\n" .
               "Prioridade: " . ucfirst($this->solicitation->priority) . "\n" .
               "Período: " . $this->solicitation->preferred_date_start->format('d/m/Y') . 
               " até " . $this->solicitation->preferred_date_end->format('d/m/Y');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $patient = $this->solicitation->patient;
        $tuss = $this->solicitation->tuss;
        
        return [
            'title' => 'Nova Solicitação Criada',
            'icon' => 'file-medical',
            'type' => 'solicitation_created',
            'solicitation_id' => $this->solicitation->id,
            'patient_id' => $patient->id,
            'patient_name' => $patient->name,
            'tuss_code' => $tuss->code,
            'tuss_description' => $tuss->description,
            'priority' => $this->solicitation->priority,
            'message' => "Nova solicitação criada para {$patient->name}",
            'link' => "/solicitations/{$this->solicitation->id}"
        ];
    }
} 