<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Appointment;
use App\Models\User;

class PaymentNeeded extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    public $appointment;

    /**
     * The recipient user.
     *
     * @var \App\Models\User
     */
    public $user;

    /**
     * The action URL.
     *
     * @var string
     */
    public $actionUrl;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\Appointment $appointment
     * @param \App\Models\User $user
     * @param string $actionUrl
     * @return void
     */
    public function __construct(Appointment $appointment, User $user, string $actionUrl)
    {
        $this->appointment = $appointment;
        $this->user = $user;
        $this->actionUrl = $actionUrl;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Pagamento de Prestador Pendente')
                    ->view('emails.appointments.payment-needed');
    }
} 