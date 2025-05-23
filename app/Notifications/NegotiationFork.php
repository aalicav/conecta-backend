<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Negotiation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class NegotiationFork extends Notification
{
    use Queueable;

    /**
     * The original negotiation instance.
     *
     * @var \App\Models\Negotiation
     */
    protected $originalNegotiation;

    /**
     * The forked negotiations.
     *
     * @var \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection
     */
    protected $forkedNegotiations;

    /**
     * Create a new notification instance.
     *
     * @param \App\Models\Negotiation $originalNegotiation
     * @param \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection $forkedNegotiations
     * @return void
     */
    public function __construct(Negotiation $originalNegotiation, $forkedNegotiations)
    {
        $this->originalNegotiation = $originalNegotiation;
        $this->forkedNegotiations = $forkedNegotiations;
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
        $forkCount = $this->forkedNegotiations->count();
        $message = (new MailMessage)
                    ->subject('Negociação Bifurcada')
                    ->line('A negociação "' . $this->originalNegotiation->title . '" foi bifurcada em ' . $forkCount . ' novas negociações:');
        
        // Add links to each forked negotiation
        foreach ($this->forkedNegotiations as $fork) {
            $message->line('- ' . $fork->title);
        }
        
        $message->action('Ver Detalhes', url("/negotiations?parent_id={$this->originalNegotiation->id}"));
        
        return $message;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $forkCount = $this->forkedNegotiations->count();
        
        return [
            'title' => 'Negociação Bifurcada',
            'body' => "A negociação '{$this->originalNegotiation->title}' foi bifurcada em {$forkCount} novas negociações.",
            'type' => 'negotiation_fork',
            'original_negotiation_id' => $this->originalNegotiation->id,
            'original_title' => $this->originalNegotiation->title,
            'fork_count' => $forkCount,
            'forked_negotiation_ids' => $this->forkedNegotiations->pluck('id')->toArray()
        ];
    }
} 