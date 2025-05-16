<?php

namespace App\Notifications;

use App\Models\Negotiation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NegotiationPartiallyApproved extends Notification
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
        // Calcular estatísticas da negociação
        $totalItems = $this->negotiation->items()->count();
        $approvedItems = $this->negotiation->items()->where('status', 'approved')->count();
        $rejectedItems = $this->negotiation->items()->where('status', 'rejected')->count();
        
        // Calcular valores financeiros
        $totalProposed = $this->negotiation->items()->sum('proposed_value');
        $totalApproved = $this->negotiation->items()->where('status', 'approved')->sum('approved_value');
        
        $formattedProposed = 'R$ ' . number_format($totalProposed, 2, ',', '.');
        $formattedApproved = 'R$ ' . number_format($totalApproved, 2, ',', '.');
        
        // Criar mensagem detalhada
        $body = "A negociação '{$this->negotiation->title}' foi parcialmente aprovada. ";
        $body .= "De {$totalItems} itens, {$approvedItems} foram aprovados e {$rejectedItems} foram rejeitados. ";
        $body .= "Valor total proposto: {$formattedProposed}. Valor total aprovado: {$formattedApproved}.";
        
        return [
            'title' => 'Negociação Parcialmente Aprovada',
            'body' => $body,
            'action_url' => "/negotiations/{$this->negotiation->id}",
            'action_text' => 'Ver Detalhes',
            'icon' => 'mdi-check-decagram',
            'type' => 'negotiation_partially_approved',
            'negotiation_id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'total_items' => $totalItems,
            'approved_items' => $approvedItems,
            'rejected_items' => $rejectedItems,
            'total_proposed' => $totalProposed,
            'total_approved' => $totalApproved
        ];
    }
} 