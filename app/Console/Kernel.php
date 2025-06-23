<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\CleanAuditLogs;
use App\Console\Commands\CheckMailConfig;
use App\Console\Commands\SendTestMail;
use App\Console\Commands\UpdateNegotiationPermissions;
use App\Console\Commands\CleanupOldNegotiations;
use App\Console\Commands\ProcessHealthPlanBilling;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        CleanAuditLogs::class,
        CheckMailConfig::class,
        SendTestMail::class,
        UpdateNegotiationPermissions::class,
        CleanupOldNegotiations::class,
        ProcessHealthPlanBilling::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run the audit log cleanup command weekly
        $schedule->command('audit:clean')->weekly()->sundays()->at('01:00');

        // Run cleanup of old negotiations every day at midnight
        $schedule->command('negotiations:cleanup')
            ->daily()
            ->at('00:00')
            ->appendOutputTo(storage_path('logs/negotiations-cleanup.log'));

        // Verifica pagamentos em atraso diariamente às 8h
        $schedule->command('billing:check-overdue')
            ->dailyAt('08:00')
            ->appendOutputTo(storage_path('logs/billing-overdue.log'));

        // Process billing rules daily at midnight
        $schedule->command('billing:process-rules')
            ->daily()
            ->at('00:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Process health plan billing daily
        $schedule->command('billing:process-health-plans')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/billing.log'));

        // Process appointment attendance daily
        $schedule->command('appointments:process-attendance')
            ->dailyAt('23:00')
            ->withoutOverlapping();

        // Clean up old reports daily at midnight
        $schedule->command('reports:cleanup')->daily();

        // Clean up old notifications daily at 1 AM
        $schedule->command('notifications:cleanup')->dailyAt('01:00');

        // Generate scheduled reports daily at 2 AM
        $schedule->command('reports:generate-scheduled')->dailyAt('02:00');

        // Send notifications for scheduled reports daily at 2:30 AM
        $schedule->command('reports:send-notifications')->dailyAt('02:30');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 