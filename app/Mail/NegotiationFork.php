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
use Illuminate\Database\Eloquent\Collection;

class NegotiationFork extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $forker;
    public Collection $forkedNegotiations;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Negotiation $originalNegotiation,
        public User $recipient,
        public string $actionUrl,
        array|Collection $forkedNegotiations
    ) {
        $this->forker = Auth::user() ?? User::find($originalNegotiation->updated_by) ?? $recipient;
        
        // Ensure forked negotiations is a collection
        if (is_array($forkedNegotiations)) {
            $this->forkedNegotiations = collect($forkedNegotiations);
        } else {
            $this->forkedNegotiations = $forkedNegotiations;
        }
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Negociação Bifurcada: ' . $this->originalNegotiation->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.negotiation_fork',
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