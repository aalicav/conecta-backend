<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WhatsAppService;
use App\Services\WhatsAppTemplateBuilder;
use App\Channels\WhatsAppChannel;
use Twilio\Rest\Client;

class WhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Register Twilio client
        $this->app->singleton(Client::class, function ($app) {
            $sid = config('services.twilio.account_sid');
            $token = config('services.twilio.auth_token');
            
            return new Client($sid, $token);
        });
        
        // Register WhatsApp template builder
        $this->app->singleton(WhatsAppTemplateBuilder::class, function ($app) {
            return new WhatsAppTemplateBuilder();
        });
        
        // Register WhatsApp service
        $this->app->singleton(WhatsAppService::class, function ($app) {
            return new WhatsAppService(
                $app->make(WhatsAppTemplateBuilder::class)
            );
        });
        
        // Register WhatsApp notification channel
        $this->app->singleton(WhatsAppChannel::class, function ($app) {
            return new WhatsAppChannel(
                $app->make(WhatsAppService::class),
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