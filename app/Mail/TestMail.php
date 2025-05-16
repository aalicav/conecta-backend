<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The message content.
     *
     * @var string
     */
    public $content;

    /**
     * The email subject.
     *
     * @var string
     */
    protected $emailSubject;

    /**
     * Create a new message instance.
     *
     * @param string $content
     * @param string|null $subject
     * @return void
     */
    public function __construct(string $content, ?string $subject = null)
    {
        $this->content = $content;
        $this->emailSubject = $subject ?? 'Teste de Email - ' . config('app.name');
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject($this->emailSubject)
                    ->view('emails.test')
                    ->text('emails.test_plain');
    }
} 