<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Negotiation;

class NegotiationCompleted extends Notification
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
        $totalApproved = $this->negotiation->items()->where('status', 'approved')->sum('approved_value');
        $formattedTotal = 'R$ ' . number_format($totalApproved, 2, ',', '.');
        
        return (new MailMessage)
                    ->subject('Negociação Concluída com Sucesso')
                    ->line('A negociação "' . $this->negotiation->title . '" foi concluída com sucesso.')
                    ->line('Valor total aprovado: ' . $formattedTotal)
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
        $totalApproved = $this->negotiation->items()->where('status', 'approved')->sum('approved_value');
        
        return [
            'title' => 'Negociação Concluída com Sucesso',
            'body' => "A negociação '{$this->negotiation->title}' foi concluída com sucesso.",
            'type' => 'negotiation_completed',
            'negotiation_id' => $this->negotiation->id,
            'total_approved_value' => $totalApproved,
        ];
    }
} 