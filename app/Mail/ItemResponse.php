<?php

namespace App\Mail;

use App\Models\NegotiationItem;
use App\Models\Negotiation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class ItemResponse extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Negotiation $negotiation;
    public User $responder;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public NegotiationItem $item,
        public User $recipient,
        public string $actionUrl
    ) {
        $this->negotiation = $item->negotiation;
        $this->responder = Auth::user() ?? User::find($item->updated_by) ?? $recipient;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Resposta a Item de Negociação: ' . $this->negotiation->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.item_response',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
} 