<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * The Twilio client instance.
     *
     * @var \Twilio\Rest\Client
     */
    protected $twilio;

    /**
     * Create a new WhatsApp channel instance.
     *
     * @param  \Twilio\Rest\Client  $twilio
     * @return void
     */
    public function __construct(Client $twilio)
    {
        $this->twilio = $twilio;
    }

    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        if (!method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        // Skip if WhatsApp is disabled or phone number is missing
        if (empty($message) || !$this->canReceiveWhatsApp($notifiable)) {
            return;
        }

        $to = $this->getRecipientWhatsAppNumber($notifiable);
        $from = config('services.twilio.whatsapp_from');
        
        try {
            // Standard message
            if (is_string($message)) {
                $this->twilio->messages->create(
                    "whatsapp:$to",
                    [
                        'from' => "whatsapp:$from",
                        'body' => $message,
                    ]
                );
            } 
            // Template message
            else if (is_array($message) && isset($message['template'])) {
                $this->twilio->messages->create(
                    "whatsapp:$to",
                    [
                        'from' => "whatsapp:$from",
                        'contentSid' => $message['template'],
                        'contentVariables' => json_encode($message['variables'] ?? []),
                        'messagingServiceSid' => config('services.twilio.messaging_service_sid'),
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error('WhatsApp notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the phone number for the notification.
     *
     * @param  mixed  $notifiable
     * @return string|null
     */
    protected function getRecipientWhatsAppNumber($notifiable)
    {
        if ($notifiable->routeNotificationFor('whatsapp')) {
            return $notifiable->routeNotificationFor('whatsapp');
        }

        if (isset($notifiable->phone_number)) {
            return $notifiable->phone_number;
        }

        return null;
    }

    /**
     * Determine if the notifiable entity can receive WhatsApp messages.
     *
     * @param  mixed  $notifiable
     * @return bool
     */
    protected function canReceiveWhatsApp($notifiable)
    {
        return 
            !empty($this->getRecipientWhatsAppNumber($notifiable)) && 
            (!method_exists($notifiable, 'canReceiveWhatsApp') || $notifiable->canReceiveWhatsApp());
    }
} 