<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Negotiation;

class NewNegotiationCycle extends Notification
{
    use Queueable;

    /**
     * The negotiation instance.
     *
     * @var \App\Models\Negotiation
     */
    protected $negotiation;

    /**
     * The previous cycle status.
     *
     * @var string
     */
    protected $previousStatus;

    /**
     * Create a new notification instance.
     *
     * @param \App\Models\Negotiation $negotiation
     * @param string $previousStatus
     * @return void
     */
    public function __construct(Negotiation $negotiation, string $previousStatus)
    {
        $this->negotiation = $negotiation;
        $this->previousStatus = $previousStatus;
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
        $pendingItemsCount = $this->negotiation->items()->where('status', 'pending')->count();
        $totalItemsCount = $this->negotiation->items()->count();
        
        return (new MailMessage)
                    ->subject('Novo Ciclo de Negociação Iniciado')
                    ->line('Um novo ciclo (#' . $this->negotiation->negotiation_cycle . ') foi iniciado para a negociação "' . $this->negotiation->title . '".')
                    ->line('Existem ' . $pendingItemsCount . ' de ' . $totalItemsCount . ' itens pendentes para revisão.')
                    ->action('Ver Detalhes', url("/negotiations/{$this->negotiation->id}"));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $pendingItemsCount = $this->negotiation->items()->where('status', 'pending')->count();
        $totalItemsCount = $this->negotiation->items()->count();
        
        return [
            'title' => 'Novo Ciclo de Negociação Iniciado',
            'body' => "Um novo ciclo (#{$this->negotiation->negotiation_cycle}) foi iniciado para a negociação '{$this->negotiation->title}'.",
            'type' => 'new_negotiation_cycle',
            'negotiation_id' => $this->negotiation->id,
            'cycle_number' => $this->negotiation->negotiation_cycle,
            'previous_status' => $this->previousStatus,
            'pending_items' => $pendingItemsCount,
            'total_items' => $totalItemsCount
        ];
    }
} 