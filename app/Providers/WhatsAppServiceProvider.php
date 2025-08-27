<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WhapiWhatsAppService;
use App\Services\WhatsAppTemplateBuilder;
use App\Notifications\Channels\WhatsAppChannel;

class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register WhatsApp template builder
        $this->app->singleton(WhatsAppTemplateBuilder::class, function ($app) {
            return new WhatsAppTemplateBuilder();
        });
        
        // Register Whapi WhatsApp service
        $this->app->singleton(WhapiWhatsAppService::class, function ($app) {
            return new WhapiWhatsAppService();
        });
        
        // Register WhatsApp notification channel
        $this->app->singleton(WhatsAppChannel::class, function ($app) {
            return new WhatsAppChannel(
                $app->make(WhapiWhatsAppService::class),
                $app->make(WhatsAppTemplateBuilder::class)
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
} 