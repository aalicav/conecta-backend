<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GeneralNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * The notification title.
     *
     * @var string
     */
    public $subject;

    /**
     * The notification body.
     *
     * @var string
     */
    public $content;

    /**
     * The action URL.
     *
     * @var string|null
     */
    public $actionUrl;

    /**
     * The action text.
     *
     * @var string
     */
    public $actionText;

    /**
     * Create a new message instance.
     *
     * @param string $subject
     * @param string $content
     * @param string|null $actionUrl
     * @param string $actionText
     * @return void
     */
    public function __construct(string $subject, string $content, string $actionUrl = null, string $actionText = 'Ver Detalhes')
    {
        $this->subject = $subject;
        $this->content = $content;
        $this->actionUrl = $actionUrl;
        $this->actionText = $actionText;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            html: 'emails.general-notification',
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