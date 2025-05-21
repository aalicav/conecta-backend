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

class StatusRollback extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public User $reverter;
    public string $previousStatus;
    public string $currentStatus;
    public ?string $reason;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public Negotiation $negotiation,
        public User $recipient,
        public string $actionUrl,
        string $previousStatus,
        string $currentStatus,
        ?string $reason = null
    ) {
        $this->reverter = Auth::user() ?? User::find($negotiation->updated_by) ?? $recipient;
        $this->previousStatus = $previousStatus;
        $this->currentStatus = $currentStatus;
        $this->reason = $reason;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Status da Negociação Revertido: ' . $this->negotiation->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.status_rollback',
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