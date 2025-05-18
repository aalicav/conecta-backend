<?php

namespace App\Notifications;

use App\Models\Negotiation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NegotiationApprovalRequired extends Notification
{
    use Queueable;

    /**
     * The negotiation instance.
     *
     * @var \App\Models\Negotiation
     */
    protected $negotiation;

    /**
     * The approval level required.
     *
     * @var string
     */
    protected $approvalLevel;

    /**
     * Create a new notification instance.
     *
     * @param \App\Models\Negotiation $negotiation
     * @param string $approvalLevel
     * @return void
     */
    public function __construct(Negotiation $negotiation, string $approvalLevel)
    {
        $this->negotiation = $negotiation;
        $this->approvalLevel = $approvalLevel;
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
            ->subject('Aprovação de Negociação Necessária')
            ->line("A negociação '{$this->negotiation->title}' precisa de sua aprovação.")
            ->line("Nível de aprovação: {$this->approvalLevel}")
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
        // Map approval levels to Portuguese names
        $levelNames = [
            'commercial' => 'Comercial',
            'financial' => 'Financeira',
            'management' => 'Gestão',
            'legal' => 'Jurídica',
            'direction' => 'Diretoria'
        ];

        $levelName = $levelNames[$this->approvalLevel] ?? $this->approvalLevel;

        return [
            'id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'approval_level' => $this->approvalLevel,
            'approval_level_name' => $levelName,
            'creator_name' => $this->negotiation->creator->name ?? 'Usuário',
            'entity_type' => $this->negotiation->negotiable_type,
            'entity_id' => $this->negotiation->negotiable_id,
            'entity_name' => $this->negotiation->negotiable->name ?? 'Entidade',
            'url' => "/negotiations/{$this->negotiation->id}",
            'type' => 'approval_required',
            'created_at' => now()->toIso8601String(),
        ];
    }
} 