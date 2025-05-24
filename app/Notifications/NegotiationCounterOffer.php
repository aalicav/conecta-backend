<?php

namespace App\Notifications;

use App\Models\NegotiationItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;

class NegotiationCounterOffer extends Notification
{
    use Queueable;

    /**
     * The negotiation item instance.
     *
     * @var \App\Models\NegotiationItem
     */
    protected $item;

    /**
     * Create a new notification instance.
     *
     * @param  \App\Models\NegotiationItem  $item
     * @return void
     */
    public function __construct(NegotiationItem $item)
    {
        $this->item = $item;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        $channels = ['database'];
        if ($notifiable instanceof User && $notifiable->phone) {
            $channels[] = WhatsAppChannel::class;
        }
        return $channels;
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $tuss = $this->item->tuss;
        $tussName = $tuss ? $tuss->name : 'Procedimento';
        $negotiation = $this->item->negotiation;
        $formattedValue = 'R$ ' . number_format($this->item->approved_value, 2, ',', '.');
        
        return [
            'title' => 'Contra-proposta Recebida',
            'body' => "Uma contra-proposta foi feita para o procedimento '{$tussName}' com valor de {$formattedValue}.",
            'action_url' => "/negotiations/{$negotiation->id}",
            'action_text' => 'Ver Negociação',
            'icon' => 'mdi-arrow-left-right',
            'type' => 'counter_offer',
            'negotiation_id' => $negotiation->id,
            'item_id' => $this->item->id,
            'tuss_id' => $this->item->tuss_id,
            'tuss_name' => $tussName,
            'counter_value' => $this->item->approved_value,
            'negotiation_title' => $negotiation->title,
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
        $recipientName = $notifiable->name ?? 'Usuário';
        $tussName = $this->item->tuss ? $this->item->tuss->name : 'Procedimento';
        $formattedValue = number_format($this->item->approved_value, 2, ',', '.');

        return (new WhatsAppMessage)
            ->template('negotiation_counter_offer')
            ->to($notifiable->phone)
            ->parameters([
                '1' => $recipientName,
                '2' => $this->item->negotiation->title,
                '3' => $tussName,
                '4' => "R$ {$formattedValue}",
                '5' => url("/negotiations/{$this->item->negotiation->id}")
            ]);
    }
} 