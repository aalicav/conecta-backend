<?php

namespace App\Notifications;

use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class NoProvidersFound extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The solicitation data.
     *
     * @var array
     */
    protected $solicitationData;

    /**
     * The notification data.
     *
     * @var array
     */
    protected $data;

    /**
     * Create a new notification instance.
     *
     * @param  array  $solicitationData
     * @param  array  $data
     * @return void
     */
    public function __construct(array $solicitationData, array $data)
    {
        $this->solicitationData = $solicitationData;
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
            ->subject('Nenhum Profissional Encontrado para Solicitação')
            ->line("Não foi possível encontrar profissionais disponíveis para a solicitação #{$this->solicitationData['id']}.")
            ->line("Paciente: {$this->solicitationData['patient_name']}")
            ->line("Procedimento: {$this->solicitationData['tuss_code']} - {$this->solicitationData['tuss_description']}")
            ->action('Ver Solicitação', url("/solicitations/{$this->solicitationData['id']}"))
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
            'body' => "Não foi possível encontrar profissionais disponíveis para a solicitação #{$this->solicitationData['id']}.",
            'action_link' => "/solicitations/{$this->solicitationData['id']}",
            'icon' => 'alert-triangle',
            'priority' => 'high',
            'solicitation_id' => $this->solicitationData['id'],
            'patient_name' => $this->solicitationData['patient_name'],
            'procedure_code' => $this->solicitationData['tuss_code'],
            'procedure_description' => $this->solicitationData['tuss_description']
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable)
    {
        if (empty($notifiable->whatsapp)) {
            return null;
        }

        $patient = $this->solicitationData['patient'];
        $tuss = $this->solicitationData['tuss'];

        if (!$patient || !$tuss) {
            Log::warning("Missing patient or TUSS data for solicitation #{$this->solicitationData['id']}", [
                'has_patient' => !is_null($patient),
                'has_tuss' => !is_null($tuss)
            ]);
            return null;
        }

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
                            'text' => $this->solicitationData['id']
                        ],
                        [
                            'type' => 'text',
                            'text' => $patient['name']
                        ],
                        [
                            'type' => 'text',
                            'text' => "{$tuss['code']} - {$tuss['description']}"
                        ]
                    ]
                ]
            ]
        ];
    }
} 