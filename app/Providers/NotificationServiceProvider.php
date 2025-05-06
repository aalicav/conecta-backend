<?php

namespace App\Providers;

use App\Console\Commands\SendAppointmentReminders;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Registrar comandos Artisan relacionados a notificações
        if ($this->app->runningInConsole()) {
            $this->commands([
                SendAppointmentReminders::class,
            ]);
        }
    }
} 