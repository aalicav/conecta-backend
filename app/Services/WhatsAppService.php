<?php

namespace App\Services;

use Twilio\Rest\Client;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\HealthPlan;
use App\Models\Appointment;
use App\Models\WhatsappMessage;
use App\Models\Message;
use App\Models\Clinic;
use Carbon\Carbon;
use App\Models\Negotiation;
use App\Models\User;

class WhatsAppService
{
    /**
     * The Twilio client instance.
     *
     * @var \Twilio\Rest\Client
     */
    public $client;

    /**
     * The WhatsApp number that messages will be sent from.
     *
     * @var string
     */
    public $fromNumber;

    /**
     * The messaging service SID for Twilio.
     *
     * @var string|null
     */
    public $messagingServiceSid;

    /**
     * Template SIDs
     */
    const TEMPLATE_NPS_SURVEY_PRESTADOR = 'HX88462651cfd565442071b4be9e4020df';
    const TEMPLATE_AGENDAMENTO_CANCELADO = 'HX1169ac440fccef19a84caa272fbea431';
    const TEMPLATE_AGENDAMENTO_CONFIRMADO = 'HXb9e03531bd86c7978cd63432acd803ee';
    const TEMPLATE_AGENDAMENTO_CLIENTE = 'HXbb4f49e248375328385ae8063db616b7';
    const TEMPLATE_NPS_PERGUNTA = 'HX54700067ab72934ecc4c39e855281a83';
    const TEMPLATE_NPS_SURVEY = 'HX936970d99660f9bc527eede3c57b646a';
    const TEMPLATE_COPY_MENSAGEM_OPERADORA = 'HX933d81b51955fd09e063b959e3b7007e';
    const TEMPLATE_NEGOTIATION_CREATED = 'HXac200d96a677a800c1cf940884d25457';
    const TEMPLATE_NEW_PROFESSIONAL = 'HX537cd859a24a94b9bb7dcf8f87705ea9';
    const TEMPLATE_DISPONIBILIDADE_PRESTADOR = 'HX2620646c83edaf69913512bf5f1314c9';
    const TEMPLATE_CONFIRMACAO_ATENDIMENTO = 'SID_A_SER_INSERIDO_CONFIRMACAO_ATENDIMENTO';
    const TEMPLATE_PAGAMENTO_REALIZADO = 'SID_A_SER_INSERIDO_PAGAMENTO_REALIZADO';
    const TEMPLATE_LEMBRETE_NOTA_FISCAL = 'SID_A_SER_INSERIDO_LEMBRETE_NOTA_FISCAL';
    const TEMPLATE_TAREFA_CRITICA = 'SID_A_SER_INSERIDO_TAREFA_CRITICA';
    const TEMPLATE_APROVACAO_PENDENTE = 'SID_A_SER_INSERIDO_APROVACAO_PENDENTE';
    const TEMPLATE_PACIENTE_AUSENTE = 'SID_A_SER_INSERIDO_PACIENTE_AUSENTE';
    const TEMPLATE_PREPARO_EXAME = 'SID_A_SER_INSERIDO_PREPARO_EXAME';
    const TEMPLATE_APPOINTMENT_VERIFICATION = 'HXee84e7c3ced3df6028ae1ede67b0d312';
    const TEMPLATE_AVAILABILITY_REJECTED = 'HX74614ebbe1cc652ab7fbcf4149642507';
    const TEMPLATE_HEALTH_PLAN_AVAILABILITY_SELECTED = 'HX2e5ac98635e2c89457b475e0c67645f3';

    const TEMPLATE_SOLICITATION_INVITE = '';
    
    /**
     * The template builder instance.
     *
     * @var \App\Services\WhatsAppTemplateBuilder
     */
    public $templateBuilder;

    /**
     * Create a new WhatsApp service instance.
     *
     * @param WhatsAppTemplateBuilder|null $templateBuilder
     * @return void
     */
    public function __construct(WhatsAppTemplateBuilder $templateBuilder = null)
    {
        $sid = config('services.twilio.account_sid');
        $token = config('services.twilio.auth_token');
        $fromNumber = config('services.twilio.whatsapp_from');
        $messagingServiceSid = config('services.twilio.messaging_service_sid');

        if (!$sid || !$token) {
            throw new Exception('Twilio credentials not configured');
        }

        $this->client = new Client($sid, $token);
        $this->fromNumber = $fromNumber;
        $this->messagingServiceSid = $messagingServiceSid;
        $this->templateBuilder = $templateBuilder ?? new WhatsAppTemplateBuilder();
    }

