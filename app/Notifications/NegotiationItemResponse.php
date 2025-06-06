<?php

namespace App\Notifications;

use App\Models\NegotiationItem;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Messages\WhatsAppMessage;

class NegotiationItemResponse extends Notification
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
        
        $statusText = match($this->item->status) {
            'approved' => 'aprovado',
            'rejected' => 'rejeitado',
            default => 'respondido'
        };
        
        // Mensagem personalizada baseada no status
        $body = "O procedimento '{$tussName}' foi {$statusText}";
        if ($this->item->status === 'approved' && $this->item->approved_value) {
            $formattedValue = 'R$ ' . number_format($this->item->approved_value, 2, ',', '.');
            $body .= " com valor de {$formattedValue}";
        }
        if ($this->item->notes) {
            $body .= ". Observação: {$this->item->notes}";
        }
        
        return [
            'title' => 'Resposta em Item da Negociação',
            'body' => $body,
            'action_url' => "/negotiations/{$negotiation->id}",
            'action_text' => 'Ver Negociação',
            'icon' => 'mdi-comment-check',
            'type' => 'item_response',
            'negotiation_id' => $negotiation->id,
            'item_id' => $this->item->id,
            'tuss_id' => $this->item->tuss_id,
            'tuss_name' => $tussName,
            'status' => $this->item->status,
            'approved_value' => $this->item->approved_value,
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
        $statusText = match($this->item->status) {
            'approved' => 'aprovado',
            'rejected' => 'rejeitado',
            default => 'respondido'
        };
        
        $formattedValue = '';
        if ($this->item->status === 'approved' && $this->item->approved_value) {
            $formattedValue = number_format($this->item->approved_value, 2, ',', '.');
        }

        return (new WhatsAppMessage)
            ->template('negotiation_item_response')
            ->to($notifiable->phone)
            ->parameters([
                '1' => $recipientName,
                '2' => $this->item->negotiation->title,
                '3' => $tussName,
                '4' => $statusText,
                '5' => $formattedValue ? "R$ {$formattedValue}" : "N/A",
                '6' => $this->item->notes ?: "Nenhuma observação",
                '7' => url("/negotiations/{$this->item->negotiation->id}")
            ]);
    }
} 