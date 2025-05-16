<?php

namespace App\Notifications;

use App\Models\Negotiation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NegotiationSubmitted extends Notification
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
        $itemCount = $this->negotiation->items()->count();
        
        return [
            'title' => 'Negociação Submetida para Revisão',
            'body' => "A negociação '{$this->negotiation->title}' foi submetida para sua revisão, com {$itemCount} procedimentos.",
            'action_url' => "/negotiations/{$this->negotiation->id}",
            'action_text' => 'Revisar Negociação',
            'icon' => 'mdi-file-document-edit',
            'type' => 'negotiation_submitted',
            'negotiation_id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'item_count' => $itemCount,
            'submitted_by' => $this->negotiation->creator->name,
        ];
    }
} 