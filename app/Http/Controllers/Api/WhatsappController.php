<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use App\Services\WhapiWhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

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
     * Handle WhatsApp webhook (simplified - only for status updates)
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
                    return response($challenge, 200);
                }
                
                return response()->json(['error' => 'Verification failed'], 403);
            }
            
            // Process the webhook data for status updates only
            $webhookData = $request->all();
            
            Log::info('Processing WhatsApp webhook for status updates', $webhookData);
            
            // Handle status updates from Whapi
            $this->whatsappService->handleWebhook($webhookData);
            
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
} 