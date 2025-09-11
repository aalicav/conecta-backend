<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use App\Services\WhapiWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class WhatsappController extends Controller
{
    protected $whatsappService;

    public function __construct(WhapiWhatsAppService $whatsappService)
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
     * Send a new WhatsApp interactive message with buttons
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendInteractive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'body' => 'required|string',
            'buttons' => 'required|array|min:1|max:3',
            'buttons.*.id' => 'required|string|max:200',
            'buttons.*.title' => 'required|string|max:20',
            'related_model_type' => 'nullable|string',
            'related_model_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $message = $this->whatsappService->sendInteractiveMessage(
                $request->recipient,
                $request->body,
                $request->buttons,
                $request->related_model_type,
                $request->related_model_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Mensagem interativa enviada com sucesso',
                'data' => $message
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem interativa: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar mensagem interativa',
                'error' => $e->getMessage()
            ], 500);
        }
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
     * Test a template with fake data
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'template_name' => 'required|string',
            'template_type' => 'required|string|in:appointment_reminder,appointment_confirmation,appointment_cancellation,welcome,reset_password,exam_results,payment_receipt',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Dados fictícios baseados no tipo de template
            $fakeData = $this->generateFakeDataForTemplate($request->template_type);
            
            // Enviar mensagem de template com dados fictícios
            $message = $this->whatsappService->sendTemplateMessage(
                $request->recipient,
                $request->template_name,
                $fakeData
            );

            return response()->json([
                'success' => true,
                'message' => 'Template de teste enviado com sucesso',
                'data' => $message,
                'template_data' => $fakeData
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar template de teste: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar template de teste',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test a specific Conecta template with fake data
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testConectaTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'template_key' => 'required|string|in:agendamento_cliente,agendamento_cancelado,agendamento_confirmado,nps_survey,nps_survey_prestador,nps_pergunta,copy_menssagem_operadora',
            'custom_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            // Use the new sendTestMessage method with optional custom data
            $customData = $request->input('custom_data', []);
            
            $message = $this->whatsappService->sendTestMessage(
                $request->recipient,
                $request->template_key,
                $customData
            );
            
            // Get the test data used (either custom or default)
            $testData = empty($customData) 
                ? $this->whatsappService->generateDefaultTestData($request->template_key)
                : $customData;

            return response()->json([
                'success' => true,
                'message' => 'Template Conecta de teste enviado com sucesso',
                'template_key' => $request->template_key,
                'data' => $message,
                'template_data' => $testData
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar template Conecta de teste: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar template Conecta',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a test interactive message
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendInteractiveTest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'message_type' => 'required|string|in:appointment_notification,appointment_reminder,nps_survey,professional_evaluation,medlar_evaluation'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $recipient = $request->recipient;
            $messageType = $request->message_type;
            
            // Create mock data for testing
            $mockPatient = (object) [
                'name' => 'João Silva',
                'phone' => $recipient
            ];
            
            $mockAppointment = (object) [
                'id' => 123,
                'scheduled_date' => now()->addDays(2),
                'provider' => (object) ['name' => 'Dr. Maria Santos']
            ];

            $result = null;
            
            switch ($messageType) {
                case 'appointment_notification':
                    $result = $this->whatsappService->sendAppointmentNotificationToPatient($mockPatient, $mockAppointment);
                    break;
                case 'appointment_reminder':
                    $result = $this->whatsappService->sendAppointmentReminderToPatient($mockPatient, $mockAppointment);
                    break;
                case 'nps_survey':
                    $result = $this->whatsappService->sendNpsSurveyToPatient($mockPatient, $mockAppointment);
                    break;
                case 'professional_evaluation':
                    $result = $this->whatsappService->sendProfessionalEvaluationToPatient($mockPatient, $mockAppointment);
                    break;
                case 'medlar_evaluation':
                    $result = $this->whatsappService->sendMedlarEvaluationToPatient($mockPatient, $mockAppointment);
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => 'Mensagem interativa de teste enviada com sucesso',
                'message_type' => $messageType,
                'data' => $result
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem interativa de teste: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar mensagem interativa de teste',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a test message using a simple interface
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendSimpleTest(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'template_key' => 'required|string|in:agendamento_cliente,agendamento_cancelado,agendamento_confirmado,nps_survey,nps_survey_prestador,nps_pergunta,copy_menssagem_operadora',
            'values' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            Log::info('Template test request received', [
                'recipient' => $request->recipient,
                'template_key' => $request->template_key,
                'values' => $request->values
            ]);
            
            // Convert named values to indexed values if provided
            $testData = [];
            if ($request->has('values') && is_array($request->values)) {
                // Convert associative array to indexed array for template values
                $index = 1;
                foreach ($request->values as $value) {
                    $testData[(string)$index] = $value;
                    $index++;
                }
            }
            
            $message = $this->whatsappService->sendTestMessage(
                $request->recipient,
                $request->template_key,
                $testData
            );

            return response()->json([
                'success' => true,
                'message' => 'Mensagem de teste enviada com sucesso',
                'template_key' => $request->template_key,
                'data' => $message,
                'values_used' => empty($testData) 
                    ? $this->whatsappService->generateDefaultTestData($request->template_key) 
                    : $testData
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem de teste simples: ' . $e->getMessage(), [
                'recipient' => $request->recipient,
                'template_key' => $request->template_key,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar mensagem de teste',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate fake data for template testing based on template type
     * 
     * @param string $templateType
     * @return array
     */
    private function generateFakeDataForTemplate($templateType)
    {
        switch ($templateType) {
            case 'appointment_reminder':
                return [
                    'patient_name' => 'João Silva',
                    'doctor_name' => 'Dr. Ana Oliveira',
                    'specialty' => 'Cardiologia',
                    'date' => date('d/m/Y', strtotime('+2 days')),
                    'time' => '14:30',
                    'location' => 'Clínica Central, Sala 302',
                    'address' => 'Av. Paulista, 1000, São Paulo',
                    'confirmation_link' => 'https://exemplo.com/confirmar/12345'
                ];
                
            case 'appointment_confirmation':
                return [
                    'patient_name' => 'Maria Santos',
                    'appointment_id' => '123456',
                    'doctor_name' => 'Dr. Carlos Mendes',
                    'specialty' => 'Dermatologia',
                    'date' => date('d/m/Y', strtotime('+5 days')),
                    'time' => '10:15',
                    'location' => 'Centro Médico Saúde, Sala 105',
                    'preparation' => 'Jejum de 8 horas e trazer exames anteriores',
                    'cancellation_link' => 'https://exemplo.com/cancelar/123456'
                ];
                
            case 'appointment_cancellation':
                return [
                    'patient_name' => 'Pedro Almeida',
                    'appointment_id' => '789012',
                    'doctor_name' => 'Dra. Juliana Costa',
                    'specialty' => 'Oftalmologia',
                    'date' => date('d/m/Y', strtotime('+3 days')),
                    'time' => '16:00',
                    'cancellation_reason' => 'Médico indisponível',
                    'reschedule_link' => 'https://exemplo.com/reagendar/789012'
                ];
                
            case 'welcome':
                return [
                    'user_name' => 'Roberto Ferreira',
                    'company_name' => 'Conecta Saúde',
                    'account_type' => 'Paciente',
                    'access_link' => 'https://exemplo.com/acesso',
                    'support_email' => 'suporte@conectasaude.com',
                    'support_phone' => '(11) 3456-7890'
                ];
                
            case 'reset_password':
                return [
                    'user_name' => 'Luiza Carvalho',
                    'reset_code' => 'ABC123XYZ',
                    'expiration_time' => '60 minutos',
                    'reset_link' => 'https://exemplo.com/redefinir-senha?token=abc123xyz',
                    'support_email' => 'suporte@conectasaude.com'
                ];
                
            case 'exam_results':
                return [
                    'patient_name' => 'Fernanda Lima',
                    'exam_type' => 'Hemograma Completo',
                    'exam_date' => date('d/m/Y', strtotime('-3 days')),
                    'doctor_name' => 'Dr. Ricardo Sousa',
                    'doctor_comment' => 'Resultados dentro da normalidade',
                    'results_link' => 'https://exemplo.com/resultados/45678',
                    'follow_up_date' => date('d/m/Y', strtotime('+30 days'))
                ];
                
            case 'payment_receipt':
                return [
                    'patient_name' => 'André Martins',
                    'invoice_number' => 'FAT-2023-12345',
                    'payment_date' => date('d/m/Y'),
                    'payment_amount' => 'R$ 350,00',
                    'payment_method' => 'Cartão de Crédito',
                    'service_description' => 'Consulta Médica - Endocrinologia',
                    'receipt_link' => 'https://exemplo.com/recibo/12345'
                ];
                
            default:
                return [
                    'name' => 'Usuário Teste',
                    'date' => date('d/m/Y'),
                    'time' => date('H:i'),
                    'link' => 'https://exemplo.com/teste'
                ];
        }
    }

    /**
     * Send a template message with custom data
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendTemplate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string',
            'template_name' => 'required|string',
            'template_data' => 'required|array',
            'related_model_type' => 'nullable|string',
            'related_model_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $message = $this->whatsappService->sendTemplateMessage(
                $request->recipient,
                $request->template_name,
                $request->template_data,
                $request->related_model_type,
                $request->related_model_id
            );

            return response()->json([
                'success' => true,
                'message' => 'Template enviado com sucesso',
                'data' => $message
            ], 201);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar template personalizado: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao enviar template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle WhatsApp webhook (enhanced for both status updates and incoming messages)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function webhook(Request $request)
    {
        try {
            // Verify webhook if it's a GET verification request from Whapi
            if ($request->isMethod('get')) {
                $mode = $request->input('hub_mode');
                $token = $request->input('hub_verify_token');
                $challenge = $request->input('hub_challenge');
                
                $verifyToken = config('whapi.webhook_verify_token');
                
                if ($mode === 'subscribe' && $token === $verifyToken) {
                    Log::info('WhatsApp webhook verification successful');
                    return response()->json($challenge, 200);
                }
                
                Log::warning('WhatsApp webhook verification failed', [
                    'received_token' => $token,
                    'expected_token' => $verifyToken,
                    'mode' => $mode
                ]);
                return response()->json(['error' => 'Verification failed'], 403);
            }
            
            // Process the webhook data
            $webhookData = $request->all();
            
            Log::info('Processing WhatsApp webhook', [
                'data_type' => isset($webhookData['status']) ? 'status_update' : 
                              (isset($webhookData['messages']) ? 'incoming_message' : 'other'),
                'webhook_id' => $webhookData['id'] ?? 'unknown'
            ]);
            
            // Determine what type of webhook this is
            if (isset($webhookData['status'])) {
                // Status update for a message we sent
                Log::info('Processing status update', [
                    'message_id' => $webhookData['id'] ?? 'unknown',
                    'status' => $webhookData['status'] ?? 'unknown'
                ]);
                $this->whatsappService->handleWebhook($webhookData);
            } 
            elseif (isset($webhookData['messages']) && is_array($webhookData['messages'])) {
                // Incoming message(s)
                Log::info('Processing incoming messages', [
                    'count' => count($webhookData['messages']),
                    'first_message_id' => $webhookData['messages'][0]['id'] ?? 'unknown'
                ]);
                
                foreach ($webhookData['messages'] as $message) {
                    $this->processIncomingMessage($message);
                }
            }
            elseif (isset($webhookData['type']) && $webhookData['type'] === 'interactive') {
                // Interactive response (button click)
                Log::info('Processing interactive response', [
                    'message_id' => $webhookData['id'] ?? 'unknown',
                    'from' => $webhookData['from'] ?? 'unknown',
                    'button_id' => $webhookData['interactive']['button_reply']['id'] ?? 'unknown'
                ]);
                $this->whatsappService->processInteractiveResponse($webhookData);
            }
            else {
                // Unknown webhook type
                Log::warning('Received unknown webhook type', [
                    'data' => $webhookData
                ]);
            }
            
            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $request->all()
            ]);
            
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Process an incoming WhatsApp message
     * 
     * @param array $message The message data from webhook
     * @return void
     */
    protected function processIncomingMessage(array $message): void
    {
        try {
            // Extract key information
            $messageId = $message['id'] ?? null;
            $from = $message['from'] ?? null;
            $timestamp = $message['timestamp'] ?? null;
            $type = $message['type'] ?? 'text';
            
            if (!$messageId || !$from) {
                Log::warning('Incomplete message data received', ['message' => $message]);
                return;
            }
            
            Log::info('Processing incoming message', [
                'message_id' => $messageId,
                'from' => $from,
                'type' => $type,
                'timestamp' => $timestamp
            ]);
            
            // Normalize phone number
            try {
                $normalizedPhone = $this->whatsappService->normalizePhoneNumber($from);
            } catch (\Exception $e) {
                Log::warning('Failed to normalize phone number', [
                    'original' => $from,
                    'error' => $e->getMessage()
                ]);
                $normalizedPhone = $from;
            }
            
            // Save message to database
            $content = '';
            $mediaUrl = null;
            $mediaType = null;
            
            switch ($type) {
                case 'text':
                    $content = $message['text']['body'] ?? '';
                    break;
                    
                case 'image':
                    $mediaType = 'image';
                    $mediaUrl = $message['image']['url'] ?? null;
                    $content = $message['image']['caption'] ?? '[Image]';
                    break;
                    
                case 'document':
                    $mediaType = 'document';
                    $mediaUrl = $message['document']['url'] ?? null;
                    $content = $message['document']['filename'] ?? '[Document]';
                    break;
                    
                case 'audio':
                    $mediaType = 'audio';
                    $mediaUrl = $message['audio']['url'] ?? null;
                    $content = '[Audio]';
                    break;
                    
                case 'video':
                    $mediaType = 'video';
                    $mediaUrl = $message['video']['url'] ?? null;
                    $content = $message['video']['caption'] ?? '[Video]';
                    break;
                    
                case 'interactive':
                    $content = '[Interactive Message]';
                    if (isset($message['interactive']['button_reply'])) {
                        $content .= ' - Button: ' . ($message['interactive']['button_reply']['title'] ?? 'Unknown');
                    }
                    break;
                    
                default:
                    $content = "[{$type} message]";
            }
            
            // Save to database
            WhatsappMessage::create([
                'external_id' => $messageId,
                'sender' => $normalizedPhone,
                'recipient' => config('whapi.from_number', 'system'),
                'message' => $content,
                'direction' => 'inbound',
                'status' => 'received',
                'media_type' => $mediaType,
                'media_url' => $mediaUrl,
                'received_at' => $timestamp ? Carbon::createFromTimestamp($timestamp) : now(),
            ]);
            
            // Handle appointment-related keywords
            $this->handleKeywords($normalizedPhone, $content);
            
        } catch (\Exception $e) {
            Log::error('Error processing incoming message', [
                'error' => $e->getMessage(),
                'message' => $message
            ]);
        }
    }
    
    /**
     * Handle keywords in incoming messages
     * 
     * @param string $phone Normalized phone number
     * @param string $content Message content
     * @return void
     */
    protected function handleKeywords(string $phone, string $content): void
    {
        // Convert to lowercase for case-insensitive matching
        $lowerContent = strtolower($content);
        
        // Check for appointment-related keywords
        if (strpos($lowerContent, 'confirmar') !== false || 
            strpos($lowerContent, 'confirm') !== false) {
            
            $this->handleAppointmentConfirmation($phone);
        }
        elseif (strpos($lowerContent, 'cancelar') !== false || 
                strpos($lowerContent, 'cancel') !== false) {
                
            $this->handleAppointmentCancellation($phone);
        }
        elseif (strpos($lowerContent, 'reagendar') !== false || 
                strpos($lowerContent, 'remarcar') !== false || 
                strpos($lowerContent, 'reschedule') !== false) {
                
            $this->handleAppointmentReschedule($phone);
        }
        elseif (strpos($lowerContent, 'ajuda') !== false || 
                strpos($lowerContent, 'help') !== false || 
                strpos($lowerContent, 'suporte') !== false) {
                
            $this->sendHelpMessage($phone);
        }
    }
    
    /**
     * Handle appointment confirmation via text message
     * 
     * @param string $phone
     * @return void
     */
    protected function handleAppointmentConfirmation(string $phone): void
    {
        try {
            // Find the appointment by phone number
            $appointment = $this->findAppointmentByPhone($phone);
            
            if ($appointment) {
                // Only allow confirmation for scheduled appointments
                if ($appointment->status !== 'scheduled') {
                    $this->whatsappService->sendTextMessage(
                        $phone, 
                        "Seu agendamento não está mais pendente de confirmação. Status atual: {$appointment->status}."
                    );
                    return;
                }
                
                // Update appointment status to confirmed
                $appointment->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'patient_confirmed' => true,
                    'confirmation_method' => 'whatsapp_message'
                ]);
                
                // Send confirmation message
                $confirmationMessage = "✅ Agendamento confirmado com sucesso!\n\n" .
                                     "Data: " . $appointment->scheduled_date->format('d/m/Y') . "\n" .
                                     "Horário: " . $appointment->scheduled_date->format('H:i') . "\n" .
                                     "Profissional: " . $appointment->provider->name . "\n\n" .
                                     "Aguardamos você! Se precisar de algo, entre em contato conosco.";
                
                $this->whatsappService->sendTextMessage($phone, $confirmationMessage);
                
                // Log the confirmation
                Log::info('Appointment confirmed via WhatsApp message', [
                    'appointment_id' => $appointment->id,
                    'patient_phone' => $phone,
                    'confirmed_at' => now()
                ]);
            } else {
                // Send error message if appointment not found
                $this->whatsappService->sendTextMessage(
                    $phone, 
                    "❌ Não foi possível encontrar seu agendamento. Entre em contato conosco para mais informações."
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle appointment confirmation', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle appointment cancellation via text message
     * 
     * @param string $phone
     * @return void
     */
    protected function handleAppointmentCancellation(string $phone): void
    {
        try {
            // Find the appointment by phone number
            $appointment = $this->findAppointmentByPhone($phone);
            
            if ($appointment) {
                // Only allow cancellation for scheduled or confirmed appointments
                if ($appointment->status !== 'scheduled' && $appointment->status !== 'confirmed') {
                    $this->whatsappService->sendTextMessage(
                        $phone, 
                        "Seu agendamento não pode ser cancelado. Status atual: {$appointment->status}."
                    );
                    return;
                }
                
                // Update appointment status to cancelled
                $appointment->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_method' => 'whatsapp_message',
                    'cancelled_by_patient' => true
                ]);
                
                // Send cancellation confirmation message
                $cancellationMessage = "❌ Agendamento cancelado com sucesso!\n\n" .
                                     "Data: " . $appointment->scheduled_date->format('d/m/Y') . "\n" .
                                     "Horário: " . $appointment->scheduled_date->format('H:i') . "\n" .
                                     "Profissional: " . $appointment->provider->name . "\n\n" .
                                     "Se desejar reagendar, entre em contato conosco. " .
                                     "Obrigado por nos avisar!";
                
                $this->whatsappService->sendTextMessage($phone, $cancellationMessage);
                
                // Log the cancellation
                Log::info('Appointment cancelled via WhatsApp message', [
                    'appointment_id' => $appointment->id,
                    'patient_phone' => $phone,
                    'cancelled_at' => now()
                ]);
            } else {
                // Send error message if appointment not found
                $this->whatsappService->sendTextMessage(
                    $phone, 
                    "❌ Não foi possível encontrar seu agendamento. Entre em contato conosco para mais informações."
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle appointment cancellation', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Handle appointment reschedule request via text message
     * 
     * @param string $phone
     * @return void
     */
    protected function handleAppointmentReschedule(string $phone): void
    {
        try {
            // Find the appointment by phone number
            $appointment = $this->findAppointmentByPhone($phone);
            
            if ($appointment) {
                // Only allow rescheduling for scheduled or confirmed appointments
                if ($appointment->status !== 'scheduled' && $appointment->status !== 'confirmed') {
                    $this->whatsappService->sendTextMessage(
                        $phone, 
                        "Seu agendamento não pode ser reagendado. Status atual: {$appointment->status}."
                    );
                    return;
                }
                
                // Check if appointment was previously cancelled
                if ($appointment->cancelled_by_patient) {
                    $this->whatsappService->sendTextMessage(
                        $phone, 
                        "Este agendamento foi cancelado anteriormente e não pode ser reagendado. " .
                        "Por favor, solicite um novo agendamento entrando em contato conosco."
                    );
                    return;
                }
                
                // Send reschedule options message
                $rescheduleMessage = "🔄 Reagendamento solicitado!\n\n" .
                                   "Agendamento atual:\n" .
                                   "Data: " . $appointment->scheduled_date->format('d/m/Y') . "\n" .
                                   "Horário: " . $appointment->scheduled_date->format('H:i') . "\n" .
                                   "Profissional: " . $appointment->provider->name . "\n\n" .
                                   "Para reagendar, entre em contato conosco através dos canais:\n" .
                                   "📞 Telefone: (11) 99999-9999\n" .
                                   "💬 WhatsApp: (11) 99999-9999\n" .
                                   "🌐 Site: www.conectasaude.com\n\n" .
                                   "Ou responda esta mensagem com sua preferência de data e horário.";
                
                $this->whatsappService->sendTextMessage($phone, $rescheduleMessage);
                
                // Log the reschedule request
                Log::info('Appointment reschedule requested via WhatsApp message', [
                    'appointment_id' => $appointment->id,
                    'patient_phone' => $phone,
                    'requested_at' => now()
                ]);
                
                // Create a reschedule request record
                $this->createRescheduleRequest($appointment, $phone);
            } else {
                // Send error message if appointment not found
                $this->whatsappService->sendTextMessage(
                    $phone, 
                    "❌ Não foi possível encontrar seu agendamento. Entre em contato conosco para mais informações."
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle appointment reschedule', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Send help message with available commands
     * 
     * @param string $phone
     * @return void
     */
    protected function sendHelpMessage(string $phone): void
    {
        $helpMessage = "📱 *Comandos disponíveis:*\n\n" .
                      "• *confirmar* - Confirma seu próximo agendamento\n" .
                      "• *cancelar* - Cancela seu próximo agendamento\n" .
                      "• *reagendar* - Solicita reagendamento\n" .
                      "• *ajuda* - Mostra esta mensagem\n\n" .
                      "Para falar com um atendente, envie a palavra *atendente*.";
        
        $this->whatsappService->sendTextMessage($phone, $helpMessage);
    }
    
    /**
     * Find appointment by patient phone number
     * 
     * @param string $phone
     * @return \App\Models\Appointment|null
     */
    protected function findAppointmentByPhone(string $phone)
    {
        try {
            // Try to find patient by phone
            $patient = \App\Models\Patient::whereHas('phones', function ($query) use ($phone) {
                $query->where('number', 'like', '%' . substr($phone, -8) . '%');
            })->first();
            
            if (!$patient) {
                Log::warning('Patient not found for phone', ['phone' => $phone]);
                return null;
            }
            
            // Find the most recent upcoming appointment for this patient
            $appointment = \App\Models\Appointment::where('patient_id', $patient->id)
                ->whereIn('status', ['scheduled', 'confirmed'])
                ->where('scheduled_date', '>=', now()->subHours(3)) // Include appointments from the last 3 hours
                ->orderBy('scheduled_date', 'asc')
                ->first();
                
            if (!$appointment) {
                Log::warning('No upcoming appointments found for patient', [
                    'patient_id' => $patient->id,
                    'phone' => $phone
                ]);
            }
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error('Error finding appointment by phone', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Create reschedule request record
     * 
     * @param \App\Models\Appointment $appointment
     * @param string $phone
     * @return void
     */
    protected function createRescheduleRequest($appointment, string $phone): void
    {
        try {
            // Create a reschedule request
            \App\Models\AppointmentRescheduling::create([
                'appointment_id' => $appointment->id,
                'original_scheduled_date' => $appointment->scheduled_date,
                'reason' => 'Solicitado pelo paciente via WhatsApp',
                'requested_by_patient' => true,
                'status' => 'pending',
                'requested_by' => null // System generated
            ]);
            
            Log::info('Reschedule request created', [
                'appointment_id' => $appointment->id,
                'patient_phone' => $phone
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create reschedule request', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }
} 