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
            Log::warning('toWhatsApp method did not return a WhatsAppMessage instance for notification: ' . get_class($notification));
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
            Log::warning('No recipient phone number for WhatsApp notification: ' . get_class($notification) . ' to ' . get_class($notifiable));
            return;
        }
        
        try {
            // Delegate to the actual WhatsAppService to send the templated message
            $this->whatsAppService->sendTemplateMessage(
                $message->recipientPhone,
                $message->templateName,
                $message->variables
            );
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