<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Negotiation;

class NegotiationPartiallyCompleted extends Notification
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
        $totalItems = $this->negotiation->items()->count();
        $approvedItems = $this->negotiation->items()->where('status', 'approved')->count();
        $rejectedItems = $this->negotiation->items()->where('status', 'rejected')->count();
        $totalApproved = $this->negotiation->items()->where('status', 'approved')->sum('approved_value');
        $formattedTotal = 'R$ ' . number_format($totalApproved, 2, ',', '.');
        
        return (new MailMessage)
                    ->subject('Negociação Parcialmente Concluída')
                    ->line('A negociação "' . $this->negotiation->title . '" foi parcialmente concluída.')
                    ->line("{$approvedItems} de {$totalItems} itens foram aprovados, com valor total de {$formattedTotal}.")
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
        $totalItems = $this->negotiation->items()->count();
        $approvedItems = $this->negotiation->items()->where('status', 'approved')->count();
        $rejectedItems = $this->negotiation->items()->where('status', 'rejected')->count();
        $totalApproved = $this->negotiation->items()->where('status', 'approved')->sum('approved_value');
        
        return [
            'title' => 'Negociação Parcialmente Concluída',
            'body' => "A negociação '{$this->negotiation->title}' foi parcialmente concluída. {$approvedItems} de {$totalItems} itens foram aprovados.",
            'type' => 'negotiation_partially_completed',
            'negotiation_id' => $this->negotiation->id,
            'total_items' => $totalItems,
            'approved_items' => $approvedItems,
            'rejected_items' => $rejectedItems,
            'total_approved_value' => $totalApproved,
        ];
    }
} 