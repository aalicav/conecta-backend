<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Support\Facades\Notification;
use Twilio\Rest\Client;
use App\Providers\WhatsAppServiceProvider;
use App\Models\Negotiation;
use App\Observers\NegotiationObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register WhatsApp service provider
        $this->app->register(WhatsAppServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register WhatsApp notification channel
        Notification::extend('whatsapp', function ($app) {
            return new WhatsAppChannel(
                new Client(
                    config('services.twilio.account_sid'),
                    config('services.twilio.auth_token')
                )
            );
        });

        Negotiation::observe(NegotiationObserver::class);
    }
}
