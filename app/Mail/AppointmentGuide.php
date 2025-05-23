<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\Appointment;

class AppointmentGuide extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The appointment instance.
     *
     * @var \App\Models\Appointment
     */
    public $appointment;

    /**
     * The guide content or path.
     *
     * @var string
     */
    public $guidePath;

    /**
     * Create a new message instance.
     *
     * @param \App\Models\Appointment $appointment
     * @param string $guidePath
     * @return void
     */
    public function __construct(Appointment $appointment, string $guidePath)
    {
        $this->appointment = $appointment;
        $this->guidePath = $guidePath;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        $subject = 'Guia de Atendimento - Conecta Saúde';
        
        $mail = $this->subject($subject)
                    ->view('emails.appointments.guide')
                    ->with([
                        'appointment' => $this->appointment
                    ]);
                    
        // Anexar o PDF da guia
        if (file_exists(storage_path('app/' . $this->guidePath))) {
            $mail->attach(storage_path('app/' . $this->guidePath), [
                'as' => 'guia_atendimento.pdf',
                'mime' => 'application/pdf',
            ]);
        }
        
        return $mail;
    }
} 