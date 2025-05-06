<?php

namespace App\Providers;

use App\Services\Settings;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('settings', function ($app) {
            return new Settings();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Comandos Artisan para gerenciar configurações
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SettingsGet::class,
                \App\Console\Commands\SettingsSet::class,
                \App\Console\Commands\SettingsList::class,
                \App\Console\Commands\SettingsExport::class,
                \App\Console\Commands\SettingsImport::class,
            ]);
        }
    }
} 