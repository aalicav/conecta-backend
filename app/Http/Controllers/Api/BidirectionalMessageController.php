<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Services\WhatsAppService;
use App\Http\Resources\MessageResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BidirectionalMessageController extends Controller
{
    public $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Get all conversations
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConversations(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $limit = $request->input('limit', 20);
        $conversations = $this->whatsappService->getConversations($limit);

        return response()->json([
            'success' => true,
            'data' => MessageResource::collection($conversations)
        ]);
    }

    /**
     * Get conversation history for a specific phone number
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function getConversationHistory(Request $request, string $phone)
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $limit = $request->input('limit', 50);
        $messages = $this->whatsappService->getConversationHistory($phone, $limit);

        return response()->json([
            'success' => true,
            'data' => MessageResource::collection($messages)
        ]);
    }

    /**
     * Send a manual message
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendBidirectionalMessage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient_phone' => 'required|string',
            'content' => 'required|string|max:1000',
            'related_model_type' => 'nullable|string',
            'related_model_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $message = $this->whatsappService->sendMessageViaConversations(
                $request->recipient_phone,
                $request->content,
                $request->related_model_type,
                $request->related_model_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Mensagem enviada com sucesso via Twilio Conversations',
                'data' => new MessageResource($message)
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to send manual message via Conversations: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar mensagem via Twilio Conversations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Twilio conversation messages
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTwilioMessages(Request $request, string $phone)
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Get or create conversation
            $conversationSid = $this->whatsappService->getOrCreateConversation($phone);
            
            // Get messages from Twilio Conversations
            $twilioMessages = $this->whatsappService->getTwilioConversationMessages($conversationSid, 50);

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation_sid' => $conversationSid,
                    'messages' => $twilioMessages,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get Twilio conversation messages: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao obter mensagens do Twilio Conversations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Setup webhook for a conversation
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function setupWebhook(Request $request, string $phone)
    {
        $validator = Validator::make($request->all(), [
            'webhook_url' => 'required|url',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Get or create conversation
            $conversationSid = $this->whatsappService->getOrCreateConversation($phone);
            
            // Setup webhook
            $webhook = $this->whatsappService->setupConversationWebhook(
                $conversationSid, 
                $request->webhook_url
            );

            return response()->json([
                'success' => true,
                'message' => 'Webhook configurado com sucesso',
                'data' => [
                    'conversation_sid' => $conversationSid,
                    'webhook_sid' => $webhook->sid,
                    'webhook_url' => $request->webhook_url,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to setup conversation webhook: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao configurar webhook',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages by entity type and ID
     *
     * @param Request $request
     * @param string $type
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMessagesByEntity(Request $request, string $type, int $id)
    {
        $validator = Validator::make(['type' => $type, 'id' => $id], [
            'type' => 'required|string|in:Patient,Professional,Clinic',
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $messages = Message::where('related_model_type', $type)
            ->where('related_model_id', $id)
            ->orderBy('created_at', 'asc')
            ->paginate($request->input('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => MessageResource::collection($messages),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ]
        ]);
    }

    /**
     * Get message statistics
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getStatistics(Request $request)
    {
        $stats = [
            'total_messages' => Message::count(),
            'inbound_messages' => Message::where('direction', Message::DIRECTION_INBOUND)->count(),
            'outbound_messages' => Message::where('direction', Message::DIRECTION_OUTBOUND)->count(),
            'failed_messages' => Message::where('status', Message::STATUS_FAILED)->count(),
            'delivered_messages' => Message::where('status', Message::STATUS_DELIVERED)->count(),
            'read_messages' => Message::where('status', Message::STATUS_READ)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Get messages with filters
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getMessages(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'direction' => 'nullable|string|in:inbound,outbound',
            'status' => 'nullable|string|in:pending,sent,delivered,read,failed',
            'phone' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = Message::query();

        // Apply filters
        if ($request->has('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('phone')) {
            $phone = $request->phone;
            $query->where(function ($q) use ($phone) {
                $q->where('sender_phone', 'like', "%{$phone}%")
                  ->orWhere('recipient_phone', 'like', "%{$phone}%");
            });
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Apply sorting
        $query->orderBy('created_at', 'desc');

        // Paginate results
        $perPage = $request->input('per_page', 50);
        $messages = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => MessageResource::collection($messages),
            'pagination' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'per_page' => $messages->perPage(),
                'total' => $messages->total(),
            ]
        ]);
    }

    /**
     * Migrate template messages to Conversations system
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function migrateTemplateMessages(Request $request, string $phone)
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $results = $this->whatsappService->migrateTemplateMessagesToConversations($phone);

            return response()->json([
                'success' => true,
                'message' => 'Migração de mensagens de template concluída',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to migrate template messages: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao migrar mensagens de template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sync template messages with Conversations
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function syncTemplateMessages(Request $request, string $phone)
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $results = $this->whatsappService->syncTemplateMessagesWithConversations($phone);

            return response()->json([
                'success' => true,
                'message' => 'Sincronização de mensagens de template concluída',
                'data' => $results
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to sync template messages: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao sincronizar mensagens de template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get complete conversation history including template messages
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCompleteHistory(Request $request, string $phone)
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $limit = $request->input('limit', 50);
            $messages = $this->whatsappService->getCompleteConversationHistory($phone, $limit);

            return response()->json([
                'success' => true,
                'data' => MessageResource::collection($messages)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get complete conversation history: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao obter histórico completo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark conversation as read
     *
     * @param Request $request
     * @param string $phone
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsRead(Request $request, string $phone)
    {
        $validator = Validator::make(['phone' => $phone], [
            'phone' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Mark all inbound messages for this phone as read
            $updated = Message::where('recipient_phone', $phone)
                ->where('direction', Message::DIRECTION_INBOUND)
                ->whereNull('read_at')
                ->update([
                    'status' => Message::STATUS_READ,
                    'read_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Conversa marcada como lida',
                'data' => [
                    'phone' => $phone,
                    'messages_updated' => $updated,
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