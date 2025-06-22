<?php

namespace App\Notifications;

use App\Models\Solicitation;
use App\Notifications\Channels\WhatsAppChannel;
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
        $procedure = $this->solicitation->procedure;

        return (new MailMessage)
            ->subject('Nenhum Profissional Encontrado para Solicitação')
            ->line("Não foi possível encontrar profissionais disponíveis para a solicitação #{$this->solicitation->id}.")
            ->line("Paciente: {$patient->name}")
            ->line("Procedimento: {$procedure->name}")
            ->action('Ver Solicitação', url("/solicitations/{$this->solicitation->id}"))
            ->line('Esta solicitação requer atenção imediata.');
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
            'title' => 'Nenhum Profissional Encontrado',
            'body' => "Não foi possível encontrar profissionais disponíveis para a solicitação #{$this->solicitation->id}.",
            'action_link' => "/solicitations/{$this->solicitation->id}",
            'icon' => 'alert-triangle',
            'priority' => 'high',
            'solicitation_id' => $this->solicitation->id
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable)
    {
        $patient = $this->solicitation->patient;
        $procedure = $this->solicitation->procedure;

        return [
            'template_name' => 'no_providers_found',
            'language' => [
                'code' => 'pt_BR'
            ],
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        [
                            'type' => 'text',
                            'text' => $this->solicitation->id
                        ],
                        [
                            'type' => 'text',
                            'text' => $patient->name
                        ],
                        [
                            'type' => 'text',
                            'text' => $procedure->name
                        ]
                    ]
                ]
            ]
        ];
    }
} 