<?php

namespace App\Notifications;

use App\Models\Negotiation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class NegotiationCancelled extends Notification
{
    use Queueable;

    /**
     * The negotiation instance.
     *
     * @var \App\Models\Negotiation
     */
    protected $negotiation;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function __construct(Negotiation $negotiation)
    {
        $this->negotiation = $negotiation;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['database'];
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
            'title' => 'Negociação Cancelada',
            'body' => "A negociação '{$this->negotiation->title}' foi cancelada.",
            'action_url' => "/negotiations/{$this->negotiation->id}",
            'action_text' => 'Ver Detalhes',
            'icon' => 'mdi-cancel',
            'type' => 'negotiation_cancelled',
            'negotiation_id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'cancelled_by' => Auth::user()->name,
        ];
    }
} 