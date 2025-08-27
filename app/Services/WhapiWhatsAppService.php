<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Phone;
use App\Models\Message;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class WhapiWhatsAppService
{
    protected $httpClient;
    protected $apiKey;
    protected $baseUrl;
    protected $webhookUrl;

    public function __construct()
    {
        $this->apiKey = config('whapi.api_key');
        $this->baseUrl = config('whapi.base_url');
        $this->webhookUrl = config('whapi.webhook_url');
        
        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => config('whapi.message_timeout', 30),
        ]);
    }

    /**
     * Send text message via Whapi
     */
    public function sendTextMessage(string $phone, string $message, array $options = []): array
    {
        try {
            $formattedPhone = $this->formatNumber($phone);
            
            $payload = [
                'to' => $formattedPhone,
                'text' => $message,
            ];

            // Add optional parameters
            if (isset($options['preview_url'])) {
                $payload['preview_url'] = $options['preview_url'];
            }

            Log::info('Sending WhatsApp message via Whapi', [
                'phone' => $phone,
                'formatted_phone' => $formattedPhone,
                'message' => $message,
                'options' => $options,
            ]);

            $response = $this->httpClient->post('/messages/text', [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            Log::info('Whapi API response', [
                'phone' => $phone,
                'response' => $responseData,
                'status_code' => $response->getStatusCode(),
            ]);

            // Save message to database
            $this->saveMessage($phone, $message, 'outbound', 'sent', $responseData['id'] ?? null);

            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? null,
                'response' => $responseData,
            ];

        } catch (GuzzleException $e) {
            Log::error('Failed to send WhatsApp message via Whapi', [
                'phone' => $phone,
                'message' => $message,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveMessage($phone, $message, 'outbound', 'failed');

            throw new Exception('Failed to send WhatsApp message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp message', [
                'phone' => $phone,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveMessage($phone, $message, 'outbound', 'failed');

            throw $e;
        }
    }

    /**
     * Send media message via Whapi
     */
    public function sendMediaMessage(string $phone, string $mediaUrl, string $mediaType, string $caption = '', array $options = []): array
    {
        try {
            $formattedPhone = $this->formatNumber($phone);
            
            $endpoint = $this->getMediaEndpoint($mediaType);
            
            $payload = [
                'to' => $formattedPhone,
                'url' => $mediaUrl,
            ];

            if (!empty($caption)) {
                $payload['caption'] = $caption;
            }

            // Add optional parameters
            if (isset($options['filename'])) {
                $payload['filename'] = $options['filename'];
            }

            Log::info('Sending WhatsApp media message via Whapi', [
                'phone' => $phone,
                'formatted_phone' => $formattedPhone,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'caption' => $caption,
                'endpoint' => $endpoint,
            ]);

            $response = $this->httpClient->post($endpoint, [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            Log::info('Whapi media API response', [
                'phone' => $phone,
                'response' => $responseData,
                'status_code' => $response->getStatusCode(),
            ]);

            // Save message to database
            $this->saveMessage($phone, $caption ?: "[{$mediaType}]", 'outbound', 'sent', $responseData['id'] ?? null, $mediaType, $mediaUrl);

            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? null,
                'response' => $responseData,
            ];

        } catch (GuzzleException $e) {
            Log::error('Failed to send WhatsApp media message via Whapi', [
                'phone' => $phone,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveMessage($phone, $caption ?: "[{$mediaType}]", 'outbound', 'failed', null, $mediaType, $mediaUrl);

            throw new Exception('Failed to send WhatsApp media message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp media message', [
                'phone' => $phone,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveMessage($phone, $caption ?: "[{$mediaType}]", 'outbound', 'failed', null, $mediaType, $mediaUrl);

            throw $e;
        }
    }

    /**
     * Send template message via Whapi
     */
    public function sendTemplateMessage(string $phone, string $templateName, array $parameters = []): array
    {
        try {
            $formattedPhone = $this->formatNumber($phone);
            
            $payload = [
                'to' => $formattedPhone,
                'template' => $templateName,
            ];

            if (!empty($parameters)) {
                $payload['parameters'] = $parameters;
            }

            Log::info('Sending WhatsApp template message via Whapi', [
                'phone' => $phone,
                'formatted_phone' => $formattedPhone,
                'template' => $templateName,
                'parameters' => $parameters,
            ]);

            $response = $this->httpClient->post('/messages/template', [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            Log::info('Whapi template API response', [
                'phone' => $phone,
                'response' => $responseData,
                'status_code' => $response->getStatusCode(),
            ]);

            // Save message to database
            $this->saveMessage($phone, "[Template: {$templateName}]", 'outbound', 'sent', $responseData['id'] ?? null);

            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? null,
                'response' => $responseData,
            ];

        } catch (GuzzleException $e) {
            Log::error('Failed to send WhatsApp template message via Whapi', [
                'phone' => $phone,
                'template' => $templateName,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveMessage($phone, "[Template: {$templateName}]", 'outbound', 'failed');

            throw new Exception('Failed to send WhatsApp template message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp template message', [
                'phone' => $phone,
                'template' => $templateName,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveMessage($phone, "[Template: {$templateName}]", 'outbound', 'failed');

            throw $e;
        }
    }

    /**
     * Process incoming message from webhook
     */
    public function processIncomingMessage(array $webhookData): void
    {
        try {
            Log::info('Processing incoming WhatsApp message from Whapi', $webhookData);

            // Extract message data from webhook
            $phone = $this->extractPhoneFromWebhook($webhookData);
            $content = $this->extractContentFromWebhook($webhookData);
            $messageId = $webhookData['id'] ?? null;
            $timestamp = $webhookData['timestamp'] ?? now();
            $type = $webhookData['type'] ?? 'text';

            if (!$phone || !$content) {
                Log::warning('Invalid webhook data - missing phone or content', $webhookData);
                return;
            }

            // Check if we already processed this message
            if ($messageId) {
                $existingMessage = Message::where('external_id', $messageId)->first();
                if ($existingMessage) {
                    Log::info('Message already processed, skipping', [
                        'message_id' => $messageId,
                        'phone' => $phone,
                    ]);
                    return;
                }
            }

            // Save incoming message to database
            $this->saveMessage($phone, $content, 'inbound', 'received', $messageId, $type);

            // Identify sender entity
            $senderEntity = $this->identifySenderEntity($phone);

            Log::info('Processed incoming WhatsApp message', [
                'phone' => $phone,
                'content' => $content,
                'message_id' => $messageId,
                'sender_entity' => $senderEntity,
            ]);

        } catch (Exception $e) {
            Log::error('Failed to process incoming WhatsApp message', [
                'webhook_data' => $webhookData,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get conversation history for a specific phone
     */
    public function getConversationHistory(string $phone, int $limit = 50, int $offset = 0): array
    {
        try {
            $messages = Message::where('recipient_phone', $phone)
                ->orWhere('sender_phone', $phone)
                ->orderBy('created_at', 'desc')
                ->skip($offset)
                ->take($limit)
                ->get();

            $total = Message::where('recipient_phone', $phone)
                ->orWhere('sender_phone', $phone)
                ->count();

            return [
                'messages' => $messages->map(function ($message) {
                    return [
                        'id' => $message->id,
                        'content' => $message->content,
                        'direction' => $message->direction,
                        'status' => $message->status,
                        'timestamp' => $message->created_at->format('Y-m-d H:i:s'),
                        'type' => $message->media_type,
                        'media_url' => $message->media_url,
                    ];
                })->toArray(),
                'pagination' => [
                    'total' => $total,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $total,
                ]
            ];

        } catch (Exception $e) {
            Log::error('Failed to get conversation history', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return [
                'messages' => [],
                'pagination' => [
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => false,
                ]
            ];
        }
    }

    /**
     * Get all conversations with latest message
     */
    public function getConversations(int $limit = 20): array
    {
        try {
            $conversations = Message::select('recipient_phone', 'sender_phone')
                ->selectRaw('MAX(created_at) as latest_message_at')
                ->groupBy('recipient_phone', 'sender_phone')
                ->orderBy('latest_message_at', 'desc')
                ->limit($limit)
                ->get();

            $formattedConversations = [];
            foreach ($conversations as $conversation) {
                $phone = $conversation->recipient_phone ?: $conversation->sender_phone;
                
                if ($phone) {
                    $latestMessage = Message::where('recipient_phone', $phone)
                        ->orWhere('sender_phone', $phone)
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($latestMessage) {
                        $formattedConversations[] = [
                            'phone' => $phone,
                            'latest_message' => [
                                'id' => $latestMessage->id,
                                'content' => $latestMessage->content,
                                'direction' => $latestMessage->direction,
                                'status' => $latestMessage->status,
                                'timestamp' => $latestMessage->created_at->format('Y-m-d H:i:s'),
                                'type' => $latestMessage->media_type,
                            ],
                            'contact_info' => $this->identifySenderEntity($phone),
                            'created_at' => $conversation->latest_message_at->format('Y-m-d H:i:s'),
                        ];
                    }
                }
            }

            return $formattedConversations;

        } catch (Exception $e) {
            Log::error('Failed to get conversations', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Send message to a phone number (alias for sendTextMessage)
     */
    public function sendMessage(string $phone, string $content): array
    {
        return $this->sendTextMessage($phone, $content);
    }

    /**
     * Identify sender entity by phone number
     */
    public function identifySenderEntity(string $phone)
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);

        // Search in Patient phones
        $patient = Patient::whereHas('phones', function ($query) use ($normalizedPhone) {
            $query->where('number', $normalizedPhone);
        })->first();

        if ($patient) {
            return [
                'type' => 'Patient',
                'id' => $patient->id,
                'name' => $patient->name,
                'entity' => $patient
            ];
        }

        // Search in Professional phones
        $professional = Professional::whereHas('phones', function ($query) use ($normalizedPhone) {
            $query->where('number', $normalizedPhone);
        })->first();

        if ($professional) {
            return [
                'type' => 'Professional',
                'id' => $professional->id,
                'name' => $professional->name,
                'entity' => $professional
            ];
        }

        // Search in Clinic phones
        $clinic = Clinic::whereHas('phones', function ($query) use ($normalizedPhone) {
            $query->where('number', $normalizedPhone);
        })->first();

        if ($clinic) {
            return [
                'type' => 'Clinic',
                'id' => $clinic->id,
                'name' => $clinic->name,
                'entity' => $clinic
            ];
        }

        return null;
    }

    /**
     * Get media endpoint based on media type
     */
    protected function getMediaEndpoint(string $mediaType): string
    {
        $endpoints = [
            'image' => '/messages/image',
            'video' => '/messages/video',
            'audio' => '/messages/audio',
            'document' => '/messages/document',
            'sticker' => '/messages/sticker',
        ];

        return $endpoints[$mediaType] ?? '/messages/media';
    }

    /**
     * Extract phone number from webhook data
     */
    protected function extractPhoneFromWebhook(array $webhookData): ?string
    {
        // Handle different webhook formats
        if (isset($webhookData['from'])) {
            return $this->normalizePhoneNumber($webhookData['from']);
        }

        if (isset($webhookData['contact'])) {
            return $this->normalizePhoneNumber($webhookData['contact']['wa_id'] ?? '');
        }

        return null;
    }

    /**
     * Extract content from webhook data
     */
    protected function extractContentFromWebhook(array $webhookData): ?string
    {
        if (isset($webhookData['text']['body'])) {
            return $webhookData['text']['body'];
        }

        if (isset($webhookData['message']['text'])) {
            return $webhookData['message']['text'];
        }

        if (isset($webhookData['body'])) {
            return $webhookData['body'];
        }

        return null;
    }

    /**
     * Save message to database
     */
    protected function saveMessage(string $phone, string $content, string $direction, string $status, ?string $externalId = null, ?string $mediaType = null, ?string $mediaUrl = null): void
    {
        try {
            $messageData = [
                'content' => $content,
                'direction' => $direction,
                'status' => $status,
                'media_type' => $mediaType,
                'media_url' => $mediaUrl,
                'external_id' => $externalId,
                'message_type' => $mediaType ? 'media' : 'text',
            ];

            if ($direction === 'outbound') {
                $messageData['sender_phone'] = config('whapi.from_number', 'system');
                $messageData['recipient_phone'] = $phone;
            } else {
                $messageData['sender_phone'] = $phone;
                $messageData['recipient_phone'] = config('whapi.from_number', 'system');
            }

            Message::create($messageData);

            Log::info('Message saved to database', $messageData);

        } catch (Exception $e) {
            Log::error('Failed to save message to database', [
                'phone' => $phone,
                'content' => $content,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Utility methods
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '0') {
            $phone = substr($phone, 1);
        }
        
        if (strlen($phone) === 10) {
            $phone = '55' . $phone;
        }
        
        return $phone;
    }

    public function formatNumber(string $phone): string
    {
        $phone = $this->normalizePhoneNumber($phone);
        return "+{$phone}";
    }

    /**
     * Legacy methods for backward compatibility
     */
    public function handleWebhook(array $webhookData): void
    {
        $this->processIncomingMessage($webhookData);
    }

    public function processAppointmentVerificationResponse(string $response, string $phone): void
    {
        Log::info('Processing appointment verification response', [
            'response' => $response,
            'phone' => $phone,
        ]);
    }

    // Alias methods for backward compatibility
    public function sendConversationMessage(string $phone, string $content, string $author = 'system'): array
    {
        return $this->sendTextMessage($phone, $content);
    }

    public function sendMessageViaConversations(string $phone, string $content): array
    {
        return $this->sendTextMessage($phone, $content);
    }

    public function getOrCreateConversation(string $phone): string
    {
        // For Whapi, we don't need conversations like Twilio
        // Return a unique identifier for backward compatibility
        return "whapi_conversation_{$phone}";
    }
}