    /**
     * Create or get a conversation for a phone number
     *
     * @param string $phone Phone number
     * @return string Conversation SID
     */
    public function getOrCreateConversation(string $phone): string
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);
        
        // Check if conversation already exists for this phone
        $existingConversation = $this->findConversationByPhone($normalizedPhone);
        
        if ($existingConversation) {
            return $existingConversation->sid;
        }

        // Create new conversation
        $conversation = $this->client->conversations->v1->conversations->create([
            'friendlyName' => "Conversa com {$normalizedPhone}",
            'uniqueName' => "whatsapp_{$normalizedPhone}",
        ]);

        // Add WhatsApp participant
        $this->addWhatsAppParticipant($conversation->sid, $normalizedPhone);

        Log::info('Created new Twilio conversation', [
            'conversation_sid' => $conversation->sid,
            'phone' => $normalizedPhone,
        ]);

        return $conversation->sid;
    }

    /**
     * Add WhatsApp participant to conversation
     *
     * @param string $conversationSid
     * @param string $phone
     * @return void
     */
    public function addWhatsAppParticipant(string $conversationSid, string $phone): void
    {
        try {
            $formattedPhone = $this->formatNumber($phone);
            
            $participant = $this->client->conversations->v1->conversations($conversationSid)
                ->participants->create([
                    'messagingBinding.address' => $formattedPhone,
                    'messagingBinding.proxyAddress' => $this->formatNumber($this->fromNumber),
                ]);

            Log::info('Added WhatsApp participant to conversation', [
                'conversation_sid' => $conversationSid,
                'participant_sid' => $participant->sid,
                'phone' => $phone,
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
     * Find conversation by phone number
     *
     * @param string $phone
     * @return object|null
     */
    public function findConversationByPhone(string $phone): ?object
    {
        try {
            $conversations = $this->client->conversations->v1->conversations->read([
                'uniqueName' => "whatsapp_{$phone}",
            ]);

            return !empty($conversations) ? $conversations[0] : null;
        } catch (Exception $e) {
            Log::warning('Failed to find conversation by phone', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Send message through Twilio Conversations
     *
     * @param string $conversationSid
     * @param string $content
     * @param string $author
     * @return object
     */
    public function sendConversationMessage(string $conversationSid, string $content, string $author = 'system'): object
    {
        try {
            $message = $this->client->conversations->v1->conversations($conversationSid)
                ->messages->create([
                    'author' => $author,
                    'body' => $content,
                ]);

            Log::info('Sent conversation message', [
                'conversation_sid' => $conversationSid,
                'message_sid' => $message->sid,
                'author' => $author,
            ]);

            return $message;
        } catch (Exception $e) {
            Log::error('Failed to send conversation message', [
                'conversation_sid' => $conversationSid,
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process incoming WhatsApp message from webhook using Conversations
     *
     * @param string $from Phone number of sender
     * @param string $content Message content
     * @param array $metadata Additional metadata
     * @return Message
     */
    public function processIncomingMessage(string $from, string $content, array $metadata = [])
    {
        // Normalize phone number
        $normalizedFrom = $this->normalizePhoneNumber($from);
        
        // Get or create conversation
        $conversationSid = $this->getOrCreateConversation($normalizedFrom);
        
        // Create message record
        $message = Message::create([
            'sender_phone' => $normalizedFrom,
            'recipient_phone' => $this->fromNumber,
            'content' => $content,
            'direction' => Message::DIRECTION_INBOUND,
            'status' => Message::STATUS_DELIVERED, // Incoming messages are considered delivered
            'message_type' => Message::TYPE_TEXT,
            'metadata' => array_merge($metadata, [
                'conversation_sid' => $conversationSid,
                'twilio_conversations' => true,
            ]),
            'delivered_at' => now(),
        ]);

        Log::info('Incoming WhatsApp message processed via Conversations', [
            'message_id' => $message->id,
            'from' => $normalizedFrom,
            'content' => $content,
            'conversation_sid' => $conversationSid,
        ]);

        // Try to identify the sender entity
        $senderEntity = $this->identifySenderEntity($normalizedFrom);
        if ($senderEntity) {
            $message->update([
                'related_model_type' => get_class($senderEntity),
                'related_model_id' => $senderEntity->id,
            ]);
        }

        return $message;
    }

    /**
     * Send manual message using Twilio Conversations
     *
     * @param string $to Recipient phone number
     * @param string $content Message content
     * @param string|null $relatedModelType Related model type
     * @param int|null $relatedModelId Related model ID
     * @return Message
     */
    public function sendManualMessage(
        string $to, 
        string $content, 
        ?string $relatedModelType = null, 
        ?int $relatedModelId = null
    ) {
        // Normalize phone number
        $normalizedTo = $this->normalizePhoneNumber($to);
        
        // Get or create conversation
        $conversationSid = $this->getOrCreateConversation($normalizedTo);
        
        // Create message record
        $message = Message::create([
            'sender_phone' => $this->fromNumber,
            'recipient_phone' => $normalizedTo,
            'content' => $content,
            'direction' => Message::DIRECTION_OUTBOUND,
            'status' => Message::STATUS_PENDING,
            'message_type' => Message::TYPE_TEXT,
            'related_model_type' => $relatedModelType,
            'related_model_id' => $relatedModelId,
            'metadata' => [
                'conversation_sid' => $conversationSid,
                'twilio_conversations' => true,
            ],
        ]);

        try {
            // Send message through Conversations
            $twilioMessage = $this->sendConversationMessage($conversationSid, $content, 'system');
            
            // Update the message status
            $message->update([
                'status' => Message::STATUS_SENT,
                'external_id' => $twilioMessage->sid,
                'sent_at' => now(),
            ]);
            
            Log::info('Manual WhatsApp message sent via Conversations', [
                'message_id' => $message->id,
                'to' => $normalizedTo,
                'conversation_sid' => $conversationSid,
                'twilio_message_sid' => $twilioMessage->sid,
            ]);
        } catch (Exception $e) {
            // Update the message with error information
            $message->update([
                'status' => Message::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to send manual WhatsApp message via Conversations', [
                'message_id' => $message->id,
                'to' => $normalizedTo,
                'conversation_sid' => $conversationSid,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }

    /**
     * Get conversation history for a phone number
     *
     * @param string $phone Phone number
     * @param int $limit Number of messages to return
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getConversationHistory(string $phone, int $limit = 50)
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);
        
        return Message::where(function ($query) use ($normalizedPhone) {
            $query->where('sender_phone', $normalizedPhone)
                  ->orWhere('recipient_phone', $normalizedPhone);
        })
        ->orderBy('created_at', 'asc')
        ->limit($limit)
        ->get();
    }

    /**
     * Get all conversations (grouped by phone number)
     *
     * @param int $limit Number of conversations to return
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getConversations(int $limit = 20)
    {
        // Get the latest message from each conversation by grouping by sender/recipient phone
        $conversations = Message::select('*')
            ->whereIn('id', function ($query) {
                $query->selectRaw('MAX(id)')
                      ->from('messages')
                      ->groupBy('sender_phone', 'recipient_phone');
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $conversations;
    }

    /**
     * Get Twilio conversation messages
     *
     * @param string $conversationSid
     * @param int $limit
     * @return array
     */
    public function getTwilioConversationMessages(string $conversationSid, int $limit = 50): array
    {
        try {
            $messages = $this->client->conversations->v1->conversations($conversationSid)
                ->messages->read([], $limit);

            return array_map(function ($message) {
                return [
                    'sid' => $message->sid,
                    'author' => $message->author,
                    'body' => $message->body,
                    'dateCreated' => $message->dateCreated,
                    'dateUpdated' => $message->dateUpdated,
                ];
            }, $messages);
        } catch (Exception $e) {
            Log::error('Failed to get Twilio conversation messages', [
                'conversation_sid' => $conversationSid,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Set up webhook for conversation
     *
     * @param string $conversationSid
     * @param string $webhookUrl
     * @return object
     */
    public function setupConversationWebhook(string $conversationSid, string $webhookUrl): object
    {
        try {
            $webhook = $this->client->conversations->v1->conversations($conversationSid)
                ->webhooks->create([
                    'target' => 'webhook',
                    'configuration.url' => $webhookUrl,
                    'configuration.method' => 'POST',
                    'configuration.filters' => [
                        'onMessageAdded',
                        'onConversationUpdated',
                    ],
                ]);

            Log::info('Setup conversation webhook', [
                'conversation_sid' => $conversationSid,
                'webhook_sid' => $webhook->sid,
                'url' => $webhookUrl,
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

    /**
     * Identify the entity that sent a message based on phone number
     *
     * @param string $phone Phone number
     * @return Patient|Professional|Clinic|null
     */
    public function identifySenderEntity(string $phone)
    {
        // Check if it's a patient
        $patient = Patient::where('phone', $phone)->first();
        if ($patient) {
            return $patient;
        }

        // Check if it's a professional
        $professional = Professional::where('phone', $phone)->first();
        if ($professional) {
            return $professional;
        }

        // Check if it's a clinic
        $clinic = Clinic::where('phone', $phone)->first();
        if ($clinic) {
            return $clinic;
        }

        return null;
    }

    /**
     * Update message status from webhook for bidirectional messages
     *
     * @param string $externalId External message ID
     * @param string $status New status
     * @param array $metadata Additional metadata
     * @return Message|null
     */
    public function updateBidirectionalMessageStatus(string $externalId, string $status, array $metadata = [])
    {
        $message = Message::where('external_id', $externalId)->first();
        
        if (!$message) {
            Log::warning('Message not found for external ID', ['external_id' => $externalId]);
            return null;
        }

        $updateData = ['status' => $status];

        // Update timestamps based on status
        if ($status === Message::STATUS_SENT && !$message->sent_at) {
            $updateData['sent_at'] = now();
        } else if ($status === Message::STATUS_DELIVERED && !$message->delivered_at) {
            $updateData['delivered_at'] = now();
        } else if ($status === Message::STATUS_READ && !$message->read_at) {
            $updateData['read_at'] = now();
        } else if ($status === Message::STATUS_FAILED) {
            $updateData['error_message'] = $metadata['error_message'] ?? 'Message delivery failed';
        }

        $message->update($updateData);
        
        Log::info('Updated bidirectional message status', [
            'message_id' => $message->id,
            'status' => $status,
            'external_id' => $externalId,
        ]);

        return $message;
    }

    /**
     * Send a text WhatsApp message and save it to the database.
     *
     * @param  string  $to The recipient's phone number (with country code, no + prefix)
     * @param  string  $message The message to send
     * @param  string|null  $relatedModelType The type of related model
     * @param  int|null  $relatedModelId The ID of related model
     * @return \App\Models\WhatsappMessage
     */
    public function sendTextMessage(string $to, string $message, $relatedModelType = null, $relatedModelId = null)
    {
        // Create a record in the database first
        $whatsappMessage = WhatsappMessage::create([
            'recipient' => $to,
            'content' => $message,
            'status' => WhatsappMessage::STATUS_PENDING,
            'related_model_type' => $relatedModelType,
            'related_model_id' => $relatedModelId,
        ]);

        try {
            $formattedTo = $this->formatNumber($to);
            $formattedFrom = $this->formatNumber($this->fromNumber);

            $messageParams = [
                'from' => $formattedFrom,
                'body' => $message,
            ];

            // Add messaging service SID if configured
            if ($this->messagingServiceSid) {
                $messageParams['messagingServiceSid'] = $this->messagingServiceSid;
            }

            $result = $this->client->messages->create($formattedTo, $messageParams);
            
            // Update the message status
            $whatsappMessage->update([
                'status' => WhatsappMessage::STATUS_SENT,
                'external_id' => $result->sid,
                'sent_at' => now(),
            ]);
            
            Log::info('WhatsApp message sent', [
                'to' => $to,
                'message_sid' => $result->sid,
                'status' => $result->status,
            ]);
        } catch (Exception $e) {
            // Update the message with error information
            $whatsappMessage->update([
                'status' => WhatsappMessage::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to send WhatsApp message', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);
        }

        return $whatsappMessage;
    }

    /**
     * Send a media WhatsApp message and save it to the database.
     *
     * @param  string  $to The recipient's phone number (with country code, no + prefix)
     * @param  string  $mediaUrl The URL of the media to send
     * @param  string  $mediaType The type of media (image, document, video, audio)
     * @param  string|null  $caption Optional caption for the media
     * @param  string|null  $relatedModelType The type of related model
     * @param  int|null  $relatedModelId The ID of related model
     * @return \App\Models\WhatsappMessage
     */
    public function sendMediaMessage(
        string $to, 
        string $mediaUrl, 
        string $mediaType, 
        ?string $caption = null,
        $relatedModelType = null, 
        $relatedModelId = null
    ) {
        // Create a record in the database first
        $whatsappMessage = WhatsappMessage::create([
            'recipient' => $to,
            'content' => $caption,
            'media_url' => $mediaUrl,
            'status' => WhatsappMessage::STATUS_PENDING,
            'related_model_type' => $relatedModelType,
            'related_model_id' => $relatedModelId,
        ]);

        try {
            $formattedTo = $this->formatNumber($to);
            $formattedFrom = $this->formatNumber($this->fromNumber);

            $messageParams = [
                'from' => $formattedFrom,
                'mediaUrl' => [$mediaUrl],
            ];

            // Add caption if provided
            if ($caption) {
                $messageParams['body'] = $caption;
            }

            // Add messaging service SID if configured
            if ($this->messagingServiceSid) {
                $messageParams['messagingServiceSid'] = $this->messagingServiceSid;
            }

            $result = $this->client->messages->create($formattedTo, $messageParams);
            
            // Update the message status
            $whatsappMessage->update([
                'status' => WhatsappMessage::STATUS_SENT,
                'external_id' => $result->sid,
                'sent_at' => now(),
            ]);
            
            Log::info('WhatsApp media message sent', [
                'to' => $to,
                'media_url' => $mediaUrl,
                'message_sid' => $result->sid,
                'status' => $result->status,
            ]);
        } catch (Exception $e) {
            // Update the message with error information
            $whatsappMessage->update([
                'status' => WhatsappMessage::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to send WhatsApp media message', [
                'to' => $to,
                'media_url' => $mediaUrl,
                'error' => $e->getMessage(),
            ]);
        }

        return $whatsappMessage;
    }

    /**
     * Resend a failed WhatsApp message
     *
     * @param  \App\Models\WhatsappMessage  $message
     * @return \App\Models\WhatsappMessage
     */
    public function resendMessage(WhatsappMessage $message)
    {
        // Reset error message and status
        $message->update([
            'status' => WhatsappMessage::STATUS_PENDING,
            'error_message' => null,
        ]);

        // Resend based on message type
        if ($message->media_url) {
            // Media message
            return $this->sendMediaMessage(
                $message->recipient,
                $message->media_url,
                $this->detectMediaType($message->media_url),
                $message->content,
                $message->related_model_type,
                $message->related_model_id
            );
        } else {
            // Text message
            return $this->sendTextMessage(
                $message->recipient,
                $message->content,
                $message->related_model_type,
                $message->related_model_id
            );
        }
    }

    /**
     * Handle webhooks from WhatsApp/Twilio
     *
     * @param  array  $webhookData
     * @return void
     */
    public function handleWebhook(array $webhookData)
    {
        Log::info('Processing WhatsApp webhook', ['data' => $webhookData]);

        try {
            // Handle Facebook/WhatsApp webhook
            if (isset($webhookData['entry'])) {
                foreach ($webhookData['entry'] as $entry) {
                    if (isset($entry['changes'])) {
                        foreach ($entry['changes'] as $change) {
                            if (isset($change['value']['statuses'])) {
                                foreach ($change['value']['statuses'] as $status) {
                                    $this->updateBidirectionalMessageStatus($status);
                                }
                            }
                        }
                    }
                }
            }
            // Handle Twilio webhook
            else if (isset($webhookData['MessageSid'])) {
                $this->updateTwilioMessageStatus($webhookData);
            }
        } catch (Exception $e) {
            Log::error('Error processing WhatsApp webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Update a message status based on webhook data
     *
     * @param  array  $statusData
     * @return void
     */
    public function updateMessageStatus(array $statusData)
    {
        if (!isset($statusData['id'])) {
            Log::warning('Missing message ID in webhook data', ['data' => $statusData]);
            return;
        }

        $message = WhatsappMessage::where('external_id', $statusData['id'])->first();

        if (!$message) {
            Log::warning('Message not found for external ID', ['external_id' => $statusData['id']]);
            return;
        }

        // Map WhatsApp status to our status
        $statusMap = [
            'sent' => WhatsappMessage::STATUS_SENT,
            'delivered' => WhatsappMessage::STATUS_DELIVERED,
            'read' => WhatsappMessage::STATUS_READ,
            'failed' => WhatsappMessage::STATUS_FAILED,
        ];

        $status = $statusData['status'] ?? null;
        
        if (!isset($statusMap[$status])) {
            Log::warning('Unknown status in webhook data', ['status' => $status]);
            return;
        }

        $updateData = ['status' => $statusMap[$status]];

        // Update timestamps based on status
        if ($status === 'sent' && !$message->sent_at) {
            $updateData['sent_at'] = now();
        } else if ($status === 'delivered' && !$message->delivered_at) {
            $updateData['delivered_at'] = now();
        } else if ($status === 'read' && !$message->read_at) {
            $updateData['read_at'] = now();
        } else if ($status === 'failed') {
            $updateData['error_message'] = $statusData['errors'][0]['message'] ?? 'Unknown error';
        }

        $message->update($updateData);
        
        Log::info('Updated message status', [
            'message_id' => $message->id,
            'status' => $statusMap[$status],
            'external_id' => $statusData['id'],
        ]);
    }

    /**
     * Update a message status based on Twilio webhook data
     *
     * @param  array  $webhookData
     * @return void
     */
    public function updateTwilioMessageStatus(array $webhookData)
    {
        $messageSid = $webhookData['MessageSid'] ?? null;
        $messageStatus = $webhookData['MessageStatus'] ?? null;

        if (!$messageSid || !$messageStatus) {
            Log::warning('Missing required data in Twilio webhook', [
                'data' => $webhookData
            ]);
            return;
        }

        $message = WhatsappMessage::where('external_id', $messageSid)->first();

        if (!$message) {
            Log::warning('Message not found for external ID', ['external_id' => $messageSid]);
            return;
        }

        // Map Twilio status to our status
        $statusMap = [
            'sent' => WhatsappMessage::STATUS_SENT,
            'delivered' => WhatsappMessage::STATUS_DELIVERED,
            'read' => WhatsappMessage::STATUS_READ,
            'failed' => WhatsappMessage::STATUS_FAILED,
            'undelivered' => WhatsappMessage::STATUS_FAILED,
        ];

        if (!isset($statusMap[$messageStatus])) {
            // Ignore statuses we don't care about (like "queued")
            return;
        }

        $updateData = ['status' => $statusMap[$messageStatus]];

        // Update timestamps based on status
        if ($messageStatus === 'sent' && !$message->sent_at) {
            $updateData['sent_at'] = now();
        } else if ($messageStatus === 'delivered' && !$message->delivered_at) {
            $updateData['delivered_at'] = now();
        } else if ($messageStatus === 'read' && !$message->read_at) {
            $updateData['read_at'] = now();
        } else if (in_array($messageStatus, ['failed', 'undelivered'])) {
            $updateData['error_message'] = $webhookData['ErrorMessage'] ?? 'Message delivery failed';
        }

        $message->update($updateData);
        
        Log::info('Updated message status from Twilio', [
            'message_id' => $message->id,
            'status' => $statusMap[$messageStatus],
            'external_id' => $messageSid,
        ]);
    }

    /**
     * Send a simple WhatsApp message. (Legacy method maintained for compatibility)
     *
     * @param  string  $to The recipient's phone number (with country code, no + prefix)
     * @param  string  $message The message to send
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance|null
     */
    public function sendMessage(string $to, string $message)
    {
        try {
            $to = $this->formatNumber($to);
            $from = $this->formatNumber($this->fromNumber);

            $messageParams = [
                'from' => $from,
                'body' => $message,
            ];

            // Add messaging service SID if configured
            if ($this->messagingServiceSid) {
                $messageParams['messagingServiceSid'] = $this->messagingServiceSid;
            }

            $message = $this->client->messages->create($to, $messageParams);
            
            Log::info('WhatsApp message sent', [
                'to' => $to,
                'message_sid' => $message->sid,
                'status' => $message->status,
            ]);

            return $message;
        } catch (Exception $e) {
            Log::error('Failed to send WhatsApp message', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Send a template WhatsApp message.
     *
     * @param  string  $to The recipient's phone number (with country code, no + prefix)
     * @param  string  $contentSid The content SID of the template
     * @param  array  $variables The variables to be used in the template
     * @param  string|null  $relatedModelType The type of related model
     * @param  int|null  $relatedModelId The ID of related model
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance|null
     */
    public function sendTemplateMessage(
        string $to, 
        string $contentSid, 
        array $variables = [],
        $relatedModelType = null,
        $relatedModelId = null
    ) {
        try {
            $to = $this->formatNumber($to);
            $from = $this->formatNumber($this->fromNumber);

            $messageParams = [
                'from' => $from,
                'contentSid' => $contentSid,
            ];

            // Add variables if provided
            if (!empty($variables)) {
                $messageParams['contentVariables'] = json_encode($variables);
            }

            // Add messaging service SID if configured
            if ($this->messagingServiceSid) {
                $messageParams['messagingServiceSid'] = $this->messagingServiceSid;
            }

            // Create a record in the database first
            $whatsappMessage = WhatsappMessage::create([
                'recipient' => preg_replace('/[^0-9]/', '', $to),
                'content' => json_encode($variables),
                'status' => WhatsappMessage::STATUS_PENDING,
                'related_model_type' => $relatedModelType,
                'related_model_id' => $relatedModelId,
            ]);

            $message = $this->client->messages->create($to, $messageParams);
            
            // Update the message with the SID and status
            $whatsappMessage->update([
                'status' => WhatsappMessage::STATUS_SENT,
                'external_id' => $message->sid,
                'sent_at' => now(),
            ]);
            
            Log::info('WhatsApp template message sent', [
                'to' => $to,
                'content_sid' => $contentSid,
                'message_sid' => $message->sid,
                'status' => $message->status,
            ]);

            return $message;
        } catch (Exception $e) {
            // If we created a message record, update it with the error
            if (isset($whatsappMessage)) {
                $whatsappMessage->update([
                    'status' => WhatsappMessage::STATUS_FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }

            Log::error('Failed to send WhatsApp template message', [
                'to' => $to,
                'content_sid' => $contentSid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
    
    /**
     * Send a template using a template payload from the builder
     *
     * @param array $payload Template payload from the builder
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance|null
     */
    public function sendFromTemplate(array $payload)
    {
        try {
            // Validate that we have a phone number
            if (empty($payload['to'])) {
                Log::warning("Cannot send WhatsApp template: missing phone number", [
                    'template' => $payload['template'] ?? 'unknown',
                    'payload' => $payload
                ]);
                return null;
            }
            
            $templateMap = [
                'agendamento_cliente' => self::TEMPLATE_AGENDAMENTO_CLIENTE,
                'agendamento_cancelado' => self::TEMPLATE_AGENDAMENTO_CANCELADO,
                'agendamento_confirmado' => self::TEMPLATE_AGENDAMENTO_CONFIRMADO,
                'nps_survey' => self::TEMPLATE_NPS_SURVEY,
                'nps_survey_prestador' => self::TEMPLATE_NPS_SURVEY_PRESTADOR,
                'nps_pergunta' => self::TEMPLATE_NPS_PERGUNTA,
                'copy_menssagem_operadora' => self::TEMPLATE_COPY_MENSAGEM_OPERADORA,
                'disponibilidade_prestador' => self::TEMPLATE_DISPONIBILIDADE_PRESTADOR,
                'confirmacao_atendimento' => self::TEMPLATE_CONFIRMACAO_ATENDIMENTO,
                'pagamento_realizado' => self::TEMPLATE_PAGAMENTO_REALIZADO,
                'lembrete_nota_fiscal' => self::TEMPLATE_LEMBRETE_NOTA_FISCAL,
                'tarefa_critica' => self::TEMPLATE_TAREFA_CRITICA,
                'aprovacao_pendente' => self::TEMPLATE_APROVACAO_PENDENTE,
                'paciente_ausente' => self::TEMPLATE_PACIENTE_AUSENTE,
                'preparo_exame' => self::TEMPLATE_PREPARO_EXAME,
                'solicitation_invite' => self::TEMPLATE_SOLICITATION_INVITE,
            ];
            
            $templateSid = $templateMap[$payload['template']] ?? null;
            
            if (!$templateSid) {
                Log::error("Unknown template type", [
                    'template' => $payload['template'] ?? 'null',
                    'available_templates' => array_keys($templateMap)
                ]);
                return null;
            }
            
            return $this->sendTemplateMessage(
                $payload['to'],
                $templateSid,
                $payload['variables'] ?? [],
                $payload['related_model_type'] ?? null,
                $payload['related_model_id'] ?? null
            );
        } catch (Exception $e) {
            Log::error('Failed to send WhatsApp template message', [
                'payload' => $payload,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return null;
        }
    }
    
    /**
     * Send appointment reminder to a patient
     *
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @param string $clinicAddress
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance|null
     */
    public function sendAppointmentReminderToPatient(
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress
    ) {
        try {
            // Check if patient has a phone number
            if (empty($patient->phone)) {
                Log::warning("Cannot send WhatsApp appointment reminder: patient has no phone number", [
                    'appointment_id' => $appointment->id,
                    'patient_id' => $patient->id,
                    'patient_name' => $patient->name ?? 'no_name'
                ]);
                return null;
            }
            
            // Safely get specialty name with better error handling
            $specialty = 'Especialista'; // Default fallback
            
            Log::info("Processing professional specialty for WhatsApp message", [
                'appointment_id' => $appointment->id,
                'patient_phone' => $patient->phone,
                'professional_id' => $professional->id ?? 'no_id',
                'professional_name' => $professional->name ?? 'no_name',
                'specialty_exists' => isset($professional->specialty),
                'specialty_type' => $professional->specialty ? gettype($professional->specialty) : 'null',
                'specialty_value' => $professional->specialty ?? 'null'
            ]);
            
            if ($professional && isset($professional->specialty)) {
                if (is_object($professional->specialty) && isset($professional->specialty->name)) {
                    $specialty = $professional->specialty->name;
                    Log::info("Using specialty object name", ['specialty' => $specialty]);
                } elseif (is_string($professional->specialty) && !empty($professional->specialty)) {
                    $specialty = $professional->specialty;
                    Log::info("Using specialty string value", ['specialty' => $specialty]);
                } else {
                    Log::warning("Specialty exists but is not object or string", [
                        'specialty_type' => gettype($professional->specialty),
                        'specialty_value' => $professional->specialty
                    ]);
                }
            }
            
            $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
            $appointmentTime = Carbon::parse($appointment->scheduled_date)->format('H:i');
            
            // Get health plan name safely
            $healthPlanName = 'Plano de Saúde'; // Default fallback
            if ($appointment->solicitation && $appointment->solicitation->healthPlan) {
                $healthPlanName = $appointment->solicitation->healthPlan->name;
            }
            
            Log::info("Building WhatsApp template variables", [
                'appointment_id' => $appointment->id,
                'patient_phone' => $patient->phone,
                'health_plan_name' => $healthPlanName,
                'patient_name' => $patient->name ?? 'no_name',
                'professional_name' => $professional->name ?? 'no_name',
                'specialty' => $specialty,
                'appointment_date' => $appointmentDate,
                'appointment_time' => $appointmentTime
            ]);
            
            $variables = $this->templateBuilder->buildAppointmentReminder(
                $healthPlanName,
                $patient->name,
                $professional->name,
                $specialty,
                $appointmentDate,
                $appointmentTime,
                $clinicAddress,
                $appointment->id
            );
            
            // Construct complete payload for sendFromTemplate
            $payload = [
                'to' => $patient->phone,
                'template' => 'agendamento_cliente', // Use the correct template for scheduled appointments
                'variables' => $variables,
                'related_model_type' => 'App\\Models\\Appointment',
                'related_model_id' => $appointment->id
            ];
            
            Log::info("Sending WhatsApp template with payload", [
                'appointment_id' => $appointment->id,
                'to' => $patient->phone,
                'template' => 'agendamento_cliente',
                'variables_count' => count($variables),
                'phone_is_valid' => !empty($patient->phone)
            ]);
            
            return $this->sendFromTemplate($payload);
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp appointment reminder', [
                'appointment_id' => $appointment->id,
                'patient_id' => $patient->id,
                'patient_phone' => $patient->phone ?? 'null',
                'professional_id' => $professional->id ?? 'null',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Don't rethrow the exception to prevent breaking the notification flow
            return null;
        }
    }
    
    /**
     * Send appointment cancellation notification
     *
     * @param Patient $patient
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendAppointmentCancellationToPatient(Patient $patient)
    {
        $payload = $this->templateBuilder->buildAppointmentCancellation($patient);
        
        return $this->sendFromTemplate($payload);
    }
    
    /**
     * Send appointment confirmation notification
     *
     * @param Patient $patient
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendAppointmentConfirmationToPatient(Patient $patient)
    {
        $payload = $this->templateBuilder->buildAppointmentConfirmation($patient);
        
        return $this->sendFromTemplate($payload);
    }
    
    /**
     * Send NPS survey to a patient
     *
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendNpsSurveyToPatient(
        Patient $patient,
        Professional $professional,
        Appointment $appointment
    ) {
        // Safely get specialty name with better error handling
        $specialty = 'Especialista'; // Default fallback
        
        if ($professional && isset($professional->specialty)) {
            if (is_object($professional->specialty) && isset($professional->specialty->name)) {
                $specialty = $professional->specialty->name;
            } elseif (is_string($professional->specialty) && !empty($professional->specialty)) {
                $specialty = $professional->specialty;
            }
        }
        
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        
        $variables = $this->templateBuilder->buildNpsSurvey(
            $patient->name,
            $appointmentDate,
            $professional->name,
            $specialty,
            (string)$appointment->id
        );
        
        // Construct complete payload for sendFromTemplate
        $payload = [
            'to' => $patient->phone,
            'template' => 'nps_survey',
            'variables' => $variables,
            'related_model_type' => 'App\\Models\\Appointment',
            'related_model_id' => $appointment->id
        ];
        
        return $this->sendFromTemplate($payload);
    }
    
    /**
     * Send provider-specific NPS survey to a patient
     *
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendProviderNpsSurveyToPatient(
        Patient $patient,
        Professional $professional,
        Appointment $appointment
    ) {
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        
        $variables = $this->templateBuilder->buildNpsProviderSurvey(
            $patient->name,
            $professional->name,
            $appointmentDate,
            (string)$appointment->id
        );
        
        // Construct complete payload for sendFromTemplate
        $payload = [
            'to' => $patient->phone,
            'template' => 'nps_survey_prestador',
            'variables' => $variables,
            'related_model_type' => 'App\\Models\\Appointment',
            'related_model_id' => $appointment->id
        ];
        
        return $this->sendFromTemplate($payload);
    }
    
    /**
     * Send NPS question to a patient
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendNpsQuestionToPatient(Patient $patient, Appointment $appointment)
    {
        $variables = $this->templateBuilder->buildNpsQuestion((string)$appointment->id);
        
        // Construct complete payload for sendFromTemplate
        $payload = [
            'to' => $patient->phone,
            'template' => 'nps_pergunta',
            'variables' => $variables,
            'related_model_type' => 'App\\Models\\Appointment',
            'related_model_id' => $appointment->id
        ];
        
        return $this->sendFromTemplate($payload);
    }
    
    /**
     * Send appointment notification to an operator
     *
     * @param string $operatorPhone
     * @param string $operatorName
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @param string $clinicAddress
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendAppointmentNotificationToOperator(
        string $operatorPhone,
        string $operatorName,
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress
    ) {
        // Safely get specialty name with better error handling
        $specialty = 'Especialista'; // Default fallback
        
        if ($professional && isset($professional->specialty)) {
            if (is_object($professional->specialty) && isset($professional->specialty->name)) {
                $specialty = $professional->specialty->name;
            } elseif (is_string($professional->specialty) && !empty($professional->specialty)) {
                $specialty = $professional->specialty;
            }
        }
        
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($appointment->scheduled_date)->format('H:i');
        
        $payload = [
            'to' => $operatorPhone,
            'template' => 'copy_menssagem_operadora',
            'variables' => $this->templateBuilder->buildOperatorMessage(
                $operatorName,
                $patient->name,
                $professional->name,
                $specialty,
                $appointmentDate,
                $appointmentTime,
                $clinicAddress
            )
        ];
        
        return $this->sendFromTemplate($payload);
    }
    
    /**
     * Send appointment notification to a health plan
     *
     * @param HealthPlan $healthPlan
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @param string $clinicAddress
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendAppointmentNotificationToHealthPlan(
        HealthPlan $healthPlan,
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress
    ) {
        $payload = $this->templateBuilder->buildHealthPlanNotification(
            $healthPlan,
            $patient,
            $professional,
            $appointment,
            $clinicAddress
        );
        
        return $this->sendFromTemplate($payload);
    }

    /**
     * Detect media type from URL
     *
     * @param string $url
     * @return string
     */
    public function detectMediaType(string $url)
    {
        $extension = pathinfo($url, PATHINFO_EXTENSION);
        
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        $videoExtensions = ['mp4', 'mov', 'avi', 'webm'];
        $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a'];
        
        if (in_array(strtolower($extension), $imageExtensions)) {
            return 'image';
        } elseif (in_array(strtolower($extension), $documentExtensions)) {
            return 'document';
        } elseif (in_array(strtolower($extension), $videoExtensions)) {
            return 'video';
        } elseif (in_array(strtolower($extension), $audioExtensions)) {
            return 'audio';
        }
        
        // Default to document if can't determine
        return 'document';
    }

    /**
     * Format a phone number for WhatsApp.
     *
     * @param  string  $number
     * @return string
     */
    public function formatNumber(string $number)
    {
        // Remove any non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $number);
        
        // If the number doesn't start with a country code, add Brazil's +55
        if (!str_starts_with($number, '55') && strlen($number) >= 10) {
            $number = '55' . $number;
        }
        
        // Format as required by Twilio WhatsApp
        return "whatsapp:+{$number}";
    }

    /**
     * Send a test message using a template without requiring actual model objects
     *
     * @param string $to The recipient's phone number (with country code, no + prefix)
     * @param string $templateKey Which template to use (agendamento_cliente, nps_survey, etc.)
     * @param array $testData Optional override test data
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance|null
     */
    public function sendTestMessage(string $to, string $templateKey, array $testData = [])
    {
        try {
            // Map template keys to template SIDs
            $templateMap = [
                'agendamento_cliente' => self::TEMPLATE_AGENDAMENTO_CLIENTE,
                'agendamento_cancelado' => self::TEMPLATE_AGENDAMENTO_CANCELADO,
                'agendamento_confirmado' => self::TEMPLATE_AGENDAMENTO_CONFIRMADO,
                'nps_survey' => self::TEMPLATE_NPS_SURVEY,
                'nps_survey_prestador' => self::TEMPLATE_NPS_SURVEY_PRESTADOR,
                'nps_pergunta' => self::TEMPLATE_NPS_PERGUNTA,
                'copy_menssagem_operadora' => self::TEMPLATE_COPY_MENSAGEM_OPERADORA
            ];
            
            if (!isset($templateMap[$templateKey])) {
                throw new Exception("Template key '{$templateKey}' not found");
            }
            
            $templateSid = $templateMap[$templateKey];
            
            // Generate default test data if none provided
            if (empty($testData)) {
                $testData = $this->generateDefaultTestData($templateKey);
            }
            
            // Send the template message
            $message = $this->sendTemplateMessage(
                $to,
                $templateSid,
                $testData,
                'test',
                null
            );
            
            Log::info('Test message sent', [
                'template_key' => $templateKey,
                'to' => $to,
                'data' => $testData
            ]);
            
            return $message;
        } catch (Exception $e) {
            Log::error('Failed to send test message', [
                'template_key' => $templateKey,
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Generate default test data for templates
     *
     * @param string $templateKey
     * @return array
     */
    public function generateDefaultTestData($templateKey)
    {
        $currentDate = Carbon::now()->format('d/m/Y');
        $futureDate = Carbon::now()->addDays(3)->format('d/m/Y');
        
        switch ($templateKey) {
            case 'agendamento_cliente':
                return [
                    '1' => 'João da Silva', // nome_cliente
                    '2' => 'Dr. Maria Fernandes', // nome_especialista
                    '3' => 'Cardiologia', // especialidade
                    '4' => $futureDate, // data_consulta
                    '5' => '14:30', // hora_consulta
                    '6' => 'Av. Paulista, 1000, São Paulo - SP', // endereco_clinica
                    '7' => 'https://agendamento.example.com/123456' // link_confirmacao
                ];
                
            case 'agendamento_cancelado':
                return [
                    '1' => 'Ana Souza', // nome_cliente
                    '2' => $futureDate, // data_consulta
                    '3' => 'https://reagendamento.example.com/123456' // link_reagendamento
                ];
                
            case 'agendamento_confirmado':
                return [
                    '1' => 'Pedro Santos', // nome_cliente
                    '2' => $futureDate, // data_consulta
                    '3' => '10:15', // hora_consulta
                    '4' => 'https://detalhes.example.com/123456' // link_detalhes
                ];
                
            case 'nps_survey':
                return [
                    '1' => 'Carlos Oliveira', // nome_cliente
                    '2' => $currentDate, // data_consulta
                    '3' => 'Dr. Ricardo Mendes', // nome_especialista
                    '4' => 'Ortopedia', // especialidade
                    '5' => '123456' // appointment_id
                ];
                
            case 'nps_survey_prestador':
                return [
                    '1' => 'Mariana Costa', // nome_cliente
                    '2' => 'Dra. Juliana Alves', // nome_especialista
                    '3' => $currentDate, // data_consulta
                    '4' => '123456' // appointment_id
                ];
                
            case 'nps_pergunta':
                return [
                    '1' => '123456' // appointment_id
                ];
                
            case 'copy_menssagem_operadora':
                return [
                    '1' => 'Fernanda Lima', // nome_operador
                    '2' => 'Lucas Martins', // nome_cliente
                    '3' => 'Dr. Paulo Cardoso', // nome_especialista
                    '4' => 'Oftalmologia', // especialidade
                    '5' => $futureDate, // data_consulta
                    '6' => '15:45', // hora_consulta
                    '7' => 'Rua Augusta, 500, São Paulo - SP' // endereco_clinica
                ];
                
            default:
                return [
                    '1' => 'Usuário Teste',
                    '2' => $currentDate,
                    '3' => 'https://teste.example.com/123'
                ];
        }
    }

    /**
     * Send a WhatsApp notification about a new negotiation created.
     *
     * @param  User $user
     * @param  Negotiation $negotiation
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendNegotiationCreatedNotification($user, $negotiation)
    {
        if (!$user || !$user->phone || !$negotiation) {
            Log::warning('Missing data for negotiation created notification', [
                'user_id' => $user->id ?? null,
                'negotiation_id' => $negotiation->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildNegotiationCreated(
                $user->name,
                (string) $negotiation->id
            );

            return $this->sendTemplateMessage(
                $user->phone,
                self::TEMPLATE_NEGOTIATION_CREATED,
                $variables,
                'App\\Models\\Negotiation',
                $negotiation->id
            );
        } catch (Exception $e) {
            Log::error('Failed to send negotiation created WhatsApp notification', [
                'user_id' => $user->id,
                'negotiation_id' => $negotiation->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send a WhatsApp notification about a new professional registration.
     *
     * @param  User $user
     * @param  Professional $professional
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendNewProfessionalNotification($user, $professional)
    {
        if (!$user || !$user->phone || !$professional) {
            Log::warning('Missing data for new professional notification', [
                'user_id' => $user->id ?? null,
                'professional_id' => $professional->id ?? null
            ]);
            return null;
        }

        try {
            // Safely get specialty name with better error handling
            $specialty = 'Especialista'; // Default fallback
            
            if ($professional && isset($professional->specialty)) {
                if (is_object($professional->specialty) && isset($professional->specialty->name)) {
                    $specialty = $professional->specialty->name;
                } elseif (is_string($professional->specialty) && !empty($professional->specialty)) {
                    $specialty = $professional->specialty;
                }
            }
            
            $variables = $this->templateBuilder->buildNewProfessional(
                $professional->name,
                $specialty,
                (string) $professional->id
            );

            return $this->sendTemplateMessage(
                $user->phone,
                self::TEMPLATE_NEW_PROFESSIONAL,
                $variables,
                'App\\Models\\Professional',
                $professional->id
            );
        } catch (Exception $e) {
            Log::error('Failed to send new professional WhatsApp notification', [
                'user_id' => $user->id,
                'professional_id' => $professional->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send provider availability request notification
     *
     * @param User $provider
     * @param Patient $patient
     * @param string $serviceType
     * @param string $date
     * @param string $time
     * @param string $requestId
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendProviderAvailabilityRequest(
        $provider,
        $patient,
        string $serviceType,
        string $date,
        string $time,
        string $requestId
    ) {
        if (!$provider || !$provider->phone) {
            Log::warning('Missing data for provider availability request', [
                'provider_id' => $provider->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildProviderAvailabilityRequest(
                $provider->name,
                $patient->name,
                $serviceType,
                $date,
                $time,
                $requestId
            );

            return $this->sendTemplateMessage(
                $provider->phone,
                self::TEMPLATE_DISPONIBILIDADE_PRESTADOR,
                $variables,
                'App\\Models\\Appointment',
                null
            );
        } catch (Exception $e) {
            Log::error('Failed to send provider availability request notification', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send service completion request notification
     *
     * @param User $provider
     * @param Patient $patient
     * @param string $time
     * @param Appointment $appointment
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendServiceCompletionRequest(
        $provider,
        $patient,
        string $time,
        $appointment
    ) {
        if (!$provider || !$provider->phone) {
            Log::warning('Missing data for service completion request', [
                'provider_id' => $provider->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildServiceCompletionRequest(
                $provider->name,
                $patient->name,
                $time,
                (string) $appointment->id
            );

            return $this->sendTemplateMessage(
                $provider->phone,
                self::TEMPLATE_CONFIRMACAO_ATENDIMENTO,
                $variables,
                'App\\Models\\Appointment',
                $appointment->id
            );
        } catch (Exception $e) {
            Log::error('Failed to send service completion request notification', [
                'provider_id' => $provider->id,
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send payment notification to provider
     *
     * @param User $provider
     * @param string $amount
     * @param string $paymentId
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendPaymentNotification(
        $provider,
        string $amount,
        string $paymentId
    ) {
        if (!$provider || !$provider->phone) {
            Log::warning('Missing data for payment notification', [
                'provider_id' => $provider->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildPaymentNotification(
                $provider->name,
                $amount,
                $paymentId
            );

            return $this->sendTemplateMessage(
                $provider->phone,
                self::TEMPLATE_PAGAMENTO_REALIZADO,
                $variables,
                'App\\Models\\Payment',
                $paymentId
            );
        } catch (Exception $e) {
            Log::error('Failed to send payment notification', [
                'provider_id' => $provider->id,
                'payment_id' => $paymentId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send invoice reminder to provider
     *
     * @param User $provider
     * @param string $pendingCount
     * @param string $documentRequestId
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendInvoiceReminder(
        $provider,
        string $pendingCount,
        string $documentRequestId
    ) {
        if (!$provider || !$provider->phone) {
            Log::warning('Missing data for invoice reminder', [
                'provider_id' => $provider->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildInvoiceReminder(
                $provider->name,
                $pendingCount,
                $documentRequestId
            );

            return $this->sendTemplateMessage(
                $provider->phone,
                self::TEMPLATE_LEMBRETE_NOTA_FISCAL,
                $variables,
                'App\\Models\\DocumentRequest',
                $documentRequestId
            );
        } catch (Exception $e) {
            Log::error('Failed to send invoice reminder', [
                'provider_id' => $provider->id,
                'document_request_id' => $documentRequestId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send critical task alert to team member
     *
     * @param User $user
     * @param string $taskType
     * @param string $taskDescription
     * @param string $priority
     * @param string $taskId
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendCriticalTaskAlert(
        $user,
        string $taskType,
        string $taskDescription,
        string $priority,
        string $taskId
    ) {
        if (!$user || !$user->phone) {
            Log::warning('Missing data for critical task alert', [
                'user_id' => $user->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildCriticalTaskAlert(
                $taskType,
                $taskDescription,
                $priority,
                $taskId
            );

            return $this->sendTemplateMessage(
                $user->phone,
                self::TEMPLATE_TAREFA_CRITICA,
                $variables,
                'App\\Models\\Task',
                $taskId
            );
        } catch (Exception $e) {
            Log::error('Failed to send critical task alert', [
                'user_id' => $user->id,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send approval pending notification
     *
     * @param User $approver
     * @param string $approvalType
     * @param string $requesterName
     * @param string $dateRequested
     * @param string $approvalId
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendApprovalPendingNotification(
        $approver,
        string $approvalType,
        string $requesterName,
        string $dateRequested,
        string $approvalId
    ) {
        if (!$approver || !$approver->phone) {
            Log::warning('Missing data for approval pending notification', [
                'approver_id' => $approver->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildApprovalPendingNotification(
                $approvalType,
                $requesterName,
                $dateRequested,
                $approvalId
            );

            return $this->sendTemplateMessage(
                $approver->phone,
                self::TEMPLATE_APROVACAO_PENDENTE,
                $variables,
                'App\\Models\\Approval',
                $approvalId
            );
        } catch (Exception $e) {
            Log::error('Failed to send approval pending notification', [
                'approver_id' => $approver->id,
                'approval_id' => $approvalId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send no-show notification to health plan
     *
     * @param User $healthPlanContact
     * @param string $patientName
     * @param string $appointmentDate
     * @param string $appointmentTime
     * @param string $providerName
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendNoShowNotification(
        $healthPlanContact,
        string $patientName,
        string $appointmentDate,
        string $appointmentTime,
        string $providerName
    ) {
        if (!$healthPlanContact || !$healthPlanContact->phone) {
            Log::warning('Missing data for no-show notification', [
                'contact_id' => $healthPlanContact->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildNoShowNotification(
                $patientName,
                $appointmentDate,
                $appointmentTime,
                $providerName
            );

            return $this->sendTemplateMessage(
                $healthPlanContact->phone,
                self::TEMPLATE_PACIENTE_AUSENTE,
                $variables,
                'App\\Models\\HealthPlan',
                $healthPlanContact->health_plan_id
            );
        } catch (Exception $e) {
            Log::error('Failed to send no-show notification', [
                'contact_id' => $healthPlanContact->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send exam preparation instructions to patient
     *
     * @param Patient $patient
     * @param string $examType
     * @param string $examDate
     * @param string $examTime
     * @param string $examId
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendExamPreparationInstructions(
        $patient,
        string $examType,
        string $examDate,
        string $examTime,
        string $examId
    ) {
        if (!$patient || !$patient->phone) {
            Log::warning('Missing data for exam preparation instructions', [
                'patient_id' => $patient->id ?? null
            ]);
            return null;
        }

        try {
            $variables = $this->templateBuilder->buildExamPreparationInstructions(
                $patient->name,
                $examType,
                $examDate,
                $examTime,
                $examId
            );

            return $this->sendTemplateMessage(
                $patient->phone,
                self::TEMPLATE_PREPARO_EXAME,
                $variables,
                'App\\Models\\Appointment',
                $examId
            );
        } catch (Exception $e) {
            Log::error('Failed to send exam preparation instructions', [
                'patient_id' => $patient->id,
                'exam_id' => $examId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Send appointment verification to patient
     *
     * @param Patient $patient
     * @param string $verificationUrl
     * @param Appointment $appointment
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendAppointmentVerificationToPatient(
        Patient $patient,
        string $verificationUrl,
        Appointment $appointment
    ) {
        try {
            if (!$patient->phone) {
                Log::warning("Cannot send verification message: patient has no phone number");
                return null;
            }
            
            // Get provider information
            $provider = $appointment->provider;
            $providerName = $provider ? $provider->name : 'Profissional';
            
            // Get procedure information
            $solicitation = $appointment->solicitation;
            $procedureName = $solicitation->tuss ? $solicitation->tuss->description : 'Procedimento';
            
            // Get clinic address
            $clinicAddress = $this->getClinicAddress($appointment);
            
            $variables = $this->templateBuilder->buildAppointmentVerification(
                $patient->name,
                config('app.name', 'Conecta'),
                Carbon::parse($appointment->scheduled_date)->format('H:i'),
                Carbon::parse($appointment->scheduled_date)->format('d/m/Y'),
                $providerName,
                $procedureName,
                $clinicAddress,
                (string) $appointment->id
            );
            
            return $this->sendTemplateMessage(
                $patient->phone,
                self::TEMPLATE_APPOINTMENT_VERIFICATION,
                $variables,
                'App\\Models\\Appointment',
                $appointment->id
            );
        } catch (Exception $e) {
            Log::error("Failed to send appointment verification to patient: " . $e->getMessage(), [
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id
            ]);
            
            return null;
        }
    }
    
    /**
     * Send appointment verification to provider
     *
     * @param mixed $provider
     * @param string $verificationUrl
     * @param Appointment $appointment
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendAppointmentVerificationToProvider(
        $provider,
        string $verificationUrl,
        Appointment $appointment
    ) {
        try {
            if (!$provider->phone) {
                Log::warning("Cannot send verification message: provider has no phone number");
                return null;
            }
            
            $clinicAddress = $this->getClinicAddress($appointment);
            $variables = $this->templateBuilder->buildAppointmentVerification(
                $provider->name,
                config('app.name', 'Conecta'),
                Carbon::parse($appointment->scheduled_date)->format('H:i'),
                Carbon::parse($appointment->scheduled_date)->format('d/m/Y'),
                $provider->name,
                $appointment->solicitation->tuss ? $appointment->solicitation->tuss->description : 'Procedimento',
                $appointment->address->street . ', ' . $appointment->address->number . ' - ' . $appointment->address->neighborhood . ' - ' . $appointment->address->city . ' - ' . $appointment->address->state . ' - ' . $appointment->address->postal_code,
                (string) $appointment->id
            );
            
            return $this->sendTemplateMessage(
                $provider->phone,
                'appointment_verification_provider',
                $variables,
                'App\\Models\\Appointment',
                $appointment->id
            );
        } catch (Exception $e) {
            Log::error("Failed to send appointment verification to provider: " . $e->getMessage(), [
                'provider_id' => $provider->id ?? 'unknown',
                'appointment_id' => $appointment->id
            ]);
            
            return null;
        }
    }

    /**
     * Send account created notification
     *
     * @param string $userName
     * @param string $to
     * @return void
     */
    public function sendAccountCreatedNotification(string $userName, string $to): void
    {
        $variables = $this->templateBuilder->buildAccountCreated($userName);
        $this->sendTemplateMessage($to, 'account_created', $variables);
    }

    /**
     * Send negotiation internal approval required notification
     *
     * @param string $approverName
     * @param string $negotiationName
     * @param string $entityName
     * @param int $itemCount
     * @param string $approvalLevel
     * @param string $negotiationId
     * @param string $to
     * @return void
     */
    public function sendNegotiationInternalApprovalRequired(
        string $approverName,
        string $negotiationName,
        string $entityName,
        int $itemCount,
        string $approvalLevel,
        string $negotiationId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildNegotiationInternalApprovalRequired(
            $approverName,
            $negotiationName,
            $entityName,
            $itemCount,
            $approvalLevel,
            $negotiationId
        );
        $this->sendTemplateMessage($to, 'negotiation_internal_approval_required', $variables);
    }

    /**
     * Send negotiation counter offer received notification
     *
     * @param string $userName
     * @param string $amount
     * @param string $itemName
     * @param string $negotiationName
     * @param string $negotiationId
     * @param string $to
     * @return void
     */
    public function sendNegotiationCounterOfferReceived(
        string $userName,
        string $amount,
        string $itemName,
        string $negotiationName,
        string $negotiationId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildNegotiationCounterOfferReceived(
            $userName,
            $amount,
            $itemName,
            $negotiationName,
            $negotiationId
        );
        $this->sendTemplateMessage($to, 'negotiation_counter_offer_received', $variables);
    }

    /**
     * Send negotiation item response notification
     *
     * @param string $userName
     * @param string $itemName
     * @param string $amount
     * @param string $negotiationName
     * @param string $status
     * @param string $negotiationId
     * @param string $to
     * @return void
     */
    public function sendNegotiationItemResponse(
        string $userName,
        string $itemName,
        string $amount,
        string $negotiationName,
        string $status,
        string $negotiationId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildNegotiationItemResponse(
            $userName,
            $itemName,
            $amount,
            $negotiationName,
            $status,
            $negotiationId
        );
        $this->sendTemplateMessage($to, 'copy_negotiation_item_response_3', $variables);
    }

    /**
     * Send negotiation submitted to entity notification
     *
     * @param string $entityName
     * @param string $negotiationName
     * @param string $negotiationId
     * @param string $to
     * @return void
     */
    public function sendNegotiationSubmittedToEntity(
        string $entityName,
        string $negotiationName,
        string $negotiationId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildNegotiationSubmittedToEntity(
            $entityName,
            $negotiationName,
            $negotiationId
        );
        $this->sendTemplateMessage($to, 'negotiation_submitted_to_entity', $variables);
    }

    /**
     * Send NPS survey to patient
     *
     * @param string $patientName
     * @param string $appointmentDate
     * @param string $professionalName
     * @param string $specialty
     * @param string $appointmentId
     * @param string $to
     * @return void
     */
    public function sendNpsSurvey(
        string $patientName,
        string $appointmentDate,
        string $professionalName,
        string $specialty,
        string $appointmentId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildNpsSurvey(
            $patientName,
            $appointmentDate,
            $professionalName,
            $specialty,
            $appointmentId
        );
        $this->sendTemplateMessage($to, 'nps_survey', $variables);
    }

    /**
     * Send NPS provider survey to patient
     *
     * @param string $patientName
     * @param string $professionalName
     * @param string $appointmentDate
     * @param string $appointmentId
     * @param string $to
     * @return void
     */
    public function sendNpsProviderSurvey(
        string $patientName,
        string $professionalName,
        string $appointmentDate,
        string $appointmentId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildNpsProviderSurvey(
            $patientName,
            $professionalName,
            $appointmentDate,
            $appointmentId
        );
        $this->sendTemplateMessage($to, 'nps_survey_prestador', $variables);
    }

    /**
     * Send NPS question to patient
     *
     * @param string $appointmentId
     * @param string $to
     * @return void
     */
    public function sendNpsQuestion(string $appointmentId, string $to): void
    {
        $this->sendTemplateMessage(
            $to,
            self::TEMPLATE_NPS_PERGUNTA,
            [
                'appointment_id' => $appointmentId
            ],
            'App\\Models\\Appointment',
            $appointmentId
        );
    }

    /**
     * Send notification when provider's availability is selected.
     *
     * @param string $providerName
     * @param string $patientName
     * @param string $procedureName
     * @param string $scheduledDate
     * @param string $appointmentId
     * @param string $to
     * @return void
     */
    public function sendAvailabilitySelectedNotification(
        string $providerName,
        string $patientName,
        string $procedureName,
        string $scheduledDate,
        string $appointmentId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildAvailabilitySelected(
            $providerName,
            $patientName,
            $procedureName,
            $scheduledDate,
            $appointmentId
        );
        
        $this->sendTemplateMessage(
            $to,
            self::TEMPLATE_HEALTH_PLAN_AVAILABILITY_SELECTED,
            $variables,
            'App\\Models\\Appointment',
            $appointmentId
        );
    }

    /**
     * Send notification when provider's availability is rejected.
     *
     * @param string $providerName
     * @param string $patientName
     * @param string $procedureName
     * @param string $availableDate
     * @param string $availableTime
     * @param string $to
     * @return void
     */
    public function sendAvailabilityRejectedNotification(
        string $providerName,
        string $patientName,
        string $procedureName,
        string $availableDate,
        string $availableTime,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildAvailabilityRejected(
            $providerName,
            $patientName,
            $procedureName,
            $availableDate,
            $availableTime
        );
        
        $this->sendTemplateMessage(
            $to,
            self::TEMPLATE_AVAILABILITY_REJECTED,
            $variables,
            'App\\Models\\ProfessionalAvailability'
        );
    }

    /**
     * Send notification to health plan admin when availability is selected.
     *
     * @param string $adminName
     * @param string $patientName
     * @param string $providerName
     * @param string $procedureName
     * @param string $scheduledDate
     * @param string $appointmentId
     * @param string $to
     * @return void
     */
    public function sendHealthPlanAvailabilitySelectedNotification(
        string $adminName,
        string $patientName,
        string $providerName,
        string $procedureName,
        string $scheduledDate,
        string $appointmentId,
        string $to
    ): void {
        $variables = $this->templateBuilder->buildHealthPlanAvailabilitySelected(
            $adminName,
            $patientName,
            $providerName,
            $procedureName,
            $scheduledDate,
            $appointmentId
        );
        
        $this->sendTemplateMessage(
            $to,
            self::TEMPLATE_DISPONIBILIDADE_PRESTADOR,
            $variables,
            'App\\Models\\Appointment',
            $appointmentId
        );
    }

    /**
     * Process appointment verification response from patient
     *
     * @param string $response
     * @param string $from
     * @return void
     */
    public function processAppointmentVerificationResponse(string $response, string $from): void
    {
        try {
            // Parse response format: confirm-{appointmentId} or reject-{appointmentId}
            if (preg_match('/^(confirm|reject)-(\d+)$/', $response, $matches)) {
                $action = $matches[1]; // confirm or reject
                $appointmentId = (int) $matches[2];
                
                // Find the appointment
                $appointment = \App\Models\Appointment::find($appointmentId);
                
                if (!$appointment) {
                    Log::warning("Appointment not found for verification response", [
                        'appointment_id' => $appointmentId,
                        'from' => $from,
                        'response' => $response
                    ]);
                    return;
                }
                
                // Verify the phone number matches the patient
                $patient = $appointment->solicitation->patient;
                if (!$patient || !$this->phoneNumbersMatch($patient->phone, $from)) {
                    Log::warning("Phone number mismatch for appointment verification", [
                        'appointment_id' => $appointmentId,
                        'from' => $from,
                        'patient_phone' => $patient->phone ?? 'null',
                        'normalized_patient' => $this->normalizePhoneNumber($patient->phone ?? ''),
                        'normalized_from' => $this->normalizePhoneNumber($from)
                    ]);
                    return;
                }
                
                // Process the response
                if ($action === 'confirm') {
                    $this->handleAppointmentConfirmation($appointment, $patient);
                } else {
                    $this->handleAppointmentRejection($appointment, $patient);
                }
                
                Log::info("Appointment verification response processed", [
                    'appointment_id' => $appointmentId,
                    'action' => $action,
                    'from' => $from
                ]);
            } else {
                Log::warning("Invalid appointment verification response format", [
                    'response' => $response,
                    'from' => $from
                ]);
            }
        } catch (\Exception $e) {
            Log::error("Error processing appointment verification response", [
                'response' => $response,
                'from' => $from,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Normalize phone number to standard format
     *
     * @param string $phoneNumber
     * @return string
     */
    public function normalizePhoneNumber(string $phoneNumber): string
    {
        // Remove all non-numeric characters
        $normalized = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Brazilian phone numbers logic:
        // - 10 digits: old landline format (without 9th digit) - add 55
        // - 11 digits: mobile or new landline format - add 55  
        // - 12+ digits starting with 55: already has country code
        // - 13+ digits starting with 55: has country code, check if valid
        
        if (strlen($normalized) >= 12 && str_starts_with($normalized, '55')) {
            // Already has country code, use as is
            return $normalized;
        } elseif (strlen($normalized) >= 10) {
            // Brazilian number without country code, add 55
            return '55' . $normalized;
        }
        
        // Invalid number, return as is
        return $normalized;
    }
    
    /**
     * Check if two phone numbers match after normalization
     *
     * @param string $phone1
     * @param string $phone2
     * @return bool
     */
    public function phoneNumbersMatch(string $phone1, string $phone2): bool
    {
        $normalized1 = $this->normalizePhoneNumber($phone1);
        $normalized2 = $this->normalizePhoneNumber($phone2);
        
        Log::info("Comparing phone numbers", [
            'phone1' => $phone1,
            'phone2' => $phone2,
            'normalized1' => $normalized1,
            'normalized2' => $normalized2
        ]);
        
        // Direct match
        if ($normalized1 === $normalized2) {
            return true;
        }
        
        // Remove leading 55 from both and compare
        $without55_1 = str_starts_with($normalized1, '55') ? substr($normalized1, 2) : $normalized1;
        $without55_2 = str_starts_with($normalized2, '55') ? substr($normalized2, 2) : $normalized2;
        
        Log::info("Comparing without country code", [
            'without55_1' => $without55_1,
            'without55_2' => $without55_2
        ]);
        
        if ($without55_1 === $without55_2) {
            return true;
        }
        
        // Handle case where one number might have an extra 5 at the beginning
        if (str_starts_with($without55_1, '5') && $without55_1 === '5' . $without55_2) {
            return true;
        }
        
        if (str_starts_with($without55_2, '5') && $without55_2 === '5' . $without55_1) {
            return true;
        }
        
        // Handle Brazilian mobile number format differences
        // Some numbers might have an extra 9 in the mobile format
        // Compare the last 8 digits (core number) for mobile numbers
        if (strlen($without55_1) >= 8 && strlen($without55_2) >= 8) {
            $core1 = substr($without55_1, -8);
            $core2 = substr($without55_2, -8);
            
            Log::info("Comparing core numbers (last 8 digits)", [
                'core1' => $core1,
                'core2' => $core2
            ]);
            
            if ($core1 === $core2) {
                return true;
            }
        }
        
        // Try removing potential 9 from mobile numbers
        // Brazilian mobile: XX9XXXXXXXX or XXXXXXXXX
        $clean1 = $this->cleanMobileNumber($without55_1);
        $clean2 = $this->cleanMobileNumber($without55_2);
        
        Log::info("Comparing cleaned mobile numbers", [
            'clean1' => $clean1,
            'clean2' => $clean2
        ]);
        
        if ($clean1 === $clean2) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Clean mobile number by removing the 9th digit if present
     *
     * @param string $number
     * @return string
     */
    public function cleanMobileNumber(string $number): string
    {
        // For numbers with 11 digits starting with area code + 9
        // Remove the 9 to get the base number
        if (strlen($number) === 11 && substr($number, 2, 1) === '9') {
            return substr($number, 0, 2) . substr($number, 3);
        }
        
        return $number;
    }
    
    /**
     * Handle appointment confirmation
     *
     * @param Appointment $appointment
     * @param Patient $patient
     * @return void
     */
    public function handleAppointmentConfirmation(Appointment $appointment, Patient $patient): void
    {
        try {
            // Update appointment status
            $appointment->status = 'confirmed';
            $appointment->confirmed_date = now();
            $appointment->save();
            
            // Send confirmation message to patient
            $this->sendAppointmentConfirmationResponse($patient, true, $appointment);
            
            // Notify health plan
            $this->notifyHealthPlanAboutConfirmation($appointment);
            
            // Notify solicitation creator
            $this->notifySolicitationCreatorAboutConfirmation($appointment);
            
            // Notify administrators
            $this->notifyAdministratorsAboutConfirmation($appointment);
            
            Log::info("Appointment confirmed successfully", [
                'appointment_id' => $appointment->id,
                'patient_id' => $patient->id
            ]);
        } catch (\Exception $e) {
            Log::error("Error handling appointment confirmation", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle appointment rejection
     *
     * @param Appointment $appointment
     * @param Patient $patient
     * @return void
     */
    public function handleAppointmentRejection(Appointment $appointment, Patient $patient): void
    {
        try {
            // Update appointment status
            $appointment->status = 'cancelled';
            $appointment->cancelled_date = now();
            $appointment->save();
            
            // Send rejection message to patient
            $this->sendAppointmentConfirmationResponse($patient, false, $appointment);
            
            // Notify health plan
            $this->notifyHealthPlanAboutRejection($appointment);
            
            // Notify solicitation creator
            $this->notifySolicitationCreatorAboutRejection($appointment);
            
            // Notify administrators
            $this->notifyAdministratorsAboutRejection($appointment);
            
            Log::info("Appointment rejected successfully", [
                'appointment_id' => $appointment->id,
                'patient_id' => $patient->id
            ]);
        } catch (\Exception $e) {
            Log::error("Error handling appointment rejection", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Send confirmation/rejection response to patient
     *
     * @param Patient $patient
     * @param bool $confirmed
     * @param Appointment $appointment
     * @return void
     */
    public function sendAppointmentConfirmationResponse(Patient $patient, bool $confirmed, Appointment $appointment = null): void
    {
        try {
            // Send the template-based feedback message
            $variables = $this->templateBuilder->buildAppointmentConfirmationResponse($confirmed);
            
            $templateName = $confirmed ? 'appointment_confirmed_response' : 'appointment_rejected_response';
            
            $this->sendTemplateMessage(
                $patient->phone,
                $templateName,
                $variables,
                'App\\Models\\Patient',
                $patient->id
            );
            
            // Send additional informative text message with appointment details
            if ($appointment) {
                $provider = $appointment->provider;
                $procedure = $appointment->solicitation->tuss;
                $appointmentDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
                
                $detailMessage = $confirmed 
                    ? "✅ *Agendamento Confirmado com Sucesso!*\n\n" .
                      "📋 *Detalhes do seu agendamento:*\n" .
                      "👤 Paciente: {$patient->name}\n" .
                      "👨‍⚕️ Profissional: {$provider->name}\n" .
                      "🩺 Procedimento: {$procedure->description}\n" .
                      "📅 Data/Hora: {$appointmentDate}\n\n" .
                      "📝 *Orientações importantes:*\n" .
                      "• Chegue com 15 minutos de antecedência\n" .
                      "• Traga documento de identidade e cartão do plano\n" .
                      "• Em caso de dúvidas, entre em contato conosco\n\n" .
                      "Aguardamos você no horário agendado! 😊"
                    : "❌ *Agendamento Cancelado*\n\n" .
                      "📋 *Detalhes do agendamento cancelado:*\n" .
                      "👤 Paciente: {$patient->name}\n" .
                      "👨‍⚕️ Profissional: {$provider->name}\n" .
                      "🩺 Procedimento: {$procedure->description}\n" .
                      "📅 Data/Hora: {$appointmentDate}\n\n" .
                      "📞 *Precisa reagendar?*\n" .
                      "Entre em contato conosco através dos nossos canais de atendimento.\n" .
                      "Estamos aqui para ajudar! 😊";
                
                // Send the detailed feedback message
                $this->sendTextMessage(
                    $patient->phone,
                    $detailMessage,
                    'App\\Models\\Appointment',
                    $appointment->id
                );
            }
            
            Log::info("Sent enhanced appointment confirmation response to patient", [
                'patient_id' => $patient->id,
                'confirmed' => $confirmed,
                'appointment_id' => $appointment ? $appointment->id : null
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error sending confirmation response to patient", [
                'patient_id' => $patient->id,
                'confirmed' => $confirmed,
                'appointment_id' => $appointment ? $appointment->id : null,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Notify health plan about appointment confirmation
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyHealthPlanAboutConfirmation(Appointment $appointment): void
    {
        try {
            $solicitation = $appointment->solicitation;
            $healthPlan = $solicitation->healthPlan;
            $patient = $solicitation->patient;
            
            if (!$healthPlan) {
                Log::warning("No health plan found for appointment confirmation notification", [
                    'appointment_id' => $appointment->id
                ]);
                return;
            }
            
            // Get health plan admin users
            $healthPlanAdmins = \App\Models\User::role('plan_admin')
                ->where('entity_type', 'App\\Models\\HealthPlan')
                ->where('entity_id', $healthPlan->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get();
            
            if ($healthPlanAdmins->isEmpty()) {
                Log::warning("No health plan admins found for confirmation notification", [
                    'appointment_id' => $appointment->id,
                    'health_plan_id' => $healthPlan->id
                ]);
                return;
            }
            
            // Prepare notification data
            $appointmentDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
            $provider = $appointment->provider;
            $procedure = $solicitation->tuss;
            
            // Send WhatsApp notification to each admin
            foreach ($healthPlanAdmins as $admin) {
                if ($admin->phone) {
                    $this->sendHealthPlanConfirmationNotification($admin, $appointment, true);
                }
            }
            
            // Send system notification (database notification)
            \Illuminate\Support\Facades\Notification::send($healthPlanAdmins, new \App\Notifications\AppointmentConfirmed($appointment));
            
            // Send detailed email notification to each admin
            foreach ($healthPlanAdmins as $admin) {
                if ($admin->email) {
                    try {
                        $emailSubject = 'Agendamento Confirmado pelo Paciente';
                        $emailContent = "
                            <h2>Agendamento Confirmado</h2>
                            <p>O agendamento foi confirmado pelo paciente. Veja os detalhes abaixo:</p>
                            
                            <h3>Informações do Agendamento:</h3>
                            <ul>
                                <li><strong>ID do Agendamento:</strong> #{$appointment->id}</li>
                                <li><strong>Paciente:</strong> {$patient->name}</li>
                                <li><strong>Profissional:</strong> {$provider->name}</li>
                                <li><strong>Procedimento:</strong> {$procedure->description}</li>
                                <li><strong>Data/Hora:</strong> {$appointmentDate}</li>
                                <li><strong>Status:</strong> Confirmado</li>
                                <li><strong>Confirmado em:</strong> " . now()->format('d/m/Y H:i:s') . "</li>
                            </ul>
                            
                            <h3>Informações do Plano de Saúde:</h3>
                            <ul>
                                <li><strong>Plano:</strong> {$healthPlan->name}</li>
                                <li><strong>Cartão do Beneficiário:</strong> {$patient->health_card_number}</li>
                            </ul>
                            
                            <p>Este agendamento está confirmado e deve proceder conforme planejado.</p>
                        ";
                        
                        \Mail::to($admin->email)->send(new \App\Mail\GeneralNotification(
                            $emailSubject,
                            $emailContent,
                            url("/appointments/{$appointment->id}")
                        ));
                        
                        Log::info("Sent email notification to health plan admin about confirmation", [
                            'admin_id' => $admin->id,
                            'admin_email' => $admin->email,
                            'appointment_id' => $appointment->id
                        ]);
                        
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send email to health plan admin about confirmation", [
                            'admin_id' => $admin->id,
                            'admin_email' => $admin->email,
                            'appointment_id' => $appointment->id,
                            'error' => $emailError->getMessage()
                        ]);
                    }
                }
            }
            
            Log::info("Successfully notified health plan about appointment confirmation", [
                'appointment_id' => $appointment->id,
                'health_plan_id' => $healthPlan->id,
                'notified_admins' => $healthPlanAdmins->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error notifying health plan about confirmation", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Notify health plan about appointment rejection
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyHealthPlanAboutRejection(Appointment $appointment): void
    {
        try {
            $solicitation = $appointment->solicitation;
            $healthPlan = $solicitation->healthPlan;
            $patient = $solicitation->patient;
            
            if (!$healthPlan) {
                Log::warning("No health plan found for appointment rejection notification", [
                    'appointment_id' => $appointment->id
                ]);
                return;
            }
            
            // Get health plan admin users
            $healthPlanAdmins = \App\Models\User::role('plan_admin')
                ->where('entity_type', 'App\\Models\\HealthPlan')
                ->where('entity_id', $healthPlan->id)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get();
            
            if ($healthPlanAdmins->isEmpty()) {
                Log::warning("No health plan admins found for rejection notification", [
                    'appointment_id' => $appointment->id,
                    'health_plan_id' => $healthPlan->id
                ]);
                return;
            }
            
            // Prepare notification data
            $appointmentDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
            $provider = $appointment->provider;
            $procedure = $solicitation->tuss;
            
            // Send WhatsApp notification to each admin
            foreach ($healthPlanAdmins as $admin) {
                if ($admin->phone) {
                    $this->sendHealthPlanConfirmationNotification($admin, $appointment, false);
                }
            }
            
            // Send system notification (database notification)
            \Illuminate\Support\Facades\Notification::send($healthPlanAdmins, new \App\Notifications\AppointmentCancelled($appointment, 'Rejeitado pelo paciente'));
            
            // Send detailed email notification to each admin
            foreach ($healthPlanAdmins as $admin) {
                if ($admin->email) {
                    try {
                        $emailSubject = 'Agendamento Rejeitado pelo Paciente';
                        $emailContent = "
                            <h2>Agendamento Rejeitado</h2>
                            <p><strong>ATENÇÃO:</strong> O agendamento foi rejeitado pelo paciente. Veja os detalhes abaixo:</p>
                            
                            <h3>Informações do Agendamento:</h3>
                            <ul>
                                <li><strong>ID do Agendamento:</strong> #{$appointment->id}</li>
                                <li><strong>Paciente:</strong> {$patient->name}</li>
                                <li><strong>Profissional:</strong> {$provider->name}</li>
                                <li><strong>Procedimento:</strong> {$procedure->description}</li>
                                <li><strong>Data/Hora:</strong> {$appointmentDate}</li>
                                <li><strong>Status:</strong> Cancelado</li>
                                <li><strong>Motivo:</strong> Rejeitado pelo paciente</li>
                                <li><strong>Cancelado em:</strong> " . now()->format('d/m/Y H:i:s') . "</li>
                            </ul>
                            
                            <h3>Informações do Plano de Saúde:</h3>
                            <ul>
                                <li><strong>Plano:</strong> {$healthPlan->name}</li>
                                <li><strong>Cartão do Beneficiário:</strong> {$patient->health_card_number}</li>
                            </ul>
                            
                            <h3>Ações Necessárias:</h3>
                            <ul>
                                <li>Verificar se o paciente deseja reagendar</li>
                                <li>Liberar o horário para outros pacientes</li>
                                <li>Entrar em contato com o paciente se necessário</li>
                            </ul>
                            
                            <p>Este agendamento foi cancelado e requer atenção para possível reagendamento.</p>
                        ";
                        
                        \Mail::to($admin->email)->send(new \App\Mail\GeneralNotification(
                            $emailSubject,
                            $emailContent,
                            url("/appointments/{$appointment->id}")
                        ));
                        
                        Log::info("Sent email notification to health plan admin about rejection", [
                            'admin_id' => $admin->id,
                            'admin_email' => $admin->email,
                            'appointment_id' => $appointment->id
                        ]);
                        
                    } catch (\Exception $emailError) {
                        Log::error("Failed to send email to health plan admin about rejection", [
                            'admin_id' => $admin->id,
                            'admin_email' => $admin->email,
                            'appointment_id' => $appointment->id,
                            'error' => $emailError->getMessage()
                        ]);
                    }
                }
            }
            
            Log::info("Successfully notified health plan about appointment rejection", [
                'appointment_id' => $appointment->id,
                'health_plan_id' => $healthPlan->id,
                'notified_admins' => $healthPlanAdmins->count()
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error notifying health plan about rejection", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Send health plan confirmation/rejection notification
     *
     * @param User $admin
     * @param Appointment $appointment
     * @param bool $confirmed
     * @return void
     */
    public function sendHealthPlanConfirmationNotification(User $admin, Appointment $appointment, bool $confirmed): void
    {
        try {
            $patient = $appointment->solicitation->patient;
            $provider = $appointment->provider;
            $procedure = $appointment->solicitation->tuss;
            
            $message = $confirmed 
                ? "✅ Agendamento Confirmado!\n\nPaciente: {$patient->name}\nProfissional: {$provider->name}\nProcedimento: {$procedure->description}\nData: " . \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i')
                : "❌ Agendamento Cancelado!\n\nPaciente: {$patient->name}\nProfissional: {$provider->name}\nProcedimento: {$procedure->description}\nData: " . \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i') . "\nMotivo: Rejeitado pelo paciente";
            
            $this->sendTextMessage(
                $admin->phone,
                $message,
                'App\\Models\\Appointment',
                $appointment->id
            );
        } catch (\Exception $e) {
            Log::error("Error sending health plan confirmation notification", [
                'admin_id' => $admin->id,
                'appointment_id' => $appointment->id,
                'confirmed' => $confirmed,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Notify solicitation creator about appointment confirmation
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifySolicitationCreatorAboutConfirmation(Appointment $appointment): void
    {
        try {
            $solicitation = $appointment->solicitation;
            $creator = \App\Models\User::find($solicitation->created_by);
            
            if (!$creator || !$creator->is_active) {
                Log::warning("No creator found or creator inactive for confirmation notification", [
                    'appointment_id' => $appointment->id,
                    'solicitation_id' => $solicitation->id,
                    'creator_id' => $solicitation->created_by
                ]);
                return;
            }
            
            $patient = $solicitation->patient;
            $provider = $appointment->provider;
            $procedure = $solicitation->tuss;
            $appointmentDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
            
            // Send system notification (database notification)
            $creator->notify(new \App\Notifications\AppointmentConfirmed($appointment));
            
            // Send WhatsApp notification
            if ($creator->phone) {
                try {
                    $whatsappMessage = "✅ *Agendamento Confirmado pelo Paciente*\n\n" .
                                     "📋 *Detalhes do agendamento:*\n" .
                                     "🆔 ID: #{$appointment->id}\n" .
                                     "👤 Paciente: {$patient->name}\n" .
                                     "👨‍⚕️ Profissional: {$provider->name}\n" .
                                     "🩺 Procedimento: {$procedure->description}\n" .
                                     "📅 Data/Hora: {$appointmentDate}\n" .
                                     "✅ Status: Confirmado pelo paciente\n\n" .
                                     "O agendamento está confirmado e pode proceder conforme planejado.";
                    
                    $this->sendTextMessage(
                        $creator->phone,
                        $whatsappMessage,
                        'App\\Models\\Appointment',
                        $appointment->id
                    );
                    
                    Log::info("Sent WhatsApp notification to solicitation creator about confirmation", [
                        'creator_id' => $creator->id,
                        'appointment_id' => $appointment->id
                    ]);
                    
                } catch (\Exception $whatsappError) {
                    Log::error("Failed to send WhatsApp to solicitation creator about confirmation", [
                        'creator_id' => $creator->id,
                        'appointment_id' => $appointment->id,
                        'error' => $whatsappError->getMessage()
                    ]);
                }
            }
            
            // Send detailed email notification
            if ($creator->email) {
                try {
                    $emailSubject = 'Agendamento Confirmado pelo Paciente';
                    $emailContent = "
                        <h2>Agendamento Confirmado</h2>
                        <p>O agendamento que você criou foi confirmado pelo paciente. Veja os detalhes abaixo:</p>
                        
                        <h3>Informações do Agendamento:</h3>
                        <ul>
                            <li><strong>ID do Agendamento:</strong> #{$appointment->id}</li>
                            <li><strong>ID da Solicitação:</strong> #{$solicitation->id}</li>
                            <li><strong>Paciente:</strong> {$patient->name}</li>
                            <li><strong>Profissional:</strong> {$provider->name}</li>
                            <li><strong>Procedimento:</strong> {$procedure->description}</li>
                            <li><strong>Data/Hora:</strong> {$appointmentDate}</li>
                            <li><strong>Status:</strong> Confirmado pelo paciente</li>
                            <li><strong>Confirmado em:</strong> " . now()->format('d/m/Y H:i:s') . "</li>
                        </ul>
                        
                        <h3>Próximos Passos:</h3>
                        <ul>
                            <li>O agendamento está confirmado e pode proceder</li>
                            <li>O profissional foi notificado</li>
                            <li>O plano de saúde foi informado</li>
                        </ul>
                        
                        <p>Você pode acompanhar o status do agendamento através do sistema.</p>
                    ";
                    
                    \Mail::to($creator->email)->send(new \App\Mail\GeneralNotification(
                        $emailSubject,
                        $emailContent,
                        url("/appointments/{$appointment->id}")
                    ));
                    
                    Log::info("Sent email notification to solicitation creator about confirmation", [
                        'creator_id' => $creator->id,
                        'creator_email' => $creator->email,
                        'appointment_id' => $appointment->id
                    ]);
                    
                } catch (\Exception $emailError) {
                    Log::error("Failed to send email to solicitation creator about confirmation", [
                        'creator_id' => $creator->id,
                        'creator_email' => $creator->email,
                        'appointment_id' => $appointment->id,
                        'error' => $emailError->getMessage()
                    ]);
                }
            }
            
            Log::info("Successfully notified solicitation creator about appointment confirmation", [
                'creator_id' => $creator->id,
                'appointment_id' => $appointment->id,
                'solicitation_id' => $solicitation->id
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error notifying solicitation creator about confirmation", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Notify solicitation creator about appointment rejection
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifySolicitationCreatorAboutRejection(Appointment $appointment): void
    {
        try {
            $solicitation = $appointment->solicitation;
            $creator = \App\Models\User::find($solicitation->created_by);
            
            if (!$creator || !$creator->is_active) {
                Log::warning("No creator found or creator inactive for rejection notification", [
                    'appointment_id' => $appointment->id,
                    'solicitation_id' => $solicitation->id,
                    'creator_id' => $solicitation->created_by
                ]);
                return;
            }
            
            $patient = $solicitation->patient;
            $provider = $appointment->provider;
            $procedure = $solicitation->tuss;
            $appointmentDate = \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i');
            
            // Send system notification (database notification)
            $creator->notify(new \App\Notifications\AppointmentCancelled($appointment, 'Rejeitado pelo paciente'));
            
            // Send WhatsApp notification
            if ($creator->phone) {
                try {
                    $whatsappMessage = "❌ *Agendamento Rejeitado pelo Paciente*\n\n" .
                                     "📋 *Detalhes do agendamento:*\n" .
                                     "🆔 ID: #{$appointment->id}\n" .
                                     "👤 Paciente: {$patient->name}\n" .
                                     "👨‍⚕️ Profissional: {$provider->name}\n" .
                                     "🩺 Procedimento: {$procedure->description}\n" .
                                     "📅 Data/Hora: {$appointmentDate}\n" .
                                     "❌ Status: Rejeitado pelo paciente\n\n" .
                                     "⚠️ *Ação necessária:*\n" .
                                     "• Verificar se o paciente deseja reagendar\n" .
                                     "• Entrar em contato se necessário\n" .
                                     "• Liberar o horário para outros pacientes";
                    
                    $this->sendTextMessage(
                        $creator->phone,
                        $whatsappMessage,
                        'App\\Models\\Appointment',
                        $appointment->id
                    );
                    
                    Log::info("Sent WhatsApp notification to solicitation creator about rejection", [
                        'creator_id' => $creator->id,
                        'appointment_id' => $appointment->id
                    ]);
                    
                } catch (\Exception $whatsappError) {
                    Log::error("Failed to send WhatsApp to solicitation creator about rejection", [
                        'creator_id' => $creator->id,
                        'appointment_id' => $appointment->id,
                        'error' => $whatsappError->getMessage()
                    ]);
                }
            }
            
            // Send detailed email notification
            if ($creator->email) {
                try {
                    $emailSubject = 'URGENTE: Agendamento Rejeitado pelo Paciente';
                    $emailContent = "
                        <h2>Agendamento Rejeitado</h2>
                        <p><strong>ATENÇÃO:</strong> O agendamento que você criou foi rejeitado pelo paciente. Veja os detalhes abaixo:</p>
                        
                        <h3>Informações do Agendamento:</h3>
                        <ul>
                            <li><strong>ID do Agendamento:</strong> #{$appointment->id}</li>
                            <li><strong>ID da Solicitação:</strong> #{$solicitation->id}</li>
                            <li><strong>Paciente:</strong> {$patient->name}</li>
                            <li><strong>Profissional:</strong> {$provider->name}</li>
                            <li><strong>Procedimento:</strong> {$procedure->description}</li>
                            <li><strong>Data/Hora:</strong> {$appointmentDate}</li>
                            <li><strong>Status:</strong> Cancelado</li>
                            <li><strong>Motivo:</strong> Rejeitado pelo paciente</li>
                            <li><strong>Cancelado em:</strong> " . now()->format('d/m/Y H:i:s') . "</li>
                        </ul>
                        
                        <h3>Ações Necessárias:</h3>
                        <ul>
                            <li><strong>Entrar em contato com o paciente</strong> para verificar se deseja reagendar</li>
                            <li><strong>Liberar o horário</strong> para outros pacientes se não houver reagendamento</li>
                            <li><strong>Verificar motivo</strong> da rejeição para melhorar o processo</li>
                            <li><strong>Atualizar status</strong> da solicitação no sistema se necessário</li>
                        </ul>
                        
                        <p><strong>Este agendamento requer atenção imediata para possível reagendamento ou liberação do horário.</strong></p>
                    ";
                    
                    \Mail::to($creator->email)->send(new \App\Mail\GeneralNotification(
                        $emailSubject,
                        $emailContent,
                        url("/appointments/{$appointment->id}")
                    ));
                    
                    Log::info("Sent email notification to solicitation creator about rejection", [
                        'creator_id' => $creator->id,
                        'creator_email' => $creator->email,
                        'appointment_id' => $appointment->id
                    ]);
                    
                } catch (\Exception $emailError) {
                    Log::error("Failed to send email to solicitation creator about rejection", [
                        'creator_id' => $creator->id,
                        'creator_email' => $creator->email,
                        'appointment_id' => $appointment->id,
                        'error' => $emailError->getMessage()
                    ]);
                }
            }
            
            Log::info("Successfully notified solicitation creator about appointment rejection", [
                'creator_id' => $creator->id,
                'appointment_id' => $appointment->id,
                'solicitation_id' => $solicitation->id
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error notifying solicitation creator about rejection", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Notify administrators about appointment confirmation
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAdministratorsAboutConfirmation(Appointment $appointment): void
    {
        try {
            $admins = User::role(['network_manager', 'super_admin', 'director'])
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get();
            
            if ($admins->isEmpty()) {
                return;
            }
            
            // Send system notification
            \Illuminate\Support\Facades\Notification::send($admins, new \App\Notifications\AppointmentConfirmed($appointment));
            
            // Send email notification
            foreach ($admins as $admin) {
                if ($admin->email) {
                    $patient = $appointment->solicitation->patient;
                    \Mail::to($admin->email)->send(new \App\Mail\GeneralNotification(
                        'Agendamento Confirmado',
                        "O agendamento do paciente {$patient->name} foi confirmado para " . \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i'),
                        url("/appointments/{$appointment->id}")
                    ));
                }
            }
            
        } catch (Exception $e) {
            Log::error("Error notifying administrators about confirmation", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Notify administrators about appointment rejection
     *
     * @param Appointment $appointment
     * @return void
     */
    public function notifyAdministratorsAboutRejection(Appointment $appointment): void
    {
        try {
            $admins = User::role(['network_manager', 'super_admin', 'director'])
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->get();
            
            if ($admins->isEmpty()) {
                return;
            }
            
            // Send system notification
            \Illuminate\Support\Facades\Notification::send($admins, new \App\Notifications\AppointmentCancelled($appointment, 'Rejeitado pelo paciente'));
            
            // Send email notification
            foreach ($admins as $admin) {
                if ($admin->email) {
                    $patient = $appointment->solicitation->patient;
                    \Mail::to($admin->email)->send(new \App\Mail\GeneralNotification(
                        'Agendamento Cancelado',
                        "O agendamento do paciente {$patient->name} foi cancelado para " . \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y H:i') . "\nMotivo: Rejeitado pelo paciente",
                        url("/appointments/{$appointment->id}")
                    ));
                }
            }
            
        } catch (Exception $e) {
            Log::error("Error notifying administrators about rejection", [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get clinic address from appointment
     *
     * @param Appointment $appointment
     * @return string
     */
    public function getClinicAddress(Appointment $appointment): string
    {
        try {
            if ($appointment->address) {
                return $appointment->address->street . ', ' . 
                       $appointment->address->number . ' - ' . 
                       $appointment->address->neighborhood . ' - ' . 
                       $appointment->address->city . ' - ' . 
                       $appointment->address->state . ' - ' . 
                       $appointment->address->postal_code;
            }
            
            // Fallback to provider address if available
            if ($appointment->provider && $appointment->provider->address) {
                return $appointment->provider->address->street . ', ' . 
                       $appointment->provider->address->number . ' - ' . 
                       $appointment->provider->address->neighborhood . ' - ' . 
                       $appointment->provider->address->city . ' - ' . 
                       $appointment->provider->address->state . ' - ' . 
                       $appointment->provider->address->postal_code;
            }
            
            return 'Endereço não disponível';
        } catch (\Exception $e) {
            Log::warning('Error getting clinic address', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
            return 'Endereço não disponível';
        }
    }

    /**
     * Send appointment notification to patient using the same template as verification.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return \App\Models\WhatsappMessage|null
     */
    public function sendAppointmentNotificationToPatient(
        Patient $patient,
        Appointment $appointment
    ) {
        try {
            if (!$patient->phone) {
                Log::warning("Cannot send appointment notification: patient has no phone number");
                return null;
            }
            
            // Get provider information
            $provider = $appointment->provider;
            $providerName = $provider ? $provider->name : 'Profissional';
            
            // Get procedure information
            $solicitation = $appointment->solicitation;
            $procedureName = $solicitation->tuss ? $solicitation->tuss->description : 'Procedimento';
            
            // Get clinic address
            $clinicAddress = $this->getClinicAddress($appointment);
            
            $variables = $this->templateBuilder->buildAppointmentVerification(
                $patient->name,
                config('app.name', 'Conecta'),
                Carbon::parse($appointment->scheduled_date)->format('H:i'),
                Carbon::parse($appointment->scheduled_date)->format('d/m/Y'),
                $providerName,
                $procedureName,
                $clinicAddress,
                (string) $appointment->id
            );
            
            return $this->sendTemplateMessage(
                $patient->phone,
                self::TEMPLATE_APPOINTMENT_VERIFICATION,
                $variables,
                'App\\Models\\Appointment',
                $appointment->id
            );
        } catch (Exception $e) {
            Log::error("Failed to send appointment notification to patient: " . $e->getMessage(), [
                'patient_id' => $patient->id,
                'appointment_id' => $appointment->id
            ]);
            
            return null;
        }
    }

    /**
     * Send bidirectional message (alias for sendManualMessage)
     *
     * @param string $to Recipient phone number
     * @param string $content Message content
     * @param string|null $relatedModelType Related model type
     * @param int|null $relatedModelId Related model ID
     * @return Message
     */
    public function sendBidirectionalMessage(
        string $to, 
        string $content, 
        ?string $relatedModelType = null, 
        ?int $relatedModelId = null
    ) {
        return $this->sendManualMessage($to, $content, $relatedModelType, $relatedModelId);
    }

    /**
     * Simple public method for sending messages via Twilio Conversations
     *
     * @param string $to Recipient phone number
     * @param string $content Message content
     * @param string|null $relatedModelType Related model type
     * @param int|null $relatedModelId Related model ID
     * @return Message
     */
    public function sendMessageViaConversations(
        string $to, 
        string $content, 
        ?string $relatedModelType = null, 
        ?int $relatedModelId = null
    ) {
        // Normalize phone number
        $normalizedTo = $this->normalizePhoneNumber($to);
        
        // Get or create conversation
        $conversationSid = $this->getOrCreateConversation($normalizedTo);
        
        // Create message record
        $message = Message::create([
            'sender_phone' => $this->fromNumber,
            'recipient_phone' => $normalizedTo,
            'content' => $content,
            'direction' => Message::DIRECTION_OUTBOUND,
            'status' => Message::STATUS_PENDING,
            'message_type' => Message::TYPE_TEXT,
            'related_model_type' => $relatedModelType,
            'related_model_id' => $relatedModelId,
            'metadata' => [
                'conversation_sid' => $conversationSid,
                'twilio_conversations' => true,
            ],
        ]);

        try {
            // Send message through Conversations
            $twilioMessage = $this->sendConversationMessage($conversationSid, $content, 'system');
            
            // Update the message status
            $message->update([
                'status' => Message::STATUS_SENT,
                'external_id' => $twilioMessage->sid,
                'sent_at' => now(),
            ]);
            
            Log::info('Message sent via Conversations', [
                'message_id' => $message->id,
                'to' => $normalizedTo,
                'conversation_sid' => $conversationSid,
                'twilio_message_sid' => $twilioMessage->sid,
            ]);
        } catch (Exception $e) {
            // Update the message with error information
            $message->update([
                'status' => Message::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            Log::error('Failed to send message via Conversations', [
                'message_id' => $message->id,
                'to' => $normalizedTo,
                'conversation_sid' => $conversationSid,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }

    /**
     * Migrate existing template messages to Conversations system
     *
     * @param string $phone Phone number to migrate
     * @return array Migration results
     */
    public function migrateTemplateMessagesToConversations(string $phone): array
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);
        $results = [
            'migrated' => 0,
            'errors' => [],
            'conversation_sid' => null
        ];

        try {
            // Get or create conversation
            $conversationSid = $this->getOrCreateConversation($normalizedPhone);
            $results['conversation_sid'] = $conversationSid;

            // Get existing template messages from WhatsappMessage table
            $templateMessages = WhatsappMessage::where('recipient', $normalizedPhone)
                ->where('status', 'sent')
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($templateMessages as $templateMsg) {
                try {
                    // Create message record in new system
                    $message = Message::create([
                        'sender_phone' => $this->fromNumber,
                        'recipient_phone' => $normalizedPhone,
                        'content' => $templateMsg->content,
                        'direction' => Message::DIRECTION_OUTBOUND,
                        'status' => Message::STATUS_SENT,
                        'message_type' => Message::TYPE_TEMPLATE,
                        'external_id' => $templateMsg->message_sid,
                        'sent_at' => $templateMsg->sent_at ?? $templateMsg->created_at,
                        'delivered_at' => $templateMsg->delivered_at,
                        'read_at' => $templateMsg->read_at,
                        'related_model_type' => $templateMsg->related_model_type,
                        'related_model_id' => $templateMsg->related_model_id,
                        'template_name' => $templateMsg->template_name ?? 'legacy_template',
                        'metadata' => [
                            'conversation_sid' => $conversationSid,
                            'twilio_conversations' => false,
                            'migrated_from_template' => true,
                            'original_message_id' => $templateMsg->id,
                        ],
                    ]);

                    $results['migrated']++;
                    
                    Log::info('Migrated template message to Conversations', [
                        'original_id' => $templateMsg->id,
                        'new_id' => $message->id,
                        'phone' => $normalizedPhone,
                        'conversation_sid' => $conversationSid,
                    ]);

                } catch (Exception $e) {
                    $results['errors'][] = [
                        'message_id' => $templateMsg->id,
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error('Failed to migrate template message', [
                        'message_id' => $templateMsg->id,
                        'phone' => $normalizedPhone,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Exception $e) {
            $results['errors'][] = [
                'type' => 'conversation_creation',
                'error' => $e->getMessage()
            ];
            
            Log::error('Failed to create conversation for migration', [
                'phone' => $normalizedPhone,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }

    /**
     * Get complete conversation history including template messages
     *
     * @param string $phone Phone number
     * @param int $limit Number of messages to return
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getCompleteConversationHistory(string $phone, int $limit = 50)
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);
        
        // Get all messages (both new bidirectional and migrated template messages)
        return Message::where(function ($query) use ($normalizedPhone) {
            $query->where('sender_phone', $normalizedPhone)
                  ->orWhere('recipient_phone', $normalizedPhone);
        })
        ->orderBy('created_at', 'asc')
        ->limit($limit)
        ->get();
    }

    /**
     * Sync template messages with Conversations for a specific phone
     *
     * @param string $phone Phone number
     * @return array Sync results
     */
    public function syncTemplateMessagesWithConversations(string $phone): array
    {
        $normalizedPhone = $this->normalizePhoneNumber($phone);
        $results = [
            'synced' => 0,
            'errors' => [],
            'conversation_sid' => null
        ];

        try {
            // Get or create conversation
            $conversationSid = $this->getOrCreateConversation($normalizedPhone);
            $results['conversation_sid'] = $conversationSid;

            // Get template messages that haven't been migrated yet
            $templateMessages = WhatsappMessage::where('recipient', $normalizedPhone)
                ->where('status', 'sent')
                ->whereNotExists(function ($query) use ($normalizedPhone) {
                    $query->select('id')
                          ->from('messages')
                          ->whereRaw('messages.metadata->>"$.original_message_id" = whatsapp_messages.id');
                })
                ->orderBy('created_at', 'asc')
                ->get();

            foreach ($templateMessages as $templateMsg) {
                try {
                    // Create message record in new system
                    $message = Message::create([
                        'sender_phone' => $this->fromNumber,
                        'recipient_phone' => $normalizedPhone,
                        'content' => $templateMsg->content,
                        'direction' => Message::DIRECTION_OUTBOUND,
                        'status' => Message::STATUS_SENT,
                        'message_type' => Message::TYPE_TEMPLATE,
                        'external_id' => $templateMsg->message_sid,
                        'sent_at' => $templateMsg->sent_at ?? $templateMsg->created_at,
                        'delivered_at' => $templateMsg->delivered_at,
                        'read_at' => $templateMsg->read_at,
                        'related_model_type' => $templateMsg->related_model_type,
                        'related_model_id' => $templateMsg->related_model_id,
                        'template_name' => $templateMsg->template_name ?? 'legacy_template',
                        'metadata' => [
                            'conversation_sid' => $conversationSid,
                            'twilio_conversations' => false,
                            'migrated_from_template' => true,
                            'original_message_id' => $templateMsg->id,
                        ],
                    ]);

                    $results['synced']++;
                    
                    Log::info('Synced template message with Conversations', [
                        'original_id' => $templateMsg->id,
                        'new_id' => $message->id,
                        'phone' => $normalizedPhone,
                        'conversation_sid' => $conversationSid,
                    ]);

                } catch (Exception $e) {
                    $results['errors'][] = [
                        'message_id' => $templateMsg->id,
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error('Failed to sync template message', [
                        'message_id' => $templateMsg->id,
                        'phone' => $normalizedPhone,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

        } catch (Exception $e) {
            $results['errors'][] = [
                'type' => 'conversation_creation',
                'error' => $e->getMessage()
            ];
            
            Log::error('Failed to create conversation for sync', [
                'phone' => $normalizedPhone,
                'error' => $e->getMessage(),
            ]);
        }

        return $results;
    }
}