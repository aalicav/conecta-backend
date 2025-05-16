<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\CleanAuditLogs;
use App\Console\Commands\CheckMailConfig;
use App\Console\Commands\SendTestMail;

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
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run the audit log cleanup command weekly
        $schedule->command('audit:clean')->weekly()->sundays()->at('01:00');
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