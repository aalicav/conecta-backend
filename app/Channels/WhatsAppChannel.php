<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use App\Services\WhatsAppService;
use App\Services\WhatsAppTemplateBuilder;
use Illuminate\Support\Facades\Log;

class WhatsAppChannel
{
    /**
     * The WhatsApp service instance.
     *
     * @var \App\Services\WhatsAppService
     */
    protected $whatsAppService;

    /**
     * The WhatsApp template builder instance.
     *
     * @var \App\Services\WhatsAppTemplateBuilder
     */
    protected $templateBuilder;

    /**
     * Create a new WhatsApp channel instance.
     *
     * @param  \App\Services\WhatsAppService  $whatsAppService
     * @param  \App\Services\WhatsAppTemplateBuilder  $templateBuilder
     * @return void
     */
    public function __construct(WhatsAppService $whatsAppService, WhatsAppTemplateBuilder $templateBuilder)
    {
        $this->whatsAppService = $whatsAppService;
        $this->templateBuilder = $templateBuilder;
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
        if (!$to = $notifiable->routeNotificationFor('whatsapp', $notification)) {
            return;
        }

        try {
            $whatsappMessage = $notification->toWhatsApp($notifiable);
            
            // If message is null or invalid, don't send anything
            if (!$whatsappMessage) {
                return;
            }

            // Handle different types of WhatsApp messages
            if (is_string($whatsappMessage)) {
                // Simple text message (backward compatibility)
                $message = $this->whatsAppService->sendMessage($to, $whatsappMessage);
                
                if ($message) {
                    Log::info('WhatsApp text notification sent', [
                        'to' => $to,
                        'notification' => get_class($notification),
                        'message_sid' => $message->sid
                    ]);
                }
            } elseif (is_array($whatsappMessage)) {
                if (isset($whatsappMessage['template'])) {
                    // Template message with specific template name and variables
                    $payload = [
                        'to' => $to,
                        'template' => $whatsappMessage['template'],
                        'variables' => $whatsappMessage['variables'] ?? []
                    ];
                    
                    $message = $this->whatsAppService->sendFromTemplate($payload);
                    
                    if ($message) {
                        Log::info('WhatsApp template notification sent', [
                            'to' => $to,
                            'template' => $whatsappMessage['template'],
                            'notification' => get_class($notification),
                            'message_sid' => $message->sid
                        ]);
                    }
                } elseif (isset($whatsappMessage['content'])) {
                    // Simple text message as array (backward compatibility)
                    $message = $this->whatsAppService->sendMessage($to, $whatsappMessage['content']);
                    
                    if ($message) {
                        Log::info('WhatsApp text notification sent', [
                            'to' => $to,
                            'notification' => get_class($notification),
                            'message_sid' => $message->sid
                        ]);
                    }
                } else {
                    Log::error('Invalid WhatsApp message format', [
                        'notification' => get_class($notification),
                        'message' => $whatsappMessage
                    ]);
                }
            } else {
                Log::error('Invalid WhatsApp message type', [
                    'notification' => get_class($notification),
                    'type' => gettype($whatsappMessage)
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp notification', [
                'to' => $to,
                'notification' => get_class($notification),
                'error' => $e->getMessage()
            ]);
            // Just log the error, don't rethrow
        }
    }
} 