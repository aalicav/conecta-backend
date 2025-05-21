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

class PartialApproval extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $approver;
    public int $approvedItemsCount;
    public int $rejectedItemsCount;
    public int $totalItemsCount;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Negotiation $negotiation,
        public User $recipient,
        public string $actionUrl
    ) {
        $this->approver = Auth::user() ?? User::find($negotiation->approved_by) ?? $recipient;
        $this->approvedItemsCount = $negotiation->items()->where('status', 'approved')->count();
        $this->rejectedItemsCount = $negotiation->items()->where('status', 'rejected')->count();
        $this->totalItemsCount = $negotiation->items()->count();
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Negociação Parcialmente Aprovada: ' . $this->negotiation->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.partial_approval',
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