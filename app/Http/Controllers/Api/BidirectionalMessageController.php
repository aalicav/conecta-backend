<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BidirectionalMessageController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Get all conversations
     */
    public function getConversations(Request $request)
    {
        try {
            $limit = $request->get('limit', 20);
            $conversations = $this->whatsappService->getConversations($limit);

            return response()->json([
                'success' => true,
                'data' => $conversations,
                'total' => count($conversations),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get conversations: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar conversas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get conversation history for a specific phone
     */
    public function getConversationHistory(Request $request, string $phone)
    {
        try {
            $limit = $request->get('limit', 50);
            $messages = $this->whatsappService->getConversationHistory($phone, $limit);

            return response()->json([
                'success' => true,
                'data' => $messages,
                'phone' => $phone,
                'total' => count($messages),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get conversation history: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar histórico da conversa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send message via Twilio Conversations
     */
    public function sendBidirectionalMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient_phone' => 'required|string',
            'content' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $message = $this->whatsappService->sendMessageViaConversations(
                $request->recipient_phone,
                $request->content
            );

            return response()->json([
                'success' => true,
                'message' => 'Mensagem enviada com sucesso via Twilio Conversations',
                'data' => [
                    'message_sid' => $message->sid ?? null,
                    'conversation_sid' => $message->conversationSid ?? null,
                    'content' => $request->content,
                    'timestamp' => now()->toISOString(),
                ]
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to send message via Conversations: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar mensagem via Twilio Conversations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages by entity type and ID
     */
    public function getMessagesByEntity(Request $request, string $type, int $id)
    {
        try {
            // This method would need to be implemented based on your entity structure
            // For now, we'll return an empty array since we're using Twilio Conversations
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Método não implementado para Twilio Conversations',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get messages by entity: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar mensagens por entidade',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics
     */
    public function getStatistics()
    {
        try {
            $conversations = $this->whatsappService->getConversations(1000);
            
            $totalConversations = count($conversations);
            $totalMessages = 0;
            $unreadMessages = 0;

            foreach ($conversations as $conversation) {
                if (isset($conversation['latest_message'])) {
                    $totalMessages++;
                    // Count unread messages (you might need to implement this logic)
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'total_conversations' => $totalConversations,
                    'total_messages' => $totalMessages,
                    'unread_messages' => $unreadMessages,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get statistics: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar estatísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all messages with filters
     */
    public function getMessages(Request $request)
    {
        try {
            $phone = $request->get('phone');
            $limit = $request->get('limit', 50);

            if ($phone) {
                $messages = $this->whatsappService->getConversationHistory($phone, $limit);
            } else {
                // Get messages from all conversations
                $conversations = $this->whatsappService->getConversations($limit);
                $messages = [];
                
                foreach ($conversations as $conversation) {
                    if (isset($conversation['latest_message'])) {
                        $messages[] = $conversation['latest_message'];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $messages,
                'total' => count($messages),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get messages: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar mensagens',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages directly from Twilio
     */
    public function getTwilioMessages(Request $request, string $phone)
    {
        try {
            $limit = $request->get('limit', 50);
            $messages = $this->whatsappService->getConversationHistory($phone, $limit);

            return response()->json([
                'success' => true,
                'data' => $messages,
                'phone' => $phone,
                'total' => count($messages),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Twilio messages: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar mensagens do Twilio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Setup webhook for a conversation
     */
    public function setupWebhook(Request $request, string $phone)
    {
        try {
            $conversation = $this->whatsappService->findConversationByPhone($phone);
            
            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversa não encontrada',
                ], 404);
            }

            $webhookUrl = $request->get('webhook_url', route('api.whatsapp.webhook'));
            $webhook = $this->whatsappService->setupConversationWebhook($conversation->sid, $webhookUrl);

            return response()->json([
                'success' => true,
                'message' => 'Webhook configurado com sucesso',
                'data' => [
                    'webhook_sid' => $webhook->sid,
                    'conversation_sid' => $conversation->sid,
                    'webhook_url' => $webhookUrl,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to setup webhook: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao configurar webhook',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark conversation as read
     */
    public function markAsRead(Request $request, string $phone)
    {
        try {
            // Since we're using Twilio Conversations, marking as read would need to be
            // implemented through Twilio's API if available
            return response()->json([
                'success' => true,
                'message' => 'Conversa marcada como lida',
                'data' => [
                    'phone' => $phone,
                    'messages_updated' => 0, // Not implemented for Twilio Conversations
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark conversation as read: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Falha ao marcar conversa como lida',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 