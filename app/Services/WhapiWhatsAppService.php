<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Phone;
use App\Models\HealthPlan;
use App\Models\User;
use App\Models\Appointment;

use App\Models\WhatsappMessage;
use App\Models\NpsResponse;
use App\Models\ProfessionalEvaluation;
use App\Models\MedlarEvaluation;
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
     * Validate and fix phone number before sending
     *
     * @param string $phone
     * @return string
     * @throws Exception
     */
    public function validateAndFixPhoneNumber(string $phone): string
    {
        try {
            Log::info('Phone number validation started', [
                'original_phone' => $phone,
                'length' => strlen($phone)
            ]);
            
            // First, try to normalize the number
            $normalized = $this->normalizePhoneNumber($phone);
            
            Log::info('Phone number normalized', [
                'original_phone' => $phone,
                'normalized_phone' => $normalized,
                'normalized_length' => strlen($normalized)
            ]);
            
            // Validate the final format
            if (strlen($normalized) === 12 && substr($normalized, 0, 2) === '55') {
                // Brazilian number with country code: 55 + DDD (2) + number (8)
                $ddd = substr($normalized, 2, 2);
                Log::info('Phone number validation - 12 digits', [
                    'normalized' => $normalized,
                    'ddd' => $ddd,
                    'ddd_valid' => ($ddd >= 11 && $ddd <= 99)
                ]);
                if ($ddd >= 11 && $ddd <= 99) {
                    return $normalized;
                }
            } elseif (strlen($normalized) === 13 && substr($normalized, 0, 2) === '55') {
                // Brazilian number with country code: 55 + DDD (2) + number (9)
                $ddd = substr($normalized, 2, 2);
                Log::info('Phone number validation - 13 digits', [
                    'normalized' => $normalized,
                    'ddd' => $ddd,
                    'ddd_valid' => ($ddd >= 11 && $ddd <= 99)
                ]);
                if ($ddd >= 11 && $ddd <= 99) {
                    return $normalized;
                }
            }
            
            Log::error('Phone number validation failed - invalid format', [
                'original_phone' => $phone,
                'normalized_phone' => $normalized,
                'normalized_length' => strlen($normalized)
            ]);
            
            throw new Exception("Invalid phone number format after normalization: {$normalized}");
            
        } catch (Exception $e) {
            Log::error("Phone number validation failed", [
                'original_phone' => $phone,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Send text message via Whapi
     */
    public function sendTextMessage(string $phone, string $message, ?string $relatedModelType = null, ?int $relatedModelId = null): array
    {
        try {
            // Validate and fix phone number before sending
            $formattedPhone = $this->validateAndFixPhoneNumber($phone);
            
            $payload = [
                'to' => $formattedPhone,
                'body' => $message, // Changed from 'text' to 'body' as required by WhatsApp API
            ];

            // Add optional parameters
            if (config('whapi.default_preview_url', true)) {
                $payload['preview_url'] = true;
            }

            Log::info('Sending WhatsApp message via Whapi', [
                'phone' => $phone,
                'formatted_phone' => $formattedPhone,
                'message' => $message,
                'payload' => $payload, // Log the payload for debugging
                'related_model_type' => $relatedModelType,
                'related_model_id' => $relatedModelId,
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
            $this->saveWhatsappMessage($phone, $message, 'outbound', 'sent', $responseData['id'] ?? null, $relatedModelType, $relatedModelId);

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
                'response' => method_exists($e, 'hasResponse') && $e->hasResponse() && method_exists($e, 'getResponse') ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, $message, 'outbound', 'failed', null, $relatedModelType, $relatedModelId);

            throw new Exception('Failed to send WhatsApp message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp message', [
                'phone' => $phone,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, $message, 'outbound', 'failed', null, $relatedModelType, $relatedModelId);

            throw $e;
        }
    }

    /**
     * Send media message via Whapi
     */
    public function sendMediaMessage(string $phone, string $mediaUrl, string $mediaType, string $caption = '', ?string $relatedModelType = null, ?int $relatedModelId = null): array
    {
        try {
            $formattedPhone = $this->validateAndFixPhoneNumber($phone);
            
            $endpoint = $this->getMediaEndpoint($mediaType);
            
            $payload = [
                'to' => $formattedPhone,
                'url' => $mediaUrl,
            ];

            if (!empty($caption)) {
                $payload['caption'] = $caption;
            }

            Log::info('Sending WhatsApp media message via Whapi', [
                'phone' => $phone,
                'formatted_phone' => $formattedPhone,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'caption' => $caption,
                'endpoint' => $endpoint,
                'payload' => $payload, // Log the payload for debugging
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
            $this->saveWhatsappMessage($phone, $caption ?: "[{$mediaType}]", 'outbound', 'sent', $responseData['id'] ?? null, $relatedModelType, $relatedModelId, $mediaType, $mediaUrl);

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
                'response' => method_exists($e, 'hasResponse') && $e->hasResponse() && method_exists($e, 'getResponse') ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, $caption ?: "[{$mediaType}]", 'outbound', 'failed', null, $relatedModelType, $relatedModelId, $mediaType, $mediaUrl);

            throw new Exception('Failed to send WhatsApp media message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp media message', [
                'phone' => $phone,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, $caption ?: "[{$mediaType}]", 'outbound', 'failed', null, $relatedModelType, $relatedModelId, $mediaType, $mediaUrl);

            throw $e;
        }
    }

    /**
     * Send template message via Whapi
     */
    public function sendTemplateMessage(string $phone, string $templateName, array $parameters = [], ?string $relatedModelType = null, ?int $relatedModelId = null): array
    {
        try {
            $formattedPhone = $this->validateAndFixPhoneNumber($phone);
            
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
                'payload' => $payload, // Log the payload for debugging
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
            $this->saveWhatsappMessage($phone, "[Template: {$templateName}]", 'outbound', 'sent', $responseData['id'] ?? null, $relatedModelType, $relatedModelId);

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
                'response' => method_exists($e, 'hasResponse') && $e->hasResponse() && method_exists($e, 'getResponse') ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, "[Template: {$templateName}]", 'outbound', 'failed', null, $relatedModelType, $relatedModelId);

            throw new Exception('Failed to send WhatsApp template message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp template message', [
                'phone' => $phone,
                'template' => $templateName,
                'parameters' => $parameters,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, "[Template: {$templateName}]", 'outbound', 'failed', null, $relatedModelType, $relatedModelId);

            throw $e;
        }
    }

    /**
     * Send interactive message with buttons via Whapi
     */
    public function sendInteractiveMessage(string $phone, string $body, array $buttons, ?string $relatedModelType = null, ?int $relatedModelId = null): array
    {
        try {
            $formattedPhone = $this->validateAndFixPhoneNumber($phone);
            
            // Ensure buttons are in the correct format for Whapi API
            $formattedButtons = [];
            foreach ($buttons as $button) {
                $formattedButtons[] = [
                    'type' => 'quick_reply',
                    'reply' => [
                        'id' => $button['id'] ?? '',
                        'title' => $button['title'] ?? ''
                    ]
                ];
            }
            
            $payload = [
                'to' => $formattedPhone,
                'body' => $body,
                'action' => [
                    'buttons' => $formattedButtons
                ]
            ];

            Log::info('Sending WhatsApp interactive message via Whapi', [
                'phone' => $phone,
                'formatted_phone' => $formattedPhone,
                'body' => $body,
                'original_buttons' => $buttons,
                'formatted_buttons' => $formattedButtons,
                'payload' => $payload,
                'related_model_type' => $relatedModelType,
                'related_model_id' => $relatedModelId,
            ]);

            $response = $this->httpClient->post('/messages/interactive', [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            Log::info('Whapi interactive API response', [
                'phone' => $phone,
                'response' => $responseData,
                'status_code' => $response->getStatusCode(),
            ]);

            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? null,
                'response' => $responseData,
            ];

        } catch (GuzzleException $e) {
            Log::error('Failed to send WhatsApp interactive message via Whapi', [
                'phone' => $phone,
                'body' => $body,
                'buttons' => $buttons,
                'error' => $e->getMessage(),
                'response' => method_exists($e, 'hasResponse') && $e->hasResponse() && method_exists($e, 'getResponse') ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            throw new Exception('Failed to send WhatsApp interactive message: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending WhatsApp interactive message', [
                'phone' => $phone,
                'body' => $body,
                'buttons' => $buttons,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send test message for Conecta templates
     */
    public function sendTestMessage(string $phone, string $templateKey, array $customData = []): array
    {
        try {
            $formattedPhone = $this->validateAndFixPhoneNumber($phone);
            
            // Use custom data if provided, otherwise generate default test data
            $templateData = empty($customData) 
                ? $this->generateDefaultTestData($templateKey)
                : $customData;
            
            $payload = [
                'to' => $formattedPhone,
                'template' => $templateKey,
                'parameters' => $templateData,
            ];

            Log::info('Sending Conecta test template via Whapi', [
                'phone' => $phone,
                'template_key' => $templateKey,
                'template_data' => $templateData,
                'payload' => $payload, // Log the payload for debugging
            ]);

            $response = $this->httpClient->post('/messages/template', [
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);
            
            Log::info('Whapi Conecta template API response', [
                'phone' => $phone,
                'response' => $responseData,
                'status_code' => $response->getStatusCode(),
            ]);

            // Save message to database
            $this->saveWhatsappMessage($phone, "[Conecta Template: {$templateKey}]", 'outbound', 'sent', $responseData['id'] ?? null);

            return [
                'success' => true,
                'message_id' => $responseData['id'] ?? null,
                'response' => $responseData,
            ];

        } catch (GuzzleException $e) {
            Log::error('Failed to send Conecta test template via Whapi', [
                'phone' => $phone,
                'template_key' => $templateKey,
                'error' => $e->getMessage(),
                'response' => method_exists($e, 'hasResponse') && $e->hasResponse() && method_exists($e, 'getResponse') ? $e->getResponse()->getBody()->getContents() : null,
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, "[Conecta Template: {$templateKey}]", 'outbound', 'failed');

            throw new Exception('Failed to send Conecta test template: ' . $e->getMessage());
        } catch (Exception $e) {
            Log::error('Unexpected error sending Conecta test template', [
                'phone' => $phone,
                'template_key' => $templateKey,
                'error' => $e->getMessage(),
            ]);

            // Save failed message to database
            $this->saveWhatsappMessage($phone, "[Conecta Template: {$templateKey}]", 'outbound', 'failed');

            throw $e;
        }
    }

    /**
     * Generate default test data for Conecta templates
     */
    public function generateDefaultTestData(string $templateKey): array
    {
        $currentDate = date('d/m/Y');
        $futureDate = date('d/m/Y', strtotime('+3 days'));
        
        switch ($templateKey) {
            case 'agendamento_cliente':
                return [
                    '1' => 'João da Silva',
                    '2' => 'Dra. Maria Fernandes',
                    '3' => 'Cardiologia',
                    '4' => $futureDate,
                    '5' => '14:30',
                    '6' => 'Av. Paulista, 1000, São Paulo - SP',
                    '7' => 'https://conecta.example.com/confirmar/123456'
                ];
                
            case 'agendamento_cancelado':
                return [
                    '1' => 'Ana Souza',
                    '2' => $futureDate,
                    '3' => 'Indisponibilidade do médico',
                    '4' => 'https://conecta.example.com/reagendar/123456'
                ];
                
            case 'agendamento_confirmado':
                return [
                    '1' => 'Pedro Santos',
                    '2' => $futureDate,
                    '3' => '10:15',
                    '4' => 'https://conecta.example.com/consulta/123456'
                ];
                
            case 'nps_survey':
                return [
                    '1' => 'Carlos Oliveira',
                    '2' => 'Dr. Ricardo Mendes',
                    '3' => $currentDate,
                    '4' => 'https://conecta.example.com/pesquisa/123456'
                ];
                
            case 'nps_survey_prestador':
                return [
                    '1' => 'Mariana Costa',
                    '2' => 'Dra. Juliana Alves',
                    '3' => $currentDate,
                    '4' => 'https://conecta.example.com/pesquisa-prestador/123456'
                ];
                
            case 'nps_pergunta':
                return [
                    '1' => 'Roberto Ferreira',
                    '2' => $currentDate,
                    '3' => 'https://conecta.example.com/nps/123456'
                ];
                
            case 'copy_menssagem_operadora':
                return [
                    '1' => 'Fernanda Lima',
                    '2' => 'Lucas Martins',
                    '3' => 'Dr. Paulo Cardoso',
                    '4' => 'Oftalmologia',
                    '5' => $futureDate,
                    '6' => '15:45',
                    '7' => 'Rua Augusta, 500, São Paulo - SP'
                ];
                
            default:
                return [
                    '1' => 'Usuário Teste',
                    '2' => $currentDate,
                    '3' => 'https://conecta.example.com/teste'
                ];
        }
    }

    /**
     * Resend a failed message
     */
    public function resendMessage(WhatsappMessage $message): WhatsappMessage
    {
        try {
            $result = $this->sendTextMessage($message->recipient, $message->message);
            
            // Update message status
            $message->update([
                'status' => 'sent',
                'sent_at' => now(),
                'external_id' => $result['message_id'],
            ]);
            
            return $message;
        } catch (Exception $e) {
            Log::error('Failed to resend message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            
            $message->update(['status' => 'failed']);
            throw $e;
        }
    }

    /**
     * Handle webhook for status updates and interactive responses
     */
    public function handleWebhook(array $webhookData): void
    {
        try {
            Log::info('Processing Whapi webhook', $webhookData);
            
            // Handle message status updates
            if (isset($webhookData['status'])) {
                $this->updateMessageStatus($webhookData);
            }
            
            // Handle interactive button responses
            if (isset($webhookData['type']) && $webhookData['type'] === 'interactive') {
                $this->processInteractiveResponse($webhookData);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle Whapi webhook', [
                'webhook_data' => $webhookData,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Process interactive button response
     */
    protected function processInteractiveResponse(array $webhookData): void
    {
        try {
            $messageId = $webhookData['id'] ?? null;
            $from = $webhookData['from'] ?? null;
            $interactiveData = $webhookData['interactive'] ?? null;
            
            if (!$messageId || !$from || !$interactiveData) {
                Log::warning('Invalid interactive response data', $webhookData);
                return;
            }
            
            $buttonId = $interactiveData['button_reply']['id'] ?? null;
            $buttonTitle = $interactiveData['button_reply']['title'] ?? null;
            
            if (!$buttonId) {
                Log::warning('No button ID found in interactive response', $webhookData);
                return;
            }
            
            Log::info('Processing interactive button response', [
                'message_id' => $messageId,
                'from' => $from,
                'button_id' => $buttonId,
                'button_title' => $buttonTitle,
                'interactive_data' => $interactiveData
            ]);
            
            // Here you can add custom logic to handle different button responses
            // For example, trigger specific actions based on button_id
            $this->handleButtonAction($from, $buttonId, $buttonTitle, $messageId);
            
        } catch (Exception $e) {
            Log::error('Failed to process interactive response', [
                'webhook_data' => $webhookData,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle specific button actions
     */
    protected function handleButtonAction(string $from, string $buttonId, string $buttonTitle, string $messageId): void
    {
        try {
            Log::info('Handling button action', [
                'from' => $from,
                'button_id' => $buttonId,
                'button_title' => $buttonTitle,
                'message_id' => $messageId
            ]);
            
            // Example: Handle different button actions
            switch ($buttonId) {
                case 'confirm_appointment':
                    $this->handleAppointmentConfirmation($from, $messageId);
                    break;
                case 'cancel_appointment':
                    $this->handleAppointmentCancellation($from, $messageId);
                    break;
                case 'reschedule_appointment':
                    $this->handleAppointmentReschedule($from, $messageId);
                    break;
                case 'contact_support':
                    $this->handleContactSupport($from, $messageId);
                    break;
                case 'nps_0_6':
                    $this->handleNpsResponse($from, $messageId, 'detractor', '0-6');
                    break;
                case 'nps_7_8':
                    $this->handleNpsResponse($from, $messageId, 'neutral', '7-8');
                    break;
                case 'nps_9_10':
                    $this->handleNpsResponse($from, $messageId, 'promoter', '9-10');
                    break;
                // Professional evaluation buttons
                case 'prof_0_6':
                    $this->handleProfessionalEvaluation($from, $messageId, 'detractor', '0-6');
                    break;
                case 'prof_7_8':
                    $this->handleProfessionalEvaluation($from, $messageId, 'neutral', '7-8');
                    break;
                case 'prof_9_10':
                    $this->handleProfessionalEvaluation($from, $messageId, 'promoter', '9-10');
                    break;
                // Medlar service evaluation buttons
                case 'medlar_0_6':
                    $this->handleMedlarEvaluation($from, $messageId, 'detractor', '0-6');
                    break;
                case 'medlar_7_8':
                    $this->handleMedlarEvaluation($from, $messageId, 'neutral', '7-8');
                    break;
                case 'medlar_9_10':
                    $this->handleMedlarEvaluation($from, $messageId, 'promoter', '9-10');
                    break;
                default:
                    Log::info('Unknown button action', [
                        'button_id' => $buttonId,
                        'from' => $from
                    ]);
                    break;
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle button action', [
                'from' => $from,
                'button_id' => $buttonId,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Handle appointment confirmation button
     */
    protected function handleAppointmentConfirmation(string $from, string $messageId): void
    {
        Log::info('Handling appointment confirmation', [
            'from' => $from,
            'message_id' => $messageId
        ]);
        
        try {
            // Find the appointment by phone number
            $appointment = $this->findAppointmentByPhone($from);
            
            if ($appointment) {
                // Update appointment status to confirmed
                $appointment->update([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'confirmation_method' => 'whatsapp_button'
                ]);
                
                // Send confirmation message
                $confirmationMessage = "✅ Agendamento confirmado com sucesso!\n\n" .
                                     "Data: " . $appointment->scheduled_date->format('d/m/Y') . "\n" .
                                     "Horário: " . $appointment->scheduled_date->format('H:i') . "\n" .
                                     "Profissional: " . $appointment->provider->name . "\n\n" .
                                     "Aguardamos você! Se precisar de algo, entre em contato conosco.";
                
                $this->sendTextMessage($from, $confirmationMessage);
                
                // Log the confirmation
                Log::info('Appointment confirmed via WhatsApp button', [
                    'appointment_id' => $appointment->id,
                    'patient_phone' => $from,
                    'confirmed_at' => now()
                ]);
            } else {
                // Send error message if appointment not found
                $errorMessage = "❌ Não foi possível encontrar seu agendamento. " .
                               "Entre em contato conosco para mais informações.";
                $this->sendTextMessage($from, $errorMessage);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle appointment confirmation', [
                'from' => $from,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            
            // Send error message to user
            $errorMessage = "❌ Ocorreu um erro ao confirmar seu agendamento. " .
                           "Entre em contato conosco para mais informações.";
            $this->sendTextMessage($from, $errorMessage);
        }
    }
    
    /**
     * Handle appointment cancellation button
     */
    protected function handleAppointmentCancellation(string $from, string $messageId): void
    {
        Log::info('Handling appointment cancellation', [
            'from' => $from,
            'message_id' => $messageId
        ]);
        
        try {
            // Find the appointment by phone number
            $appointment = $this->findAppointmentByPhone($from);
            
            if ($appointment) {
                // Update appointment status to cancelled
                $appointment->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'cancellation_method' => 'whatsapp_button'
                ]);
                
                // Send cancellation confirmation message
                $cancellationMessage = "❌ Agendamento cancelado com sucesso!\n\n" .
                                     "Data: " . $appointment->scheduled_date->format('d/m/Y') . "\n" .
                                     "Horário: " . $appointment->scheduled_date->format('H:i') . "\n" .
                                     "Profissional: " . $appointment->provider->name . "\n\n" .
                                     "Se desejar reagendar, entre em contato conosco. " .
                                     "Obrigado por nos avisar!";
                
                $this->sendTextMessage($from, $cancellationMessage);
                
                // Log the cancellation
                Log::info('Appointment cancelled via WhatsApp button', [
                    'appointment_id' => $appointment->id,
                    'patient_phone' => $from,
                    'cancelled_at' => now()
                ]);
                
                // Notify the provider about the cancellation
                $this->notifyProviderAboutCancellation($appointment);
                
            } else {
                // Send error message if appointment not found
                $errorMessage = "❌ Não foi possível encontrar seu agendamento. " .
                               "Entre em contato conosco para mais informações.";
                $this->sendTextMessage($from, $errorMessage);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle appointment cancellation', [
                'from' => $from,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            
            // Send error message to user
            $errorMessage = "❌ Ocorreu um erro ao cancelar seu agendamento. " .
                           "Entre em contato conosco para mais informações.";
            $this->sendTextMessage($from, $errorMessage);
        }
    }
    
    /**
     * Handle appointment reschedule button
     */
    protected function handleAppointmentReschedule(string $from, string $messageId): void
    {
        Log::info('Handling appointment reschedule', [
            'from' => $from,
            'message_id' => $messageId
        ]);
        
        try {
            // Find the appointment by phone number
            $appointment = $this->findAppointmentByPhone($from);
            
            if ($appointment) {
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
                
                $this->sendTextMessage($from, $rescheduleMessage);
                
                // Log the reschedule request
                Log::info('Appointment reschedule requested via WhatsApp button', [
                    'appointment_id' => $appointment->id,
                    'patient_phone' => $from,
                    'requested_at' => now()
                ]);
                
                // Create a reschedule request record
                $this->createRescheduleRequest($appointment, $from);
                
            } else {
                // Send error message if appointment not found
                $errorMessage = "❌ Não foi possível encontrar seu agendamento. " .
                               "Entre em contato conosco para mais informações.";
                $this->sendTextMessage($from, $errorMessage);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle appointment reschedule', [
                'from' => $from,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            
            // Send error message to user
            $errorMessage = "❌ Ocorreu um erro ao processar sua solicitação de reagendamento. " .
                           "Entre em contato conosco para mais informações.";
            $this->sendTextMessage($from, $errorMessage);
        }
    }
    
    /**
     * Handle contact support button
     */
    protected function handleContactSupport(string $from, string $messageId): void
    {
        Log::info('Handling contact support', [
            'from' => $from,
            'message_id' => $messageId
        ]);
        
        try {
            // Send support contact information
            $supportMessage = "📞 Central de Atendimento\n\n" .
                            "Estamos aqui para ajudar! Entre em contato conosco:\n\n" .
                            "🕐 Horário de funcionamento:\n" .
                            "Segunda a Sexta: 8h às 18h\n" .
                            "Sábado: 8h às 12h\n\n" .
                            "📱 Canais de atendimento:\n" .
                            "• WhatsApp: (11) 99999-9999\n" .
                            "• Telefone: (11) 3333-4444\n" .
                            "• Email: atendimento@conectasaude.com\n" .
                            "• Site: www.conectasaude.com\n\n" .
                            "💬 Ou responda esta mensagem com sua dúvida e nossa equipe entrará em contato!";
            
            $this->sendTextMessage($from, $supportMessage);
            
            // Log the support request
            Log::info('Support contact requested via WhatsApp button', [
                'patient_phone' => $from,
                'requested_at' => now()
            ]);
            
            // Create a support ticket
            $this->createSupportTicket($from);
            
        } catch (Exception $e) {
            Log::error('Failed to handle contact support', [
                'from' => $from,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            
            // Send simple error message
            $errorMessage = "❌ Ocorreu um erro. Entre em contato conosco pelo telefone (11) 3333-4444";
            $this->sendTextMessage($from, $errorMessage);
        }
    }
    
    /**
     * Handle NPS survey response
     */
    protected function handleNpsResponse(string $from, string $messageId, string $category, string $range): void
    {
        Log::info('Handling NPS response', [
            'from' => $from,
            'message_id' => $messageId,
            'category' => $category,
            'range' => $range
        ]);
        
        try {
            // Find the patient and appointment
            $appointment = $this->findAppointmentByPhone($from);
            
            if ($appointment) {
                // Save NPS response to database
                $this->saveNpsResponse($appointment, $category, $range, $from);
                
                // Send thank you message based on category
                $thankYouMessage = $this->getNpsThankYouMessage($category);
                if ($thankYouMessage) {
                    $this->sendTextMessage($from, $thankYouMessage);
                }
                
                // If it's a detractor, create a follow-up task
                if ($category === 'detractor') {
                    $this->createDetractorFollowUp($appointment, $from);
                }
                
            } else {
                // Send generic thank you message if appointment not found
                $thankYouMessage = "Obrigado por responder nossa pesquisa de satisfação!";
                $this->sendTextMessage($from, $thankYouMessage);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle NPS response', [
                'from' => $from,
                'message_id' => $messageId,
                'category' => $category,
                'error' => $e->getMessage()
            ]);
            
            // Send generic thank you message on error
            $thankYouMessage = "Obrigado por responder nossa pesquisa de satisfação!";
            $this->sendTextMessage($from, $thankYouMessage);
        }
    }
    
    /**
     * Get thank you message based on NPS category
     */
    protected function getNpsThankYouMessage(string $category): ?string
    {
        switch ($category) {
            case 'promoter':
                return "Obrigado pela excelente avaliação! Ficamos muito felizes em saber que você teve uma ótima experiência conosco. Sua recomendação é muito importante para nós! 😊";
            case 'neutral':
                return "Obrigado pelo seu feedback! Estamos sempre trabalhando para melhorar nossos serviços. Se tiver alguma sugestão, ficaremos felizes em ouvir! 🙂";
            case 'detractor':
                return "Obrigado pelo seu feedback honesto. Lamentamos que sua experiência não tenha sido a melhor. Gostaríamos de conversar com você para entender melhor e melhorar nossos serviços. Entre em contato conosco! 📞";
            default:
                return "Obrigado por responder nossa pesquisa de satisfação!";
        }
    }

    /**
     * Find appointment by patient phone number
     */
    protected function findAppointmentByPhone(string $phone): ?Appointment
    {
        try {
            // Normalize phone number for search
            $normalizedPhone = $this->normalizePhoneNumber($phone);
            
            // Find patient by phone
            $patient = Patient::whereHas('phones', function ($query) use ($normalizedPhone) {
                $query->where('number', 'like', '%' . substr($normalizedPhone, -8) . '%');
            })->first();
            
            if ($patient) {
                // Find the most recent appointment for this patient
                return Appointment::where('patient_id', $patient->id)
                    ->where('status', '!=', 'cancelled')
                    ->where('scheduled_date', '>=', now())
                    ->orderBy('scheduled_date', 'asc')
                    ->first();
            }
            
            return null;
            
        } catch (Exception $e) {
            Log::error('Failed to find appointment by phone', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Notify provider about appointment cancellation
     */
    protected function notifyProviderAboutCancellation(Appointment $appointment): void
    {
        try {
            if ($appointment->provider && $appointment->provider->phone) {
                $message = "📋 Cancelamento de Agendamento\n\n" .
                          "Paciente: " . $appointment->solicitation->patient->name . "\n" .
                          "Data: " . $appointment->scheduled_date->format('d/m/Y H:i') . "\n" .
                          "Cancelado via WhatsApp pelo paciente.\n\n" .
                          "Horário liberado para novos agendamentos.";
                
                $this->sendTextMessage($appointment->provider->phone, $message);
                
                Log::info('Provider notified about appointment cancellation', [
                    'appointment_id' => $appointment->id,
                    'provider_id' => $appointment->provider->id
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify provider about cancellation', [
                'appointment_id' => $appointment->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create reschedule request record
     */
    protected function createRescheduleRequest(Appointment $appointment, string $phone): void
    {
        try {
            // You can create a reschedule request table/model here
            // For now, we'll just log it
            Log::info('Reschedule request created', [
                'appointment_id' => $appointment->id,
                'patient_phone' => $phone,
                'current_date' => $appointment->scheduled_date,
                'requested_at' => now()
            ]);
            
            // TODO: Create actual reschedule request record in database
            // RescheduleRequest::create([
            //     'appointment_id' => $appointment->id,
            //     'patient_id' => $appointment->patient_id,
            //     'requested_at' => now(),
            //     'status' => 'pending',
            //     'request_method' => 'whatsapp_button'
            // ]);
            
        } catch (Exception $e) {
            Log::error('Failed to create reschedule request', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create support ticket
     */
    protected function createSupportTicket(string $phone): void
    {
        try {
            // You can create a support ticket table/model here
            // For now, we'll just log it
            Log::info('Support ticket created', [
                'patient_phone' => $phone,
                'created_at' => now(),
                'source' => 'whatsapp_button'
            ]);
            
            // TODO: Create actual support ticket record in database
            // SupportTicket::create([
            //     'patient_phone' => $phone,
            //     'status' => 'open',
            //     'priority' => 'medium',
            //     'source' => 'whatsapp_button',
            //     'created_at' => now()
            // ]);
            
        } catch (Exception $e) {
            Log::error('Failed to create support ticket', [
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Save NPS response to database
     */
    protected function saveNpsResponse(Appointment $appointment, string $category, string $range, string $phone): void
    {
        try {
            $npsResponse = NpsResponse::create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'category' => $category,
                'score_range' => $range,
                'phone' => $phone,
                'responded_at' => now(),
                'source' => 'whatsapp_button'
            ]);
            
            Log::info('NPS response saved to database', [
                'nps_response_id' => $npsResponse->id,
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'category' => $category,
                'range' => $range,
                'phone' => $phone,
                'responded_at' => $npsResponse->responded_at,
                'source' => 'whatsapp_button'
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to save NPS response to database', [
                'appointment_id' => $appointment->id,
                'category' => $category,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create follow-up task for detractors
     */
    protected function createDetractorFollowUp(Appointment $appointment, string $phone): void
    {
        try {
            // You can create a follow-up tasks table/model here
            // For now, we'll just log it
            Log::info('Detractor follow-up task created', [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'patient_phone' => $phone,
                'created_at' => now(),
                'priority' => 'high',
                'type' => 'detractor_follow_up'
            ]);
            
            // TODO: Create actual follow-up task record in database
            // FollowUpTask::create([
            //     'appointment_id' => $appointment->id,
            //     'patient_id' => $appointment->patient_id,
            //     'type' => 'detractor_follow_up',
            //     'priority' => 'high',
            //     'status' => 'pending',
            //     'assigned_to' => null, // Can be assigned to a specific team member
            //     'due_date' => now()->addHours(24), // Follow up within 24 hours
            //     'created_at' => now()
            // ]);
            
            // Notify the team about the detractor response
            $this->notifyTeamAboutDetractor($appointment, $phone);
            
        } catch (Exception $e) {
            Log::error('Failed to create detractor follow-up', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify team about detractor response
     */
    protected function notifyTeamAboutDetractor(Appointment $appointment, string $phone): void
    {
        try {
            // You can implement team notification logic here
            // For example, send email, Slack notification, etc.
            
            Log::info('Team notification sent about detractor response', [
                'appointment_id' => $appointment->id,
                'patient_phone' => $phone,
                'notified_at' => now()
            ]);
            
            // TODO: Implement actual team notification
            // Example: Send email to customer service team
            // Mail::to('customer-service@conectasaude.com')->send(new DetractorAlert($appointment, $phone));
            
        } catch (Exception $e) {
            Log::error('Failed to notify team about detractor', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle professional evaluation response
     */
    protected function handleProfessionalEvaluation(string $from, string $messageId, string $category, string $range): void
    {
        Log::info('Handling professional evaluation response', [
            'from' => $from,
            'message_id' => $messageId,
            'category' => $category,
            'range' => $range
        ]);
        
        try {
            // Find the patient and appointment
            $appointment = $this->findAppointmentByPhone($from);
            
            if ($appointment) {
                // Save professional evaluation to database
                $this->saveProfessionalEvaluation($appointment, $category, $range, $from);
                
                // Send thank you message based on category
                $thankYouMessage = $this->getProfessionalThankYouMessage($category);
                if ($thankYouMessage) {
                    $this->sendTextMessage($from, $thankYouMessage);
                }
                
                // If it's a detractor, create a follow-up task
                if ($category === 'detractor') {
                    $this->createProfessionalDetractorFollowUp($appointment, $from);
                }
                
            } else {
                // Send generic thank you message if appointment not found
                $thankYouMessage = "Obrigado por avaliar o profissional!";
                $this->sendTextMessage($from, $thankYouMessage);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle professional evaluation', [
                'from' => $from,
                'message_id' => $messageId,
                'category' => $category,
                'error' => $e->getMessage()
            ]);
            
            // Send generic thank you message on error
            $thankYouMessage = "Obrigado por avaliar o profissional!";
            $this->sendTextMessage($from, $thankYouMessage);
        }
    }

    /**
     * Handle Medlar service evaluation response
     */
    protected function handleMedlarEvaluation(string $from, string $messageId, string $category, string $range): void
    {
        Log::info('Handling Medlar evaluation response', [
            'from' => $from,
            'message_id' => $messageId,
            'category' => $category,
            'range' => $range
        ]);
        
        try {
            // Find the patient and appointment
            $appointment = $this->findAppointmentByPhone($from);
            
            if ($appointment) {
                // Save Medlar evaluation to database
                $this->saveMedlarEvaluation($appointment, $category, $range, $from);
                
                // Send thank you message based on category
                $thankYouMessage = $this->getMedlarThankYouMessage($category);
                if ($thankYouMessage) {
                    $this->sendTextMessage($from, $thankYouMessage);
                }
                
                // If it's a detractor, create a follow-up task
                if ($category === 'detractor') {
                    $this->createMedlarDetractorFollowUp($appointment, $from);
                }
                
            } else {
                // Send generic thank you message if appointment not found
                $thankYouMessage = "Obrigado por avaliar nosso atendimento!";
                $this->sendTextMessage($from, $thankYouMessage);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to handle Medlar evaluation', [
                'from' => $from,
                'message_id' => $messageId,
                'category' => $category,
                'error' => $e->getMessage()
            ]);
            
            // Send generic thank you message on error
            $thankYouMessage = "Obrigado por avaliar nosso atendimento!";
            $this->sendTextMessage($from, $thankYouMessage);
        }
    }

    /**
     * Get thank you message for professional evaluation based on category
     */
    protected function getProfessionalThankYouMessage(string $category): ?string
    {
        switch ($category) {
            case 'promoter':
                return "Obrigado pela excelente avaliação do profissional! Ficamos felizes em saber que você teve uma ótima experiência com o atendimento médico. Sua opinião é muito importante para nós! 😊";
            case 'neutral':
                return "Obrigado pelo seu feedback sobre o profissional! Estamos sempre trabalhando para melhorar a qualidade dos nossos profissionais. Se tiver alguma sugestão, ficaremos felizes em ouvir! 🙂";
            case 'detractor':
                return "Obrigado pelo seu feedback honesto sobre o profissional. Lamentamos que sua experiência não tenha sido a melhor. Gostaríamos de conversar com você para entender melhor e melhorar nossos serviços. Entre em contato conosco! 📞";
            default:
                return "Obrigado por avaliar o profissional!";
        }
    }

    /**
     * Get thank you message for Medlar evaluation based on category
     */
    protected function getMedlarThankYouMessage(string $category): ?string
    {
        switch ($category) {
            case 'promoter':
                return "Obrigado pela excelente avaliação do nosso atendimento! Ficamos muito felizes em saber que você teve uma ótima experiência com o serviço Medlar. Sua recomendação é muito importante para nós! 😊";
            case 'neutral':
                return "Obrigado pelo seu feedback sobre nosso atendimento! Estamos sempre trabalhando para melhorar nossos serviços. Se tiver alguma sugestão, ficaremos felizes em ouvir! 🙂";
            case 'detractor':
                return "Obrigado pelo seu feedback honesto sobre nosso atendimento. Lamentamos que sua experiência não tenha sido a melhor. Gostaríamos de conversar com você para entender melhor e melhorar nossos serviços. Entre em contato conosco! 📞";
            default:
                return "Obrigado por avaliar nosso atendimento!";
        }
    }

    /**
     * Save professional evaluation to database
     */
    protected function saveProfessionalEvaluation(Appointment $appointment, string $category, string $range, string $phone): void
    {
        try {
            $professionalEvaluation = ProfessionalEvaluation::create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'professional_id' => $appointment->provider_id,
                'category' => $category,
                'score_range' => $range,
                'phone' => $phone,
                'responded_at' => now(),
                'source' => 'whatsapp_button'
            ]);
            
            Log::info('Professional evaluation saved to database', [
                'professional_evaluation_id' => $professionalEvaluation->id,
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'professional_id' => $appointment->provider_id,
                'category' => $category,
                'range' => $range,
                'phone' => $phone,
                'responded_at' => $professionalEvaluation->responded_at,
                'source' => 'whatsapp_button'
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to save professional evaluation to database', [
                'appointment_id' => $appointment->id,
                'category' => $category,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Save Medlar evaluation to database
     */
    protected function saveMedlarEvaluation(Appointment $appointment, string $category, string $range, string $phone): void
    {
        try {
            $medlarEvaluation = MedlarEvaluation::create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'category' => $category,
                'score_range' => $range,
                'phone' => $phone,
                'responded_at' => now(),
                'source' => 'whatsapp_button'
            ]);
            
            Log::info('Medlar evaluation saved to database', [
                'medlar_evaluation_id' => $medlarEvaluation->id,
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'category' => $category,
                'range' => $range,
                'phone' => $phone,
                'responded_at' => $medlarEvaluation->responded_at,
                'source' => 'whatsapp_button'
            ]);
            
        } catch (Exception $e) {
            Log::error('Failed to save Medlar evaluation to database', [
                'appointment_id' => $appointment->id,
                'category' => $category,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create follow-up task for professional detractors
     */
    protected function createProfessionalDetractorFollowUp(Appointment $appointment, string $phone): void
    {
        try {
            // You can create a follow-up tasks table/model here
            // For now, we'll just log it
            Log::info('Professional detractor follow-up task created', [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'professional_id' => $appointment->provider_id,
                'patient_phone' => $phone,
                'created_at' => now(),
                'priority' => 'high',
                'type' => 'professional_detractor_follow_up'
            ]);
            
            // TODO: Create actual follow-up task record in database
            // FollowUpTask::create([
            //     'appointment_id' => $appointment->id,
            //     'patient_id' => $appointment->patient_id,
            //     'professional_id' => $appointment->provider_id,
            //     'type' => 'professional_detractor_follow_up',
            //     'priority' => 'high',
            //     'status' => 'pending',
            //     'assigned_to' => null, // Can be assigned to a specific team member
            //     'due_date' => now()->addHours(24), // Follow up within 24 hours
            //     'created_at' => now()
            // ]);
            
            // Notify the team about the professional detractor response
            $this->notifyTeamAboutProfessionalDetractor($appointment, $phone);
            
        } catch (Exception $e) {
            Log::error('Failed to create professional detractor follow-up', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create follow-up task for Medlar detractors
     */
    protected function createMedlarDetractorFollowUp(Appointment $appointment, string $phone): void
    {
        try {
            // You can create a follow-up tasks table/model here
            // For now, we'll just log it
            Log::info('Medlar detractor follow-up task created', [
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'patient_phone' => $phone,
                'created_at' => now(),
                'priority' => 'high',
                'type' => 'medlar_detractor_follow_up'
            ]);
            
            // TODO: Create actual follow-up task record in database
            // FollowUpTask::create([
            //     'appointment_id' => $appointment->id,
            //     'patient_id' => $appointment->patient_id,
            //     'type' => 'medlar_detractor_follow_up',
            //     'priority' => 'high',
            //     'status' => 'pending',
            //     'assigned_to' => null, // Can be assigned to a specific team member
            //     'due_date' => now()->addHours(24), // Follow up within 24 hours
            //     'created_at' => now()
            // ]);
            
            // Notify the team about the Medlar detractor response
            $this->notifyTeamAboutMedlarDetractor($appointment, $phone);
            
        } catch (Exception $e) {
            Log::error('Failed to create Medlar detractor follow-up', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify team about professional detractor response
     */
    protected function notifyTeamAboutProfessionalDetractor(Appointment $appointment, string $phone): void
    {
        try {
            // You can implement team notification logic here
            // For example, send email, Slack notification, etc.
            
            Log::info('Team notification sent about professional detractor response', [
                'appointment_id' => $appointment->id,
                'professional_id' => $appointment->provider_id,
                'patient_phone' => $phone,
                'notified_at' => now()
            ]);
            
            // TODO: Implement actual team notification
            // Example: Send email to customer service team
            // Mail::to('customer-service@conectasaude.com')->send(new ProfessionalDetractorAlert($appointment, $phone));
            
        } catch (Exception $e) {
            Log::error('Failed to notify team about professional detractor', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify team about Medlar detractor response
     */
    protected function notifyTeamAboutMedlarDetractor(Appointment $appointment, string $phone): void
    {
        try {
            // You can implement team notification logic here
            // For example, send email, Slack notification, etc.
            
            Log::info('Team notification sent about Medlar detractor response', [
                'appointment_id' => $appointment->id,
                'patient_phone' => $phone,
                'notified_at' => now()
            ]);
            
            // TODO: Implement actual team notification
            // Example: Send email to customer service team
            // Mail::to('customer-service@conectasaude.com')->send(new MedlarDetractorAlert($appointment, $phone));
            
        } catch (Exception $e) {
            Log::error('Failed to notify team about Medlar detractor', [
                'appointment_id' => $appointment->id,
                'phone' => $phone,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Update message status based on webhook data
     */
    protected function updateMessageStatus(array $webhookData): void
    {
        try {
            $messageId = $webhookData['id'] ?? null;
            $status = $webhookData['status'] ?? null;
            
            if (!$messageId || !$status) {
                Log::warning('Invalid webhook data for status update', $webhookData);
                return;
            }
            
            // Find message by external ID
            $message = WhatsappMessage::where('external_id', $messageId)->first();
            
            if ($message) {
                $message->update([
                    'status' => $this->mapWhapiStatus($status),
                    'sent_at' => $status === 'delivered' ? now() : $message->sent_at,
                ]);
                
                Log::info('Updated message status', [
                    'message_id' => $message->id,
                    'external_id' => $messageId,
                    'status' => $status,
                    'mapped_status' => $this->mapWhapiStatus($status),
                ]);
            } else {
                Log::warning('Message not found for status update', [
                    'external_id' => $messageId,
                    'status' => $status,
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('Failed to update message status', [
                'webhook_data' => $webhookData,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map Whapi status to internal status
     */
    protected function mapWhapiStatus(string $whapiStatus): string
    {
        $statusMap = [
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            'pending' => 'pending',
        ];
        
        return $statusMap[$whapiStatus] ?? 'pending';
    }

    /**
     * Save message to WhatsappMessage model
     */
    protected function saveWhatsappMessage(string $phone, string $content, string $direction, string $status, ?string $externalId = null, ?string $relatedModelType = null, ?int $relatedModelId = null, ?string $mediaType = null, ?string $mediaUrl = null): void
    {
        try {
            $messageData = [
                'recipient' => $phone,
                'message' => $content,
                'direction' => $direction,
                'status' => $status,
                'external_id' => $externalId,
                'related_model_type' => $relatedModelType,
                'related_model_id' => $relatedModelId,
                'media_type' => $mediaType,
                'media_url' => $mediaUrl,
            ];

            if ($direction === 'outbound') {
                $messageData['sender'] = config('whapi.from_number', 'system');
            }

            WhatsappMessage::create($messageData);

            Log::info('WhatsApp message saved to database', $messageData);

        } catch (Exception $e) {
            Log::error('Failed to save WhatsApp message to database', [
                'phone' => $phone,
                'content' => $content,
                'direction' => $direction,
                'error' => $e->getMessage(),
            ]);
        }
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
     * Format address information for messages
     */
    protected function formatAddressForMessage($address): string
    {
        if (!$address) {
            return '';
        }

        $formattedAddress = "📍 *Endereço:* {$address->street}, {$address->number}\n";
        
        if ($address->neighborhood) {
            $formattedAddress .= "🏘️ *Bairro:* {$address->neighborhood}\n";
        }
        
        if ($address->city && $address->state) {
            $formattedAddress .= "🌆 *Cidade:* {$address->city}/{$address->state}\n";
        }
        
        if ($address->zip_code) {
            $formattedAddress .= "📮 *CEP:* {$address->zip_code}\n";
        }
        
        if ($address->complement) {
            $formattedAddress .= "🏢 *Complemento:* {$address->complement}\n";
        }

        return $formattedAddress;
    }

    /**
     * Get contact information for messages
     */
    protected function getContactInformation(): string
    {
        return "📞 *Contato:*\n" .
               "• WhatsApp: (11) 99999-9999\n" .
               "• Telefone: (11) 3333-4444\n" .
               "• Email: atendimento@conectasaude.com\n" .
               "• Site: www.conectasaude.com";
    }

    /**
     * Get important instructions for appointments
     */
    protected function getAppointmentInstructions(): string
    {
        return "⚠️ *IMPORTANTE:*\n" .
               "• Chegue com 15 minutos de antecedência\n" .
               "• Traga documento de identidade e carteirinha do plano\n" .
               "• Em caso de dúvidas, entre em contato conosco\n" .
               "• Se precisar cancelar, avise com pelo menos 24h de antecedência";
    }

    /**
     * Utility methods
     */
    public function normalizePhoneNumber(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Validate Brazilian phone number format
        if (strlen($phone) === 11 && substr($phone, 0, 2) === '0') {
            // Remove leading 0 from 11-digit numbers (0 + DDD + number)
            $phone = substr($phone, 1);
        }
        
        // Check if it's a valid Brazilian number
        if (strlen($phone) === 10) {
            // 10 digits: DDD (2) + number (8)
            $ddd = substr($phone, 0, 2);
            $number = substr($phone, 2);
            
            // Validate DDD (Brazilian area codes are 11-99)
            if ($ddd >= 11 && $ddd <= 99) {
                $phone = '55' . $phone;
            } else {
                throw new Exception("Invalid Brazilian DDD: {$ddd}");
            }
        } elseif (strlen($phone) === 11) {
            // 11 digits: DDD (2) + number (9)
            $ddd = substr($phone, 0, 2);
            $number = substr($phone, 2);
            
            // Validate DDD (Brazilian area codes are 11-99)
            if ($ddd >= 11 && $ddd <= 99) {
                $phone = '55' . $phone;
            } else {
                throw new Exception("Invalid Brazilian DDD: {$ddd}");
            }
        } elseif (strlen($phone) === 12 && substr($phone, 0, 2) === '55') {
            // Already has country code
            // Validate DDD (Brazilian area codes are 11-99)
            $ddd = substr($phone, 2, 2);
            if ($ddd < 11 || $ddd > 99) {
                throw new Exception("Invalid Brazilian DDD: {$ddd}");
            }
        } elseif (strlen($phone) === 13 && substr($phone, 0, 2) === '55') {
            // Already has country code
            // Validate DDD (Brazilian area codes are 11-99)
            $ddd = substr($phone, 2, 2);
            if ($ddd < 11 || $ddd > 99) {
                throw new Exception("Invalid Brazilian DDD: {$ddd}");
            }
        } else {
            throw new Exception("Invalid phone number format. Expected 10-11 digits for Brazilian numbers, got " . strlen($phone));
        }
        
        return $phone;
    }

    public function formatNumber(string $phone): string
    {
        $phone = $this->normalizePhoneNumber($phone);
        return "+{$phone}";
    }

    /**
     * Legacy methods for backward compatibility (simplified)
     */
    public function processIncomingMessage(array $webhookData): void
    {
        // No longer processing incoming messages - only status updates
        Log::info('Incoming message processing disabled - Whapi is send-only');
    }

    public function processAppointmentVerificationResponse(string $response, string $phone): void
    {
        // No longer processing appointment verification responses
        Log::info('Appointment verification response processing disabled - Whapi is send-only');
    }

    // Alias methods for backward compatibility
    public function sendMessage(string $phone, string $content): array
    {
        return $this->sendTextMessage($phone, $content);
    }

    /**
     * Send appointment notification to patient with interactive buttons.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendAppointmentNotificationToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for appointment notification", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            // Get additional appointment information
            $solicitation = $appointment->solicitation;
            $provider = $appointment->provider;
            $address = $appointment->address;
            $procedure = $appointment->procedure;
            $specialty = $solicitation->medicalSpecialty ?? null;
            $healthPlan = $solicitation->healthPlan ?? null;

            // Build comprehensive message
            $message = "📅 *AGENDAMENTO CONFIRMADO*\n\n";
            $message .= "Olá {$patient->name}!\n\n";
            $message .= "Seu agendamento foi confirmado com sucesso:\n\n";
            
            // Professional information
            if ($provider) {
                $message .= "👨‍⚕️ *Profissional:* {$provider->name}\n";
                if ($specialty) {
                    $message .= "🩺 *Especialidade:* {$specialty->name}\n";
                }
            }
            
            // Date and time
            $message .= "📅 *Data:* " . $appointment->scheduled_date->format('d/m/Y') . "\n";
            $message .= "🕐 *Horário:* " . $appointment->scheduled_date->format('H:i') . "\n";
            
            // Procedure information
            if ($procedure) {
                $message .= "🔬 *Procedimento:* {$procedure->name}\n";
            }
            
            // Health plan information
            if ($healthPlan) {
                $message .= "🏥 *Plano de Saúde:* {$healthPlan->name}\n";
            }
            
            // Address information
            if ($address) {
                $message .= $this->formatAddressForMessage($address);
            }
            
            $message .= "\n" . $this->getAppointmentInstructions() . "\n\n";
            $message .= "Por favor, confirme sua presença:";

            $buttons = [
                [
                    'id' => 'confirm_appointment',
                    'title' => '✅ Confirmar Presença'
                ],
                [
                    'id' => 'cancel_appointment',
                    'title' => '❌ Cancelar'
                ],
                [
                    'id' => 'reschedule_appointment',
                    'title' => '🔄 Reagendar'
                ]
            ];

            return $this->sendInteractiveMessage(
                $patient->phone,
                $message,
                $buttons,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send appointment notification to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send appointment reminder to patient with interactive buttons.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendAppointmentReminderToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for appointment reminder", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            // Get additional appointment information
            $solicitation = $appointment->solicitation;
            $provider = $appointment->provider;
            $address = $appointment->address;
            $procedure = $appointment->procedure;
            $specialty = $solicitation->medicalSpecialty ?? null;
            $healthPlan = $solicitation->healthPlan ?? null;

            // Calculate time until appointment
            $now = now();
            $appointmentTime = $appointment->scheduled_date;
            $timeUntil = $now->diffForHumans($appointmentTime, true);

            // Build comprehensive reminder message
            $message = "⏰ *LEMBRETE DE AGENDAMENTO*\n\n";
            $message .= "Olá {$patient->name}!\n\n";
            $message .= "Este é um lembrete do seu agendamento:\n\n";
            
            // Professional information
            if ($provider) {
                $message .= "👨‍⚕️ *Profissional:* {$provider->name}\n";
                if ($specialty) {
                    $message .= "🩺 *Especialidade:* {$specialty->name}\n";
                }
            }
            
            // Date and time
            $message .= "📅 *Data:* " . $appointment->scheduled_date->format('d/m/Y') . "\n";
            $message .= "🕐 *Horário:* " . $appointment->scheduled_date->format('H:i') . "\n";
            $message .= "⏳ *Faltam:* {$timeUntil}\n";
            
            // Procedure information
            if ($procedure) {
                $message .= "🔬 *Procedimento:* {$procedure->name}\n";
            }
            
            // Health plan information
            if ($healthPlan) {
                $message .= "🏥 *Plano de Saúde:* {$healthPlan->name}\n";
            }
            
            // Address information (simplified for reminder)
            if ($address) {
                $message .= "📍 *Local:* {$address->street}, {$address->number}\n";
                if ($address->neighborhood) {
                    $message .= "🏘️ *Bairro:* {$address->neighborhood}\n";
                }
            }
            
            $message .= "\n⚠️ *LEMBRE-SE:*\n";
            $message .= "• Chegue com 15 minutos de antecedência\n";
            $message .= "• Traga documento de identidade e carteirinha\n";
            $message .= "• Em caso de dúvidas, entre em contato conosco\n\n";
            $message .= "Você pode:";

            $buttons = [
                [
                    'id' => 'confirm_appointment',
                    'title' => '✅ Confirmar Presença'
                ],
                [
                    'id' => 'cancel_appointment',
                    'title' => '❌ Cancelar'
                ],
                [
                    'id' => 'contact_support',
                    'title' => '📞 Suporte'
                ]
            ];

            return $this->sendInteractiveMessage(
                $patient->phone,
                $message,
                $buttons,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send appointment reminder to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send appointment cancellation to patient.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendAppointmentCancellationToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for appointment cancellation", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            // Get additional appointment information
            $solicitation = $appointment->solicitation;
            $provider = $appointment->provider;
            $procedure = $appointment->procedure;
            $specialty = $solicitation->medicalSpecialty ?? null;
            $healthPlan = $solicitation->healthPlan ?? null;

            // Build comprehensive cancellation message
            $message = "❌ *AGENDAMENTO CANCELADO*\n\n";
            $message .= "Olá {$patient->name}!\n\n";
            $message .= "Infelizmente, seu agendamento foi cancelado:\n\n";
            
            // Professional information
            if ($provider) {
                $message .= "👨‍⚕️ *Profissional:* {$provider->name}\n";
                if ($specialty) {
                    $message .= "🩺 *Especialidade:* {$specialty->name}\n";
                }
            }
            
            // Date and time
            $message .= "📅 *Data:* " . $appointment->scheduled_date->format('d/m/Y') . "\n";
            $message .= "🕐 *Horário:* " . $appointment->scheduled_date->format('H:i') . "\n";
            
            // Procedure information
            if ($procedure) {
                $message .= "🔬 *Procedimento:* {$procedure->name}\n";
            }
            
            // Health plan information
            if ($healthPlan) {
                $message .= "🏥 *Plano de Saúde:* {$healthPlan->name}\n";
            }
            
            $message .= "\n🔄 *PRÓXIMOS PASSOS:*\n";
            $message .= "• Entre em contato conosco para reagendar\n";
            $message .= "• Estamos disponíveis para ajudar\n";
            $message .= "• Lamentamos qualquer inconveniente\n\n";
            $message .= $this->getContactInformation();

            return $this->sendTextMessage(
                $patient->phone,
                $message,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send appointment cancellation to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send appointment confirmation to patient.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendAppointmentConfirmationToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for appointment confirmation", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            // Get additional appointment information
            $solicitation = $appointment->solicitation;
            $provider = $appointment->provider;
            $address = $appointment->address;
            $procedure = $appointment->procedure;
            $specialty = $solicitation->medicalSpecialty ?? null;
            $healthPlan = $solicitation->healthPlan ?? null;

            // Build comprehensive confirmation message
            $message = "✅ *AGENDAMENTO CONFIRMADO*\n\n";
            $message .= "Olá {$patient->name}!\n\n";
            $message .= "Perfeito! Seu agendamento foi confirmado com sucesso:\n\n";
            
            // Professional information
            if ($provider) {
                $message .= "👨‍⚕️ *Profissional:* {$provider->name}\n";
                if ($specialty) {
                    $message .= "🩺 *Especialidade:* {$specialty->name}\n";
                }
            }
            
            // Date and time
            $message .= "📅 *Data:* " . $appointment->scheduled_date->format('d/m/Y') . "\n";
            $message .= "🕐 *Horário:* " . $appointment->scheduled_date->format('H:i') . "\n";
            
            // Procedure information
            if ($procedure) {
                $message .= "🔬 *Procedimento:* {$procedure->name}\n";
            }
            
            // Health plan information
            if ($healthPlan) {
                $message .= "🏥 *Plano de Saúde:* {$healthPlan->name}\n";
            }
            
            // Address information
            if ($address) {
                $message .= $this->formatAddressForMessage($address);
            }
            
            $message .= "\n🎉 *TUDO PRONTO!*\n";
            $message .= "• Aguardamos você no horário marcado\n";
            $message .= "• Chegue com 15 minutos de antecedência\n";
            $message .= "• Traga documento de identidade e carteirinha\n";
            $message .= "• Em caso de dúvidas, entre em contato conosco\n\n";
            $message .= "Obrigado por escolher nossos serviços! 😊";

            return $this->sendTextMessage(
                $patient->phone,
                $message,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send appointment confirmation to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send NPS survey to patient with interactive rating buttons.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendNpsSurveyToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for NPS survey", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            $message = "Olá {$patient->name}! Como foi sua experiência conosco?\n\n" .
                      "Em uma escala de 0 a 10, qual a probabilidade de você nos recomendar para um amigo ou familiar?";

            $buttons = [
                [
                    'id' => 'nps_0_6',
                    'title' => '0-6 (Detratores)'
                ],
                [
                    'id' => 'nps_7_8',
                    'title' => '7-8 (Neutros)'
                ],
                [
                    'id' => 'nps_9_10',
                    'title' => '9-10 (Promotores)'
                ]
            ];

            return $this->sendInteractiveMessage(
                $patient->phone,
                $message,
                $buttons,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send NPS survey to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send professional evaluation survey to patient with interactive rating buttons.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendProfessionalEvaluationToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for professional evaluation", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            $message = "Olá {$patient->name}! Como foi sua experiência com o(a) {$appointment->provider->name}?\n\n" .
                      "Em uma escala de 0 a 10, como você avalia o atendimento do profissional?";

            $buttons = [
                [
                    'id' => 'prof_0_6',
                    'title' => '0-6 (Detratores)'
                ],
                [
                    'id' => 'prof_7_8',
                    'title' => '7-8 (Neutros)'
                ],
                [
                    'id' => 'prof_9_10',
                    'title' => '9-10 (Promotores)'
                ]
            ];

            return $this->sendInteractiveMessage(
                $patient->phone,
                $message,
                $buttons,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send professional evaluation to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send Medlar service evaluation survey to patient with interactive rating buttons.
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function sendMedlarEvaluationToPatient($patient, $appointment): array
    {
        try {
            if (!$patient || !$patient->phone) {
                Log::warning("No patient or phone number found for Medlar evaluation", [
                    'patient_id' => $patient->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No patient or phone number found'];
            }

            $message = "Olá {$patient->name}! Como foi sua experiência com o atendimento Medlar?\n\n" .
                      "Em uma escala de 0 a 10, como você avalia nosso serviço de agendamento e atendimento?";

            $buttons = [
                [
                    'id' => 'medlar_0_6',
                    'title' => '0-6 (Detratores)'
                ],
                [
                    'id' => 'medlar_7_8',
                    'title' => '7-8 (Neutros)'
                ],
                [
                    'id' => 'medlar_9_10',
                    'title' => '9-10 (Promotores)'
                ]
            ];

            return $this->sendInteractiveMessage(
                $patient->phone,
                $message,
                $buttons,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send Medlar evaluation to patient", [
                'patient_id' => $patient->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send appointment notification to health plan.
     *
     * @param HealthPlan $healthPlan
     * @param Appointment $appointment
     * @return array
     */
    public function sendAppointmentNotificationToHealthPlan($healthPlan, $appointment): array
    {
        try {
            if (!$healthPlan || !$healthPlan->phone) {
                Log::warning("No health plan or phone number found for appointment notification", [
                    'health_plan_id' => $healthPlan->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No health plan or phone number found'];
            }

            $message = "Novo agendamento criado: Paciente {$appointment->solicitation->patient->name} " .
                      "para {$appointment->scheduled_date->format('d/m/Y H:i')} " .
                      "com {$appointment->provider->name}.";

            return $this->sendTextMessage(
                $healthPlan->phone,
                $message,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send appointment notification to health plan", [
                'health_plan_id' => $healthPlan->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Send appointment notification to operator.
     *
     * @param User $operator
     * @param Appointment $appointment
     * @return array
     */
    public function sendAppointmentNotificationToOperator($operator, $appointment): array
    {
        try {
            if (!$operator || !$operator->phone) {
                Log::warning("No operator or phone number found for appointment notification", [
                    'operator_id' => $operator->id ?? 'null',
                    'appointment_id' => $appointment->id ?? 'null'
                ]);
                return ['success' => false, 'message' => 'No operator or phone number found'];
            }

            $message = "Novo agendamento criado: Paciente {$appointment->solicitation->patient->name} " .
                      "para {$appointment->scheduled_date->format('d/m/Y H:i')} " .
                      "com {$appointment->provider->name}.";

            return $this->sendTextMessage(
                $operator->phone,
                $message,
                'App\\Models\\Appointment',
                $appointment->id
            );

        } catch (\Exception $e) {
            Log::error("Failed to send appointment notification to operator", [
                'operator_id' => $operator->id ?? 'null',
                'appointment_id' => $appointment->id ?? 'null',
                'error' => $e->getMessage()
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
