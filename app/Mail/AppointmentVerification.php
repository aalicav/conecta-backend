<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Appointment;

class AppointmentVerification extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    public $appointment;

    /**
     * The recipient entity.
     *
     * @var mixed
     */
    public $recipient;

    /**
     * The verification URL.
     *
     * @var string
     */
    public $verificationUrl;

    /**
     * The recipient type (patient or provider).
     *
     * @var string
     */
    public $recipientType;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\Appointment $appointment
     * @param mixed $recipient
     * @param string $verificationUrl
     * @param string $recipientType
     * @return void
     */
    public function __construct(Appointment $appointment, $recipient, string $verificationUrl, string $recipientType)
    {
        $this->appointment = $appointment;
        $this->recipient = $recipient;
        $this->verificationUrl = $verificationUrl;
        $this->recipientType = $recipientType;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = 'Confirmação de Atendimento - Conecta Saúde';
        
        return $this->subject($subject)
                    ->view('emails.appointments.verification')
                    ->with([
                        'appointment' => $this->appointment,
                        'recipient' => $this->recipient,
                        'verificationUrl' => $this->verificationUrl,
                        'recipientType' => $this->recipientType
                    ]);
    }
} 