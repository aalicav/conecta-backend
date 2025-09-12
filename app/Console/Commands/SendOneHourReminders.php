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
        
        // Enviar notificações para plano de saúde e profissional/clínica
        $this->sendProviderAndHealthPlanReminders($appointment, $hoursRemaining, $minutesRemaining);
                
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

    /**
     * Send reminder notifications to health plan and provider
     */
    private function sendProviderAndHealthPlanReminders(Appointment $appointment, int $hoursRemaining, int $minutesRemaining): void
    {
        $solicitation = $appointment->solicitation;
        $patient = $solicitation->patient;
        $healthPlan = $solicitation->healthPlan;
        
        // Notificar plano de saúde
        if ($healthPlan) {
            $this->sendHealthPlanReminder($appointment, $healthPlan, $patient, $hoursRemaining, $minutesRemaining);
        }
        
        // Notificar profissional/clínica
        $this->sendProviderReminder($appointment, $patient, $hoursRemaining, $minutesRemaining);
    }

    /**
     * Send reminder to health plan
     */
    private function sendHealthPlanReminder(Appointment $appointment, $healthPlan, $patient, int $hoursRemaining, int $minutesRemaining): void
    {
        $timeText = $this->formatTimeRemaining($hoursRemaining, $minutesRemaining);
        $solicitation = $appointment->solicitation;
        
        // Enviar email para o plano de saúde
        $this->sendHealthPlanReminderEmail($appointment, $healthPlan, $patient, $timeText);
        
        // Enviar WhatsApp para o plano de saúde
        $this->sendHealthPlanReminderWhatsApp($appointment, $healthPlan, $patient, $timeText);
    }

    /**
     * Send reminder email to health plan
     */
    private function sendHealthPlanReminderEmail(Appointment $appointment, $healthPlan, $patient, string $timeText): void
    {
        $healthPlanEmail = $healthPlan->email ?? $healthPlan->contact_email;
        
        if (!$healthPlanEmail) {
            $this->warn("No email found for health plan #{$healthPlan->id}");
            return;
        }

        $subject = "Lembrete de Consulta - Paciente {$patient->name} - {$timeText}";
        
        $data = [
            'appointment_id' => $appointment->id,
            'patient_name' => $patient->name,
            'patient_document' => $patient->document ?? 'N/A',
            'health_plan_name' => $healthPlan->name,
            'scheduled_date' => $appointment->scheduled_date->format('d/m/Y H:i'),
            'time_remaining' => $timeText,
            'procedure_name' => $appointment->solicitation->tuss->description ?? 'N/A',
            'provider_name' => $this->getProviderName($appointment),
            'provider_address' => $this->getProviderAddress($appointment),
        ];

        try {
            Mail::send('emails.appointments.health_plan_one_hour_reminder', $data, function ($message) use ($healthPlanEmail, $subject) {
                $message->to($healthPlanEmail)
                       ->subject($subject);
            });
            
            Log::info("Sent one-hour reminder email to health plan #{$healthPlan->id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send one-hour reminder email to health plan #{$healthPlan->id}", [
                'health_plan_id' => $healthPlan->id,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send reminder WhatsApp to health plan
     */
    private function sendHealthPlanReminderWhatsApp(Appointment $appointment, $healthPlan, $patient, string $timeText): void
    {
        $whatsappNumber = $healthPlan->whatsapp_number ?? $healthPlan->phone;
        
        if (!$whatsappNumber) {
            $this->warn("No WhatsApp number found for health plan #{$healthPlan->id}");
            return;
        }

        $message = "⏰ *Lembrete de Consulta - {$timeText}*\n\n" .
                  "Paciente: {$patient->name}\n" .
                  "Documento: " . ($patient->document ?? 'N/A') . "\n" .
                  "Data/Hora: " . $appointment->scheduled_date->format('d/m/Y H:i') . "\n" .
                  "Procedimento: " . ($appointment->solicitation->tuss->description ?? 'N/A') . "\n" .
                  "Profissional: " . $this->getProviderName($appointment) . "\n" .
                  "Endereço: " . $this->getProviderAddress($appointment) . "\n\n" .
                  "A consulta está próxima. Por favor, confirme a presença do paciente.";

        try {
            $whatsAppService = app(\App\Services\WhapiWhatsAppService::class);
            $whatsAppService->sendTextMessage(
                $whatsappNumber,
                $message,
                'App\\Models\\HealthPlan',
                $healthPlan->id
            );
            
            Log::info("Sent one-hour reminder WhatsApp to health plan #{$healthPlan->id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send one-hour reminder WhatsApp to health plan #{$healthPlan->id}", [
                'health_plan_id' => $healthPlan->id,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send reminder to provider (professional or clinic)
     */
    private function sendProviderReminder(Appointment $appointment, $patient, int $hoursRemaining, int $minutesRemaining): void
    {
        $timeText = $this->formatTimeRemaining($hoursRemaining, $minutesRemaining);
        
        // Enviar email para o profissional/clínica
        $this->sendProviderReminderEmail($appointment, $patient, $timeText);
        
        // Enviar WhatsApp para o profissional/clínica
        $this->sendProviderReminderWhatsApp($appointment, $patient, $timeText);
    }

    /**
     * Send reminder email to provider
     */
    private function sendProviderReminderEmail(Appointment $appointment, $patient, string $timeText): void
    {
        $provider = $appointment->provider;
        $providerEmail = null;
        $providerName = $this->getProviderName($appointment);
        
        if ($appointment->provider_type === 'App\\Models\\Professional') {
            $providerEmail = $provider->email ?? $provider->contact_email;
        } elseif ($appointment->provider_type === 'App\\Models\\Clinic') {
            $providerEmail = $provider->email ?? $provider->contact_email;
        }
        
        if (!$providerEmail) {
            $this->warn("No email found for provider #{$appointment->provider_id} ({$appointment->provider_type})");
            return;
        }

        $subject = "Lembrete de Consulta - Paciente {$patient->name} - {$timeText}";
        
        $data = [
            'appointment_id' => $appointment->id,
            'patient_name' => $patient->name,
            'patient_document' => $patient->document ?? 'N/A',
            'patient_phone' => $patient->phone ?? 'N/A',
            'provider_name' => $providerName,
            'scheduled_date' => $appointment->scheduled_date->format('d/m/Y H:i'),
            'time_remaining' => $timeText,
            'procedure_name' => $appointment->solicitation->tuss->description ?? 'N/A',
            'provider_address' => $this->getProviderAddress($appointment),
        ];

        try {
            Mail::send('emails.appointments.provider_one_hour_reminder', $data, function ($message) use ($providerEmail, $subject) {
                $message->to($providerEmail)
                       ->subject($subject);
            });
            
            Log::info("Sent one-hour reminder email to provider #{$appointment->provider_id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send one-hour reminder email to provider #{$appointment->provider_id}", [
                'provider_id' => $appointment->provider_id,
                'provider_type' => $appointment->provider_type,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send reminder WhatsApp to provider
     */
    private function sendProviderReminderWhatsApp(Appointment $appointment, $patient, string $timeText): void
    {
        $provider = $appointment->provider;
        $providerPhone = null;
        
        if ($appointment->provider_type === 'App\\Models\\Professional') {
            $providerPhone = $provider->phone;
        } elseif ($appointment->provider_type === 'App\\Models\\Clinic') {
            $providerPhone = $provider->phone;
        }
        
        if (!$providerPhone) {
            $this->warn("No phone found for provider #{$appointment->provider_id} ({$appointment->provider_type})");
            return;
        }

        $message = "⏰ *Lembrete de Consulta - {$timeText}*\n\n" .
                  "Paciente: {$patient->name}\n" .
                  "Documento: " . ($patient->document ?? 'N/A') . "\n" .
                  "Telefone: " . ($patient->phone ?? 'N/A') . "\n" .
                  "Data/Hora: " . $appointment->scheduled_date->format('d/m/Y H:i') . "\n" .
                  "Procedimento: " . ($appointment->solicitation->tuss->description ?? 'N/A') . "\n" .
                  "Endereço: " . $this->getProviderAddress($appointment) . "\n\n" .
                  "A consulta está próxima. Por favor, confirme sua disponibilidade.";

        try {
            $whatsAppService = app(\App\Services\WhapiWhatsAppService::class);
            $whatsAppService->sendTextMessage(
                $providerPhone,
                $message,
                $appointment->provider_type,
                $appointment->provider_id
            );
            
            Log::info("Sent one-hour reminder WhatsApp to provider #{$appointment->provider_id} for appointment #{$appointment->id}");
        } catch (\Exception $e) {
            Log::error("Failed to send one-hour reminder WhatsApp to provider #{$appointment->provider_id}", [
                'provider_id' => $appointment->provider_id,
                'provider_type' => $appointment->provider_type,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }
}
