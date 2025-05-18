<?php

namespace App\Notifications;

use App\Models\Negotiation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class NegotiationApproved extends Notification
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
     * @param \App\Models\Negotiation $negotiation
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
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Negociação Aprovada')
            ->line("A negociação '{$this->negotiation->title}' foi totalmente aprovada.")
            ->action('Ver Negociação', url("/negotiations/{$this->negotiation->id}"))
            ->line('Obrigado por utilizar nossa plataforma!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        // Calculate financial stats for the negotiation
        $totalProposed = $this->negotiation->items->sum('proposed_value');
        $totalApproved = $this->negotiation->items->sum('approved_value');
        
        return [
            'id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'entity_type' => $this->negotiation->negotiable_type,
            'entity_id' => $this->negotiation->negotiable_id,
            'entity_name' => $this->negotiation->negotiable->name ?? 'Entidade',
            'approved_by' => Auth::user() ? Auth::user()->name : 'Sistema',
            'total_proposed' => $totalProposed,
            'total_approved' => $totalApproved,
            'url' => "/negotiations/{$this->negotiation->id}",
            'type' => 'negotiation_approved',
            'created_at' => now()->toIso8601String(),
        ];
    }
} 