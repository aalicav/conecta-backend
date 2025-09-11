<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOneHourReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:send-one-hour-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminders 1 hour before confirmed appointments';

    /**
     * Create a new command instance.
     */
    public function __construct(protected NotificationService $notificationService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting one-hour appointment reminders...');
        
        $now = Carbon::now();
        $oneHourFromNow = $now->copy()->addHour();
        
        // Janela de 10 minutos para capturar agendamentos próximos de 1 hora
        $startWindow = $oneHourFromNow->copy()->subMinutes(5);
        $endWindow = $oneHourFromNow->copy()->addMinutes(5);
        
        // Buscar agendamentos confirmados que estão próximos de 1 hora
        $appointments = Appointment::where('status', Appointment::STATUS_CONFIRMED)
            ->where('scheduled_date', '>=', $startWindow)
            ->where('scheduled_date', '<=', $endWindow)
            ->whereNull('reminder_sent_at') // Evitar envio duplicado
            ->get();

        $this->info("Found {$appointments->count()} appointments for one-hour reminder");

        if ($appointments->isEmpty()) {
            $this->info('No appointments found for one-hour reminder');
            return 0;
        }

        $sentCount = 0;
        $errorCount = 0;

        foreach ($appointments as $appointment) {
            try {
                $this->info("Processing reminder for appointment #{$appointment->id}");
                
                // Calcular tempo restante
                $timeRemaining = $now->diffInMinutes($appointment->scheduled_date, false);
                $hoursRemaining = floor($timeRemaining / 60);
                $minutesRemaining = $timeRemaining % 60;
                
                // Enviar notificações
                $this->sendReminderNotifications($appointment, $hoursRemaining, $minutesRemaining);
                
                // Marcar como lembrança enviada
                $appointment->update([
                    'reminder_sent_at' => now()
                ]);
                
                $sentCount++;
                $this->info("Sent one-hour reminder for appointment #{$appointment->id}");
                
            } catch (\Exception $e) {
                $errorCount++;
                $this->error("Failed to send reminder for appointment #{$appointment->id}: {$e->getMessage()}");
                Log::error("Failed to send one-hour reminder", [
                    'appointment_id' => $appointment->id,
                    'exception' => $e
                ]);
            }
        }

        $this->info("Successfully sent {$sentCount} one-hour reminders");
        if ($errorCount > 0) {
            $this->warn("Failed to send {$errorCount} reminders");
        }
        
        return 0;
    }

    /**
     * Send reminder notifications via email and WhatsApp
     */
    private function sendReminderNotifications(Appointment $appointment, int $hoursRemaining, int $minutesRemaining): void
    {
        $solicitation = $appointment->solicitation;
        $patient = $solicitation->patient;
        $healthPlan = $solicitation->healthPlan;
        
        if (!$patient) {
            $this->warn("No patient found for appointment #{$appointment->id}");
            return;
        }

        // Enviar email para o paciente
        $this->sendPatientReminderEmail($appointment, $patient, $healthPlan, $hoursRemaining, $minutesRemaining);
        
        // Enviar WhatsApp para o paciente
        $this->sendPatientReminderWhatsApp($appointment, $patient, $healthPlan, $hoursRemaining, $minutesRemaining);
    }

    /**
     * Send reminder email to patient
     */
    private function sendPatientReminderEmail(Appointment $appointment, $patient, $healthPlan, int $hoursRemaining, int $minutesRemaining): void
    {
        if (!$patient->email) {
            $this->warn("No email found for patient #{$patient->id}");
            return;
        }

        $timeText = $this->formatTimeRemaining($hoursRemaining, $minutesRemaining);
        
        $subject = "Lembrete de Consulta - {$timeText}";
        
        $data = [
            'appointment_id' => $appointment->id,
            'patient_name' => $patient->name,
            'health_plan_name' => $healthPlan->name ?? 'N/A',
            'scheduled_date' => $appointment->scheduled_date->format('d/m/Y H:i'),
            'time_remaining' => $timeText,
            'hours_remaining' => $hoursRemaining,
            'minutes_remaining' => $minutesRemaining,
            'procedure_name' => $appointment->solicitation->tuss->description ?? 'N/A',
            'provider_name' => $this->getProviderName($appointment),
            'provider_address' => $this->getProviderAddress($appointment),
        ];

        try {
            Mail::send('emails.appointments.one_hour_reminder', $data, function ($message) use ($patient, $subject) {
                $message->to($patient->email)
                       ->subject($subject);
            });
            
            Log::info("Sent one-hour reminder email to patient #{$patient->id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send one-hour reminder email to patient #{$patient->id}", [
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send reminder WhatsApp to patient
     */
    private function sendPatientReminderWhatsApp(Appointment $appointment, $patient, $healthPlan, int $hoursRemaining, int $minutesRemaining): void
    {
        if (!$patient->phone) {
            $this->warn("No phone found for patient #{$patient->id}");
            return;
        }

        $timeText = $this->formatTimeRemaining($hoursRemaining, $minutesRemaining);
        $solicitation = $appointment->solicitation;
        
        $message = "⏰ *Lembrete de Consulta*\n\n" .
                  "Olá {$patient->name}!\n\n" .
                  "Sua consulta está agendada para:\n" .
                  "📅 Data: " . $appointment->scheduled_date->format('d/m/Y') . "\n" .
                  "🕐 Horário: " . $appointment->scheduled_date->format('H:i') . "\n" .
                  "⏱️ Faltam: {$timeText}\n\n" .
                  "🩺 Procedimento: " . ($solicitation->tuss->description ?? 'N/A') . "\n" .
                  "🏥 Profissional: " . $this->getProviderName($appointment) . "\n" .
                  "📍 Endereço: " . $this->getProviderAddress($appointment) . "\n\n" .
                  "Por favor, chegue com 15 minutos de antecedência.\n\n" .
                  "Em caso de dúvidas, entre em contato conosco.";

        try {
            $whatsAppService = app(\App\Services\WhapiWhatsAppService::class);
            $whatsAppService->sendTextMessage(
                $patient->phone,
                $message,
                'App\\Models\\Appointment',
                $appointment->id
            );
            
            Log::info("Sent one-hour reminder WhatsApp to patient #{$patient->id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send one-hour reminder WhatsApp to patient #{$patient->id}", [
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Format time remaining in a readable format
     */
    private function formatTimeRemaining(int $hours, int $minutes): string
    {
        if ($hours > 0 && $minutes > 0) {
            return "{$hours}h e {$minutes}min";
        } elseif ($hours > 0) {
            return "{$hours}h";
        } else {
            return "{$minutes}min";
        }
    }

    /**
     * Get provider name for the appointment
     */
    private function getProviderName(Appointment $appointment): string
    {
        if ($appointment->provider_type === 'App\\Models\\Professional') {
            $professional = $appointment->provider;
            return $professional ? $professional->name : 'Profissional não informado';
        } elseif ($appointment->provider_type === 'App\\Models\\Clinic') {
            $clinic = $appointment->provider;
            return $clinic ? $clinic->name : 'Clínica não informada';
        }
        
        return 'Profissional não informado';
    }

    /**
     * Get provider address for the appointment
     */
    private function getProviderAddress(Appointment $appointment): string
    {
        if ($appointment->address) {
            $address = $appointment->address;
            return "{$address->street}, {$address->number}, {$address->neighborhood}, {$address->city} - {$address->state}";
        }
        
        if ($appointment->provider_type === 'App\\Models\\Professional') {
            $professional = $appointment->provider;
            if ($professional && $professional->address) {
                $address = $professional->address;
                return "{$address->street}, {$address->number}, {$address->neighborhood}, {$address->city} - {$address->state}";
            }
        } elseif ($appointment->provider_type === 'App\\Models\\Clinic') {
            $clinic = $appointment->provider;
            if ($clinic && $clinic->address) {
                $address = $clinic->address;
                return "{$address->street}, {$address->number}, {$address->neighborhood}, {$address->city} - {$address->state}";
            }
        }
        
        return 'Endereço não informado';
    }
}
