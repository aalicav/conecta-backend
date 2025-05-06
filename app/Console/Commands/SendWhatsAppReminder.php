<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Professional;
use App\Notifications\AppointmentReminder;
use Illuminate\Support\Facades\Notification;

class SendWhatsAppReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:send-reminder {appointment_id} {clinic_address}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send a WhatsApp appointment reminder to a patient';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $appointmentId = $this->argument('appointment_id');
        $clinicAddress = $this->argument('clinic_address');
        
        try {
            $appointment = Appointment::findOrFail($appointmentId);
            $patient = Patient::findOrFail($appointment->solicitation->patient_id);
            $professional = Professional::findOrFail($appointment->provider_id);
            
            $this->info("Sending appointment reminder to {$patient->name}");
            
            // Create and send notification
            $notification = new AppointmentReminder(
                $appointment,
                $patient,
                $professional,
                $clinicAddress
            );
            
            Notification::send($patient, $notification);
            
            $this->info('WhatsApp notification sent successfully!');
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to send notification: ' . $e->getMessage());
            return 1;
        }
    }
} 