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

class NegotiationPartiallyCompleted extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $approvedItemsCount;
    public int $rejectedItemsCount;
    public int $totalItemsCount;
    public float $approvedValue;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Negotiation $negotiation,
        public User $recipient,
        public string $actionUrl
    ) {
        $this->approvedItemsCount = $negotiation->items()->where('status', 'approved')->count();
        $this->rejectedItemsCount = $negotiation->items()->where('status', 'rejected')->count();
        $this->totalItemsCount = $negotiation->items()->count();
        $this->approvedValue = $negotiation->items()->where('status', 'approved')->sum('approved_value');
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Negociação Parcialmente Concluída: ' . $this->negotiation->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.negotiation_partially_completed',
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