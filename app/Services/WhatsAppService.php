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
            $conversations = $this->client->conversations->v1->conversations->read([
                'uniqueName' => "whatsapp_{$phone}"
            ]);

            return $conversations[0] ?? null;
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
     * Get messages from Twilio Conversation
     */
    public function getTwilioConversationMessages(string $conversationSid, int $limit = 50): array
    {
        try {
            $messages = $this->client->conversations->v1->conversations($conversationSid)
                ->messages->read([], $limit);

            $formattedMessages = [];
            foreach ($messages as $message) {
                $formattedMessages[] = [
                    'id' => $message->sid,
                    'content' => $message->body,
                    'direction' => $message->author === 'system' ? 'outbound' : 'inbound',
                    'status' => $message->delivery ? $message->delivery->status : 'sent',
                    'timestamp' => $message->dateCreated->format('Y-m-d H:i:s'),
                    'sender' => $message->author,
                    'conversation_sid' => $conversationSid,
                ];
            }

            return $formattedMessages;
        } catch (Exception $e) {
            Log::error('Failed to get Twilio conversation messages', [
                'conversation_sid' => $conversationSid,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get all conversations with latest message
     */
    public function getConversations(int $limit = 20): array
    {
        try {
            $conversations = $this->client->conversations->v1->conversations->read([], $limit);
            
            $formattedConversations = [];
            foreach ($conversations as $conversation) {
                // Get the latest message for this conversation
                $messages = $this->getTwilioConversationMessages($conversation->sid, 1);
                $latestMessage = $messages[0] ?? null;
                
                if ($latestMessage) {
                    // Extract phone from conversation unique name
                    $phone = str_replace('whatsapp_', '', $conversation->uniqueName);
                    
                    $formattedConversations[] = [
                        'conversation_sid' => $conversation->sid,
                        'phone' => $phone,
                        'latest_message' => $latestMessage,
                        'contact_info' => $this->identifySenderEntity($phone),
                        'created_at' => $conversation->dateCreated->format('Y-m-d H:i:s'),
                    ];
                }
            }

            // Sort by latest message timestamp
            usort($formattedConversations, function($a, $b) {
                return strtotime($b['latest_message']['timestamp']) - strtotime($a['latest_message']['timestamp']);
            });

            return array_slice($formattedConversations, 0, $limit);
        } catch (Exception $e) {
            Log::error('Failed to get conversations', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get conversation history for a specific phone
     */
    public function getConversationHistory(string $phone, int $limit = 50): array
    {
        try {
            $conversation = $this->findConversationByPhone($phone);
            
            if (!$conversation) {
                return [];
            }

            return $this->getTwilioConversationMessages($conversation->sid, $limit);
        } catch (Exception $e) {
            Log::error('Failed to get conversation history', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return [];
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