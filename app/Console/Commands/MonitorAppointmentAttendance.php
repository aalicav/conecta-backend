<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Solicitation;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MonitorAppointmentAttendance extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:monitor-attendance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor completed appointments and send notifications for unconfirmed attendance';

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
        $this->info('Starting appointment attendance monitoring...');
        
        $now = Carbon::now();
        
        // Buscar agendamentos concluídos que ainda não tiveram o comparecimento confirmado
        $completedAppointments = Appointment::where('status', Appointment::STATUS_COMPLETED)
            ->whereNull('patient_attended')
            ->whereNotNull('completed_date')
            ->get();

        $this->info("Found {$completedAppointments->count()} completed appointments without attendance confirmation");

        $oneHourAgo = $now->copy()->subHour();
        $twoHoursAgo = $now->copy()->subHours(2);

        $oneHourOverdue = 0;
        $twoHoursOverdue = 0;

        foreach ($completedAppointments as $appointment) {
            $completedAt = Carbon::parse($appointment->completed_date);
            
            // Verificar se passou 1 hora desde a conclusão
            if ($completedAt->lte($oneHourAgo) && $completedAt->gt($twoHoursAgo)) {
                $this->handleOneHourOverdue($appointment);
                $oneHourOverdue++;
            }
            
            // Verificar se passou 2 horas desde a conclusão
            if ($completedAt->lte($twoHoursAgo)) {
                $this->handleTwoHoursOverdue($appointment);
                $twoHoursOverdue++;
            }
        }

        $this->info("Processed {$oneHourOverdue} appointments overdue by 1 hour");
        $this->info("Processed {$twoHoursOverdue} appointments overdue by 2 hours");
        
        return 0;
    }

    /**
     * Handle appointments overdue by 1 hour
     */
    private function handleOneHourOverdue(Appointment $appointment): void
    {
        try {
            $this->info("Processing 1-hour overdue appointment #{$appointment->id}");
            
            // Buscar usuários com roles super_admin ou network_manager
            $users = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['super_admin', 'network_manager']);
            })->get();

            if ($users->isEmpty()) {
                $this->warn("No super_admin or network_manager users found for notification");
                return;
            }

            // Enviar notificação por email
            $this->sendAttendanceReminderEmail($appointment, $users, 1);
            
            Log::info("Sent 1-hour attendance reminder for appointment #{$appointment->id}");
            
        } catch (\Exception $e) {
            $this->error("Failed to process 1-hour overdue appointment #{$appointment->id}: {$e->getMessage()}");
            Log::error("Failed to process 1-hour overdue appointment", [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Handle appointments overdue by 2 hours
     */
    private function handleTwoHoursOverdue(Appointment $appointment): void
    {
        try {
            $this->info("Processing 2-hour overdue appointment #{$appointment->id}");
            
            // Marcar como não compareceu
            $appointment->update([
                'patient_attended' => false,
                'attendance_confirmed_at' => now(),
                'attendance_confirmed_by' => 1, // Sistema user
                'attendance_notes' => 'Marcado automaticamente como não compareceu após 2 horas sem confirmação',
                'status' => Appointment::STATUS_MISSED
            ]);

            // Buscar usuários com roles super_admin ou network_manager
            $users = User::whereHas('roles', function ($query) {
                $query->whereIn('name', ['super_admin', 'network_manager']);
            })->get();

            if ($users->isNotEmpty()) {
                // Enviar notificação por email
                $this->sendMissedAttendanceEmail($appointment, $users);
            }

            // Notificar plano de saúde
            $this->notifyHealthPlan($appointment);
            
            Log::info("Marked appointment #{$appointment->id} as missed and sent notifications");
            
        } catch (\Exception $e) {
            $this->error("Failed to process 2-hour overdue appointment #{$appointment->id}: {$e->getMessage()}");
            Log::error("Failed to process 2-hour overdue appointment", [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send attendance reminder email
     */
    private function sendAttendanceReminderEmail(Appointment $appointment, $users, int $hoursOverdue): void
    {
        $solicitation = $appointment->solicitation;
        $patient = $solicitation->patient;
        $healthPlan = $solicitation->healthPlan;

        $subject = "Agendamento #{$appointment->id} - Confirmação de Comparecimento Pendente";
        
        $data = [
            'appointment_id' => $appointment->id,
            'patient_name' => $patient->name,
            'health_plan_name' => $healthPlan->name ?? 'N/A',
            'scheduled_date' => $appointment->scheduled_date->format('d/m/Y H:i'),
            'completed_date' => $appointment->completed_date->format('d/m/Y H:i'),
            'hours_overdue' => $hoursOverdue,
            'procedure_name' => $solicitation->tuss->description ?? 'N/A',
        ];

        foreach ($users as $user) {
            try {
                Mail::send('emails.appointments.attendance_reminder', $data, function ($message) use ($user, $subject) {
                    $message->to($user->email)
                           ->subject($subject);
                });
            } catch (\Exception $e) {
                Log::error("Failed to send attendance reminder email to user #{$user->id}", [
                    'user_id' => $user->id,
                    'appointment_id' => $appointment->id,
                    'exception' => $e
                ]);
            }
        }
    }

    /**
     * Send missed attendance email
     */
    private function sendMissedAttendanceEmail(Appointment $appointment, $users): void
    {
        $solicitation = $appointment->solicitation;
        $patient = $solicitation->patient;
        $healthPlan = $solicitation->healthPlan;

        $subject = "Agendamento #{$appointment->id} - Paciente Não Compareceu";
        
        $data = [
            'appointment_id' => $appointment->id,
            'patient_name' => $patient->name,
            'health_plan_name' => $healthPlan->name ?? 'N/A',
            'scheduled_date' => $appointment->scheduled_date->format('d/m/Y H:i'),
            'completed_date' => $appointment->completed_date->format('d/m/Y H:i'),
            'procedure_name' => $solicitation->tuss->description ?? 'N/A',
        ];

        foreach ($users as $user) {
            try {
                Mail::send('emails.appointments.missed_attendance', $data, function ($message) use ($user, $subject) {
                    $message->to($user->email)
                           ->subject($subject);
                });
            } catch (\Exception $e) {
                Log::error("Failed to send missed attendance email to user #{$user->id}", [
                    'user_id' => $user->id,
                    'appointment_id' => $appointment->id,
                    'exception' => $e
                ]);
            }
        }
    }

    /**
     * Notify health plan about missed appointment
     */
    private function notifyHealthPlan(Appointment $appointment): void
    {
        try {
            $solicitation = $appointment->solicitation;
            $healthPlan = $solicitation->healthPlan;
            
            if (!$healthPlan) {
                $this->warn("No health plan found for appointment #{$appointment->id}");
                return;
            }

            // Enviar email para o plano de saúde
            $this->sendHealthPlanMissedEmail($appointment, $healthPlan);
            
            // Enviar WhatsApp para o plano de saúde (se configurado)
            $this->sendHealthPlanMissedWhatsApp($appointment, $healthPlan);
            
        } catch (\Exception $e) {
            Log::error("Failed to notify health plan for missed appointment #{$appointment->id}", [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send missed appointment email to health plan
     */
    private function sendHealthPlanMissedEmail(Appointment $appointment, $healthPlan): void
    {
        $solicitation = $appointment->solicitation;
        $patient = $solicitation->patient;

        $subject = "Paciente Não Compareceu - Agendamento #{$appointment->id}";
        
        $data = [
            'appointment_id' => $appointment->id,
            'patient_name' => $patient->name,
            'patient_document' => $patient->document ?? 'N/A',
            'health_plan_name' => $healthPlan->name,
            'scheduled_date' => $appointment->scheduled_date->format('d/m/Y H:i'),
            'completed_date' => $appointment->completed_date->format('d/m/Y H:i'),
            'procedure_name' => $solicitation->tuss->description ?? 'N/A',
        ];

        // Buscar email do plano de saúde
        $healthPlanEmail = $healthPlan->email ?? $healthPlan->contact_email;
        
        if (!$healthPlanEmail) {
            $this->warn("No email found for health plan #{$healthPlan->id}");
            return;
        }

        try {
            Mail::send('emails.appointments.health_plan_missed', $data, function ($message) use ($healthPlanEmail, $subject) {
                $message->to($healthPlanEmail)
                       ->subject($subject);
            });
        } catch (\Exception $e) {
            Log::error("Failed to send missed appointment email to health plan #{$healthPlan->id}", [
                'health_plan_id' => $healthPlan->id,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Send missed appointment WhatsApp to health plan
     */
    private function sendHealthPlanMissedWhatsApp(Appointment $appointment, $healthPlan): void
    {
        try {
            $solicitation = $appointment->solicitation;
            $patient = $solicitation->patient;

            // Buscar número do WhatsApp do plano de saúde
            $whatsappNumber = $healthPlan->whatsapp_number ?? $healthPlan->phone;
            
            if (!$whatsappNumber) {
                $this->warn("No WhatsApp number found for health plan #{$healthPlan->id}");
                return;
            }

            $message = "🚨 *Paciente Não Compareceu*\n\n" .
                      "Agendamento: #{$appointment->id}\n" .
                      "Paciente: {$patient->name}\n" .
                      "Data/Hora: " . $appointment->scheduled_date->format('d/m/Y H:i') . "\n" .
                      "Procedimento: " . ($solicitation->tuss->description ?? 'N/A') . "\n\n" .
                      "O paciente não compareceu ao agendamento e foi marcado automaticamente como ausente após 2 horas sem confirmação.";

            // Usar o serviço de WhatsApp existente
            $whatsAppService = app(\App\Services\WhapiWhatsAppService::class);
            $whatsAppService->sendTextMessage(
                $whatsappNumber,
                $message,
                'App\\Models\\HealthPlan',
                $healthPlan->id
            );
            
        } catch (\Exception $e) {
            Log::error("Failed to send missed appointment WhatsApp to health plan #{$healthPlan->id}", [
                'health_plan_id' => $healthPlan->id,
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }
}
