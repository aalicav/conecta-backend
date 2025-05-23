<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Appointment;
use App\Models\User;

class PatientAbsenceFinance extends Mailable
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
     * Whether the appointment was already paid.
     *
     * @var bool
     */
    public $isPaid;

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
     * @param bool $isPaid
     * @param string $actionUrl
     * @return void
     */
    public function __construct(Appointment $appointment, User $user, bool $isPaid, string $actionUrl)
    {
        $this->appointment = $appointment;
        $this->user = $user;
        $this->isPaid = $isPaid;
        $this->actionUrl = $actionUrl;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Paciente Ausente - Possível Estorno')
                    ->view('emails.appointments.patient-absence-finance');
    }
} 