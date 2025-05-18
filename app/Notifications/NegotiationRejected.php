<?php

namespace App\Notifications;

use App\Models\Negotiation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class NegotiationRejected extends Notification
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
        $levelNames = [
            'commercial' => 'Comercial',
            'financial' => 'Financeira',
            'management' => 'Gestão',
            'legal' => 'Jurídica',
            'direction' => 'Diretoria'
        ];

        $levelName = $levelNames[$this->negotiation->current_approval_level] ?? $this->negotiation->current_approval_level;

        return (new MailMessage)
            ->subject('Negociação Rejeitada')
            ->line("A negociação '{$this->negotiation->title}' foi rejeitada.")
            ->line("Nível de aprovação: {$levelName}")
            ->action('Ver Negociação', url("/negotiations/{$this->negotiation->id}"))
            ->line('Entre em contato com o responsável para mais detalhes.');
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

        $levelName = $levelNames[$this->negotiation->current_approval_level] ?? $this->negotiation->current_approval_level;

        // Find the most recent rejection in approval history
        $rejectionNotes = null;
        if ($this->negotiation->approval_history && count($this->negotiation->approval_history) > 0) {
            $rejections = collect($this->negotiation->approval_history)
                ->where('status', 'rejected')
                ->sortByDesc('created_at');
            
            if ($rejections->count() > 0) {
                $lastRejection = $rejections->first();
                $rejectionNotes = $lastRejection->notes;
            }
        }

        return [
            'id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'entity_type' => $this->negotiation->negotiable_type,
            'entity_id' => $this->negotiation->negotiable_id,
            'entity_name' => $this->negotiation->negotiable->name ?? 'Entidade',
            'rejected_by' => Auth::user() ? Auth::user()->name : 'Sistema',
            'approval_level' => $this->negotiation->current_approval_level,
            'approval_level_name' => $levelName,
            'rejection_notes' => $rejectionNotes,
            'url' => "/negotiations/{$this->negotiation->id}",
            'type' => 'negotiation_rejected',
            'created_at' => now()->toIso8601String(),
        ];
    }
} 