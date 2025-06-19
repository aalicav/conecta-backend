<?php

namespace App\Notifications\Channels;

use App\Services\WhatsAppService;
use App\Notifications\Messages\WhatsAppMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    protected WhatsAppService $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
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
            Log::warning('toWhatsApp method not defined on notification: ' . get_class($notification));
            return;
        }

        /** @var WhatsAppMessage|null $message */
        $message = $notification->toWhatsApp($notifiable);

        if (!$message instanceof WhatsAppMessage) {
            // This is expected when user doesn't have a phone number - log as info instead of warning
            if ($message === null) {
                Log::info('WhatsApp notification skipped - no phone number available', [
                    'notification' => get_class($notification),
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? 'unknown'
                ]);
            } else {
                Log::warning('toWhatsApp method did not return a WhatsAppMessage instance for notification: ' . get_class($notification), [
                    'returned_type' => gettype($message),
                    'notifiable_type' => get_class($notifiable),
                    'notifiable_id' => $notifiable->id ?? 'unknown'
                ]);
            }
            return;
        }

        if (empty($message->recipientPhone)) {
            if (method_exists($notifiable, 'routeNotificationFor') && $notifiable->routeNotificationFor('whatsapp')) {
                $message->to($notifiable->routeNotificationFor('whatsapp'));
            } elseif (isset($notifiable->phone)) {
                $message->to($notifiable->phone); // Fallback to a generic 'phone' attribute
            }
        }

        if (empty($message->recipientPhone)) {
            Log::info('WhatsApp notification skipped - no recipient phone number', [
                'notification' => get_class($notification),
                'notifiable_type' => get_class($notifiable),
                'notifiable_id' => $notifiable->id ?? 'unknown'
            ]);
            return;
        }
        
        try {
            // Prepare payload for the sendFromTemplate method
            $payload = [
                'template' => $message->templateName,
                'to' => $message->recipientPhone,
                'variables' => $message->getVariables()
            ];
            
            // Delegate to the actual WhatsAppService to send the templated message
            $this->whatsAppService->sendFromTemplate($payload);
            
            Log::info('WhatsApp notification sent via WhatsAppChannel', [
                'notification' => get_class($notification),
                'recipient' => $message->recipientPhone,
                'template' => $message->templateName
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp notification via WhatsAppChannel', [
                'error' => $e->getMessage(),
                'notification' => get_class($notification),
                'recipient' => $message->recipientPhone,
                'template' => $message->templateName
            ]);
        }
    }
} 