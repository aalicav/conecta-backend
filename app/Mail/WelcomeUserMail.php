<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class WelcomeUserMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The user's name.
     *
     * @var string
     */
    public $name;

    /**
     * The user's email.
     *
     * @var string
     */
    public $email;

    /**
     * The user's password.
     *
     * @var string
     */
    public $password;

    /**
     * Create a new message instance.
     *
     * @param array $data
     * @return void
     */
    public function __construct(array $data)
    {
        $this->name = $data['name'];
        $this->email = $data['email'];
        $this->password = $data['password'];
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Bem-vindo ao ' . config('app.name'))
                    ->view('emails.welcome_new_user');
    }
} 