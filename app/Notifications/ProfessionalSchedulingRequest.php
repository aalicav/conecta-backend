<?php

namespace App\Notifications;

use App\Models\Solicitation;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProfessionalSchedulingRequest extends Notification implements ShouldQueue
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
        return [WhatsAppChannel::class];
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toWhatsApp($notifiable)
    {
        $patient = $this->solicitation->patient;
        $procedure = $this->solicitation->tuss;
        
        return [
            'template' => 'scheduling_request',
            'variables' => [
                '1' => $notifiable->name, // Professional name
                '2' => $patient->name,
                '3' => $procedure->name,
                '4' => $this->solicitation->preferred_date_start->format('d/m/Y'),
                '5' => $this->solicitation->preferred_date_end->format('d/m/Y'),
                '6' => $this->solicitation->id
            ]
        ];
    }
} 