<?php

namespace App\Providers;

use App\Models\SystemSetting;
use App\Services\Settings;
use Illuminate\Support\ServiceProvider;

class SystemSettingServiceProvider extends ServiceProvider
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
        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SettingsSet::class,
                \App\Console\Commands\SettingsList::class,
            ]);
        }
        
        // Load all settings into cache on first request
        $this->app->booted(function () {
            if (!app()->runningInConsole() || app()->environment('testing')) {
                app('settings')->load();
            }
        });
    }
} 