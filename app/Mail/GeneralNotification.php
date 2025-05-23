<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class GeneralNotification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The notification title.
     *
     * @var string
     */
    public $title;

    /**
     * The notification body.
     *
     * @var string
     */
    public $body;

    /**
     * The action URL.
     *
     * @var string|null
     */
    public $actionUrl;

    /**
     * Create a new message instance.
     *
     * @param string $title
     * @param string $body
     * @param string|null $actionUrl
     * @return void
     */
    public function __construct(string $title, string $body, ?string $actionUrl = null)
    {
        $this->title = $title;
        $this->body = $body;
        $this->actionUrl = $actionUrl;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $mail = $this->subject($this->title)
                     ->view('emails.general-notification');
                     
        return $mail;
    }
} 