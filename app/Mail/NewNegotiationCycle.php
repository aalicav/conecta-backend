<?php

namespace App\Mail;

use App\Models\Negotiation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

class NewNegotiationCycle extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $initiator;
    public string $previousStatus;
    public int $pendingItemsCount;
    public int $totalItemsCount;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Negotiation $negotiation,
        public User $recipient,
        public string $actionUrl,
        string $previousStatus
    ) {
        $this->initiator = Auth::user() ?? User::find($negotiation->updated_by) ?? $recipient;
        $this->previousStatus = $previousStatus;
        $this->pendingItemsCount = $negotiation->items()->where('status', 'pending')->count();
        $this->totalItemsCount = $negotiation->items()->count();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Novo Ciclo de Negociação Iniciado: ' . $this->negotiation->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.new_negotiation_cycle',
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