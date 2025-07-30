<?php

namespace App\Services;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Phone;
use Exception;

class WhatsAppService
{
    public $client;
    public $fromNumber;
    public $messagingServiceSid;
    public $templateBuilder;

    public function __construct()
    {
        $this->client = new Client(
            config('services.twilio.account_sid'),
            config('services.twilio.auth_token')
        );
        
        $this->fromNumber = config('services.twilio.whatsapp_from');
        $this->messagingServiceSid = config('services.twilio.messaging_service_sid');
    }

    /**
     * Get or create a Twilio Conversation for a phone number
     */
    public function getOrCreateConversation(string $phone): string
    {
        try {
            // First, try to find existing conversation
            $conversation = $this->findConversationByPhone($phone);
            
            if ($conversation) {
                return $conversation->sid;
            }

            // Create new conversation
            $conversation = $this->client->conversations->v1->conversations->create([
                'friendlyName' => "WhatsApp Chat - {$phone}",
                'uniqueName' => "whatsapp_{$phone}",
            ]);

            Log::info('Created new Twilio conversation', [
                'conversation_sid' => $conversation->sid,
                'phone' => $phone,
            ]);

            // Add WhatsApp participant
            $this->addWhatsAppParticipant($conversation->sid, $phone);

            return $conversation->sid;
        } catch (Exception $e) {
            Log::error('Failed to get or create conversation', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Find existing conversation by phone number
     */
    public function findConversationByPhone(string $phone): ?object
    {
        try {
            Log::info('Finding conversation by phone', [
                'phone' => $phone,
                'unique_name' => "whatsapp_{$phone}",
            ]);

            // First try to find by unique name
            $conversations = $this->client->conversations->v1->conversations->read([
                'uniqueName' => "whatsapp_{$phone}"
            ]);

            if (!empty($conversations)) {
                Log::info('Found conversation by unique name', [
                    'phone' => $phone,
                    'conversation_sid' => $conversations[0]->sid,
                ]);
                return $conversations[0];
            }

            // If not found by unique name, try to find by participants
            Log::info('Conversation not found by unique name, searching by participants', [
                'phone' => $phone,
            ]);

            $allConversations = $this->client->conversations->v1->conversations->read([], 100);
            
            foreach ($allConversations as $conversation) {
                try {
                    $participants = $this->client->conversations->v1->conversations($conversation->sid)
                        ->participants->read();
                    
                    foreach ($participants as $participant) {
                        if (isset($participant->messagingBinding) && 
                            isset($participant->messagingBinding['address'])) {
                            $address = $participant->messagingBinding['address'];
                            if (preg_match('/whatsapp:\+(\d+)/', $address, $matches)) {
                                $participantPhone = $matches[1];
                                if ($participantPhone === $phone) {
                                    Log::info('Found conversation by participant', [
                                        'phone' => $phone,
                                        'conversation_sid' => $conversation->sid,
                                    ]);
                                    return $conversation;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    Log::warning('Failed to check participants for conversation', [
                        'conversation_sid' => $conversation->sid,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            Log::warning('Conversation not found for phone', [
                'phone' => $phone,
            ]);
            return null;
        } catch (Exception $e) {
            Log::error('Failed to find conversation by phone', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Add WhatsApp participant to conversation
     */
    public function addWhatsAppParticipant(string $conversationSid, string $phone): void
    {
        try {
            $formattedPhone = $this->formatNumber($phone);

            // Use the messaging service SID if available, otherwise use the from number
            $proxyAddress = $this->messagingServiceSid
                ? "whatsapp:{$this->messagingServiceSid}"
                : $this->formatNumber($this->fromNumber);

            $participant = $this->client->conversations->v1->conversations($conversationSid)
                ->participants->create([
                    'messagingBinding.address' => $formattedPhone,
                    'messagingBinding.proxyAddress' => $proxyAddress,
                ]);

            Log::info('Added WhatsApp participant to conversation', [
                'conversation_sid' => $conversationSid,
                'participant_sid' => $participant->sid,
                'phone' => $phone,
                'proxy_address' => $proxyAddress,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to add WhatsApp participant', [
                'conversation_sid' => $conversationSid,
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send message via Twilio Conversations
     */
    public function sendConversationMessage(string $conversationSid, string $content, string $author = 'system'): object
    {
        try {
            $message = $this->client->conversations->v1->conversations($conversationSid)
                ->messages->create([
                    'author' => $author,
                    'body' => $content,
                ]);

            Log::info('Sent message via Twilio Conversations', [
                'conversation_sid' => $conversationSid,
                'message_sid' => $message->sid,
                'content' => $content,
            ]);

            return $message;
        } catch (Exception $e) {
            Log::error('Failed to send message via Conversations', [
                'conversation_sid' => $conversationSid,
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get messages from Twilio Conversation with pagination
     */
    public function getTwilioConversationMessages(string $conversationSid, int $limit = 50, string $pageToken = null): array
    {
        try {
            Log::info('Getting Twilio conversation messages', [
                'conversation_sid' => $conversationSid,
                'limit' => $limit,
                'page_token' => $pageToken,
            ]);

            $params = [];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            Log::info('Twilio API parameters', [
                'conversation_sid' => $conversationSid,
                'params' => $params,
                'limit' => $limit,
            ]);

            $messages = $this->client->conversations->v1->conversations($conversationSid)
                ->messages->read($params, $limit);

            Log::info('Twilio API response', [
                'conversation_sid' => $conversationSid,
                'messages_count' => count($messages),
                'messages' => array_map(function($msg) {
                    return [
                        'sid' => $msg->sid,
                        'body' => $msg->body,
                        'author' => $msg->author,
                        'date_created' => $msg->dateCreated->format('Y-m-d H:i:s'),
                    ];
                }, $messages),
            ]);

            $formattedMessages = [];
            foreach ($messages as $message) {
                // Debug delivery object
                Log::info('Message delivery object', [
                    'conversation_sid' => $conversationSid,
                    'message_sid' => $message->sid,
                    'delivery_type' => gettype($message->delivery),
                    'delivery_value' => $message->delivery,
                    'has_delivery' => isset($message->delivery),
                ]);
                
                // Safely get delivery status
                $deliveryStatus = 'sent';
                if (isset($message->delivery) && is_object($message->delivery) && isset($message->delivery->status)) {
                    $deliveryStatus = $message->delivery->status;
                }
                
                $formattedMessages[] = [
                    'id' => $message->sid,
                    'content' => $message->body,
                    'direction' => $message->author === 'system' ? 'outbound' : 'inbound',
                    'status' => $deliveryStatus,
                    'timestamp' => $message->dateCreated->format('Y-m-d H:i:s'),
                    'sender' => $message->author,
                    'conversation_sid' => $conversationSid,
                ];
            }

            // Get pagination info
            $hasMore = false;
            $nextPageToken = null;
            
            if (count($messages) === $limit) {
                // Check if there are more messages
                $nextPage = $this->client->conversations->v1->conversations($conversationSid)
                    ->messages->read(['pageToken' => end($messages)->sid], 1);
                $hasMore = count($nextPage) > 0;
                if ($hasMore) {
                    $nextPageToken = end($messages)->sid;
                }
            }

            $result = [
                'messages' => $formattedMessages,
                'pagination' => [
                    'has_more' => $hasMore,
                    'next_page_token' => $nextPageToken,
                    'total_count' => count($formattedMessages),
                    'limit' => $limit,
                ]
            ];

            Log::info('Formatted result', [
                'conversation_sid' => $conversationSid,
                'formatted_messages_count' => count($formattedMessages),
                'has_more' => $hasMore,
                'next_page_token' => $nextPageToken,
                'result' => $result,
            ]);

            return $result;
        } catch (Exception $e) {
            Log::error('Failed to get Twilio conversation messages', [
                'conversation_sid' => $conversationSid,
                'limit' => $limit,
                'page_token' => $pageToken,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'messages' => [],
                'pagination' => [
                    'has_more' => false,
                    'next_page_token' => null,
                    'total_count' => 0,
                    'limit' => $limit,
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
            Log::info('Getting conversations', ['limit' => $limit]);
            
            $conversations = $this->client->conversations->v1->conversations->read([], $limit);
            Log::info('Found conversations from Twilio', [
                'count' => count($conversations),
                'conversations' => array_map(function($conv) {
                    return [
                        'sid' => $conv->sid,
                        'unique_name' => $conv->uniqueName ?? 'null',
                        'friendly_name' => $conv->friendlyName ?? 'null',
                        'state' => $conv->state ?? 'null',
                    ];
                }, $conversations)
            ]);
            
            $formattedConversations = [];
            foreach ($conversations as $conversation) {
                Log::info('Processing conversation', [
                    'sid' => $conversation->sid,
                    'unique_name' => $conversation->uniqueName ?? 'null',
                    'friendly_name' => $conversation->friendlyName ?? 'null',
                ]);
                
                // Get the latest message for this conversation
                $result = $this->getTwilioConversationMessages($conversation->sid, 1);
                $latestMessage = $result['messages'][0] ?? null;
                
                Log::info('Latest message result', [
                    'conversation_sid' => $conversation->sid,
                    'has_latest_message' => !is_null($latestMessage),
                    'message_count' => count($result['messages']),
                    'latest_message' => $latestMessage,
                ]);
                
                if ($latestMessage) {
                    // Try to extract phone from conversation unique name first
                    $phone = str_replace('whatsapp_', '', $conversation->uniqueName);
                    Log::info('Phone from unique name', [
                        'conversation_sid' => $conversation->sid,
                        'unique_name' => $conversation->uniqueName,
                        'extracted_phone' => $phone,
                    ]);
                    
                    // If phone is empty, try to extract from the latest message sender
                    if (empty($phone) && isset($latestMessage['sender'])) {
                        $sender = $latestMessage['sender'];
                        // Extract phone from whatsapp:+558596345077 format
                        if (preg_match('/whatsapp:\+(\d+)/', $sender, $matches)) {
                            $phone = $matches[1];
                            Log::info('Phone extracted from sender', [
                                'conversation_sid' => $conversation->sid,
                                'sender' => $sender,
                                'extracted_phone' => $phone,
                            ]);
                        }
                    }
                    
                    // If still empty, try to get from conversation participants
                    if (empty($phone)) {
                        try {
                            $participants = $this->client->conversations->v1->conversations($conversation->sid)
                                ->participants->read();
                            
                            Log::info('Participants found', [
                                'conversation_sid' => $conversation->sid,
                                'participant_count' => count($participants),
                            ]);
                            
                            foreach ($participants as $participant) {
                                Log::info('Participant details', [
                                    'conversation_sid' => $conversation->sid,
                                    'participant_sid' => $participant->sid,
                                    'messaging_binding' => $participant->messagingBinding ?? 'null',
                                ]);
                                
                                if (isset($participant->messagingBinding) && 
                                    isset($participant->messagingBinding['address'])) {
                                    $address = $participant->messagingBinding['address'];
                                    if (preg_match('/whatsapp:\+(\d+)/', $address, $matches)) {
                                        $phone = $matches[1];
                                        Log::info('Phone extracted from participant', [
                                            'conversation_sid' => $conversation->sid,
                                            'address' => $address,
                                            'extracted_phone' => $phone,
                                        ]);
                                        break;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            Log::warning('Failed to get participants for phone extraction', [
                                'conversation_sid' => $conversation->sid,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    
                    Log::info('Final phone extraction', [
                        'conversation_sid' => $conversation->sid,
                        'phone' => $phone,
                        'will_add_to_list' => !empty($phone),
                    ]);
                    
                    if (!empty($phone)) {
                        $formattedConversations[] = [
                            'conversation_sid' => $conversation->sid,
                            'phone' => $phone,
                            'latest_message' => $latestMessage,
                            'contact_info' => $this->identifySenderEntity($phone),
                            'created_at' => $conversation->dateCreated->format('Y-m-d H:i:s'),
                        ];
                    }
                }
            }

            Log::info('Formatted conversations', [
                'count' => count($formattedConversations),
                'conversations' => $formattedConversations,
            ]);

            // Sort by latest message timestamp
            usort($formattedConversations, function($a, $b) {
                return strtotime($b['latest_message']['timestamp']) - strtotime($a['latest_message']['timestamp']);
            });

            return array_slice($formattedConversations, 0, $limit);
        } catch (Exception $e) {
            Log::error('Failed to get conversations', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    /**
     * Get conversation history for a specific phone with pagination
     */
    public function getConversationHistory(string $phone, int $limit = 50, string $pageToken = null): array
    {
        try {
            Log::info('Getting conversation history', [
                'phone' => $phone,
                'limit' => $limit,
                'page_token' => $pageToken,
            ]);

            $conversation = $this->findConversationByPhone($phone);
            
            if (!$conversation) {
                Log::warning('Conversation not found for phone', [
                    'phone' => $phone,
                ]);
                return [
                    'messages' => [],
                    'pagination' => [
                        'has_more' => false,
                        'next_page_token' => null,
                        'total_count' => 0,
                        'limit' => $limit,
                    ]
                ];
            }

            Log::info('Found conversation', [
                'phone' => $phone,
                'conversation_sid' => $conversation->sid,
            ]);

            $result = $this->getTwilioConversationMessages($conversation->sid, $limit, $pageToken);
            
            Log::info('Retrieved messages', [
                'phone' => $phone,
                'message_count' => count($result['messages']),
                'has_more' => $result['pagination']['has_more'],
            ]);

            return $result;
        } catch (Exception $e) {
            Log::error('Failed to get conversation history', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return [
                'messages' => [],
                'pagination' => [
                    'has_more' => false,
                    'next_page_token' => null,
                    'total_count' => 0,
                    'limit' => $limit,
                ]
            ];
        }
    }

    /**
     * Send message to a phone number
     */
    public function sendMessageViaConversations(string $phone, string $content): object
    {
        try {
            $conversationSid = $this->getOrCreateConversation($phone);
            return $this->sendConversationMessage($conversationSid, $content, 'system');
        } catch (Exception $e) {
            Log::error('Failed to send message via Conversations', [
                'phone' => $phone,
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process incoming message from webhook
     */
    public function processIncomingMessage(string $phone, string $content, array $metadata = []): void
    {
        try {
            $messageId = $metadata['message_id'] ?? null;
            
            // Check if we already processed this message
            if ($messageId) {
                $cacheKey = "processed_message_{$messageId}";
                if (cache()->has($cacheKey)) {
                    Log::info('Message already processed, skipping', [
                        'message_id' => $messageId,
                        'phone' => $phone,
                    ]);
                    return;
                }
                
                // Mark as processed for 5 minutes
                cache()->put($cacheKey, true, 300);
            }
            
            $conversationSid = $this->getOrCreateConversation($phone);
            
            // Send the incoming message to the conversation
            $this->sendConversationMessage($conversationSid, $content, $phone);
            
            Log::info('Processed incoming message via Conversations', [
                'phone' => $phone,
                'conversation_sid' => $conversationSid,
                'content' => $content,
                'metadata' => $metadata,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to process incoming message', [
                'phone' => $phone,
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
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
     * Setup webhook for conversation
     */
    public function setupConversationWebhook(string $conversationSid, string $webhookUrl): object
    {
        try {
            $webhook = $this->client->conversations->v1->conversations($conversationSid)
                ->webhooks->create([
                    'configuration.url' => $webhookUrl,
                    'configuration.filters' => ['onMessageAdded'],
                ]);

            Log::info('Setup conversation webhook', [
                'conversation_sid' => $conversationSid,
                'webhook_url' => $webhookUrl,
                'webhook_sid' => $webhook->sid,
            ]);

            return $webhook;
        } catch (Exception $e) {
            Log::error('Failed to setup conversation webhook', [
                'conversation_sid' => $conversationSid,
                'webhook_url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // Utility methods
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
        return "whatsapp:+{$phone}";
    }

    // Legacy methods for backward compatibility
    public function handleWebhook(array $webhookData): void
    {
        // Handle status updates and other webhook data
        Log::info('Handling webhook data', $webhookData);
    }

    public function processAppointmentVerificationResponse(string $response, string $phone): void
    {
        // Handle appointment verification responses
        Log::info('Processing appointment verification response', [
            'response' => $response,
            'phone' => $phone,
        ]);
    }
}