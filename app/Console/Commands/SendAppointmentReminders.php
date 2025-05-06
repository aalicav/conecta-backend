<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendAppointmentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:send-reminders {--hours=24 : Hours before appointment to send reminder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminders for upcoming appointments';

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
        $hours = (int) $this->option('hours');
        
        $this->info("Sending reminders for appointments in the next {$hours} hours...");
        
        $now = Carbon::now();
        $targetTime = $now->copy()->addHours($hours);
        
        // Encontrar agendamentos que estão dentro do período alvo
        // (entre now + hours - 1 e now + hours)
        // Isso garante que só enviemos uma vez para cada intervalo de tempo
        $startWindow = $now->copy()->addHours($hours - 1);
        
        $appointments = Appointment::where('status', Appointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>', $startWindow)
            ->where('scheduled_at', '<=', $targetTime)
            ->get();
            
        $count = $appointments->count();
        $this->info("Found {$count} appointments scheduled for reminder");
        
        if ($count === 0) {
            return 0;
        }
        
        $sentCount = 0;
        
        foreach ($appointments as $appointment) {
            try {
                // Calcular horas restantes com precisão
                $hoursRemaining = $now->diffInHours($appointment->scheduled_at, false);
                
                // Enviar notificação com as horas exatas restantes
                $this->notificationService->sendAppointmentReminder($appointment, $hoursRemaining);
                
                $sentCount++;
                $this->info("Sent reminder for appointment #{$appointment->id}, scheduled at {$appointment->scheduled_at}, with {$hoursRemaining} hours remaining");
            } catch (\Exception $e) {
                $this->error("Failed to send reminder for appointment #{$appointment->id}: {$e->getMessage()}");
                Log::error("Failed to send appointment reminder: {$e->getMessage()}", [
                    'appointment_id' => $appointment->id,
                    'exception' => $e
                ]);
            }
        }
        
        $this->info("Successfully sent {$sentCount} reminders");
        
        return 0;
    }
} 