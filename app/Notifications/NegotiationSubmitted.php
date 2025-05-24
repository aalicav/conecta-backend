<?php

namespace App\Notifications;

use App\Models\Negotiation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;

class NegotiationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The negotiation instance.
     *
     * @var \App\Models\Negotiation
     */
    public Negotiation $negotiation;

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
    public function via($notifiable): array
    {
        $channels = ['database', 'mail'];
        if ($notifiable instanceof User && $notifiable->phone) {
            $channels[] = WhatsAppChannel::class;
        }
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $actionUrl = url("/negotiations/{$this->negotiation->id}");
        $submitterName = $this->negotiation->creator->name ?? 'Alguém';

        return (new MailMessage)
            ->subject("Negociação Submetida para Análise: {$this->negotiation->title}")
            ->greeting("Olá " . ($notifiable->name ?? 'Usuário'))
            ->line("A negociação '{$this->negotiation->title}' foi submetida por {$submitterName} e requer sua análise.")
            ->line("Total de itens: " . $this->negotiation->items()->count())
            ->action('Ver Negociação', $actionUrl)
            ->line('Obrigado por usar nossa aplicação!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable): array
    {
        return [
            'negotiation_id' => $this->negotiation->id,
            'title' => $this->negotiation->title,
            'message' => "A negociação '{$this->negotiation->title}' foi submetida e aguarda sua análise.",
            'action_url' => url("/negotiations/{$this->negotiation->id}"),
            'icon' => 'file-text',
            'type' => 'negotiation_submitted',
        ];
    }

    /**
     * Get the WhatsApp representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \App\Notifications\Messages\WhatsAppMessage
     */
    public function toWhatsApp($notifiable): WhatsAppMessage
    {
        $actionUrl = url("/negotiations/{$this->negotiation->id}");
        $recipientName = $notifiable->name ?? 'Usuário';

        return (new WhatsAppMessage)
            ->template('negotiation_submitted_to_entity')
            ->to($notifiable->phone)
            ->parameters([
                '1' => $recipientName,
                '2' => $this->negotiation->title,
                '3' => $actionUrl
            ]);
    }
} 