<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use App\Services\WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WhatsappController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsappService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Get a paginated list of WhatsApp messages
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|string|in:pending,sent,delivered,read,failed',
            'recipient' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'sort_field' => 'nullable|string|in:id,recipient,status,created_at,sent_at',
            'sort_order' => 'nullable|string|in:asc,desc',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = WhatsappMessage::query();

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('recipient')) {
            $query->where('recipient', 'like', '%' . $request->recipient . '%');
        }

        if ($request->has('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Apply sorting
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortField, $sortOrder);

        // Paginate results
        $perPage = $request->input('per_page', 15);
        $messages = $query->paginate($perPage);

        return response()->json($messages);
    }

    /**
     * Get a specific WhatsApp message by ID
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $message = WhatsappMessage::findOrFail($id);
        return response()->json($message);
    }

    /**
     * Send a new WhatsApp text message
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendText(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'message' => 'required|string',
            'related_model_type' => 'nullable|string',
            'related_model_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = $this->whatsappService->sendTextMessage(
            $request->recipient,
            $request->message,
            $request->related_model_type,
            $request->related_model_id
        );

        return response()->json($message, 201);
    }

    /**
     * Send a new WhatsApp media message
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendMedia(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'media_url' => 'required|url',
            'media_type' => 'required|string|in:image,document,video,audio',
            'caption' => 'nullable|string',
            'related_model_type' => 'nullable|string',
            'related_model_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $message = $this->whatsappService->sendMediaMessage(
            $request->recipient,
            $request->media_url,
            $request->media_type,
            $request->caption,
            $request->related_model_type,
            $request->related_model_id
        );

        return response()->json($message, 201);
    }

    /**
     * Resend a failed WhatsApp message
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function resend($id)
    {
        $message = WhatsappMessage::findOrFail($id);

        if ($message->status !== WhatsappMessage::STATUS_FAILED) {
            return response()->json([
                'error' => 'Only failed messages can be resent'
            ], 422);
        }

        $updatedMessage = $this->whatsappService->resendMessage($message);

        return response()->json($updatedMessage);
    }

    /**
     * Get statistics for WhatsApp messages
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics()
    {
        $stats = [
            'total' => WhatsappMessage::count(),
            'pending' => WhatsappMessage::where('status', WhatsappMessage::STATUS_PENDING)->count(),
            'sent' => WhatsappMessage::where('status', WhatsappMessage::STATUS_SENT)->count(),
            'delivered' => WhatsappMessage::where('status', WhatsappMessage::STATUS_DELIVERED)->count(),
            'read' => WhatsappMessage::where('status', WhatsappMessage::STATUS_READ)->count(),
            'failed' => WhatsappMessage::where('status', WhatsappMessage::STATUS_FAILED)->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Handle WhatsApp webhook
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhook(Request $request)
    {
        try {
            // Verify webhook if it's a GET verification request from Facebook
            if ($request->isMethod('get')) {
                $mode = $request->input('hub_mode');
                $token = $request->input('hub_verify_token');
                $challenge = $request->input('hub_challenge');
                
                $verifyToken = config('services.whatsapp.webhook_verify_token');
                
                if ($mode === 'subscribe' && $token === $verifyToken) {
                    return response($challenge, 200);
                }
                
                return response()->json(['error' => 'Verification failed'], 403);
            }
            
            // Process the webhook data
            $webhookData = $request->all();
            
            Log::info('WhatsApp webhook received', $webhookData);
            
            $this->whatsappService->handleWebhook($webhookData);
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
} 