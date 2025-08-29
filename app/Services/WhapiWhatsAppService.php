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
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
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
            $formattedPhone = $this->formatNumber($phone);
            
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
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
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
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
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
     * Send test message for Conecta templates
     */
    public function sendTestMessage(string $phone, string $templateKey, array $customData = []): array
    {
        try {
            $formattedPhone = $this->formatNumber($phone);
            
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
                'response' => $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : null,
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
     * Handle webhook for status updates only
     */
    public function handleWebhook(array $webhookData): void
    {
        try {
            Log::info('Processing Whapi webhook for status updates', $webhookData);
            
            // Handle message status updates
            if (isset($webhookData['status'])) {
                $this->updateMessageStatus($webhookData);
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
     * Send appointment notification to patient.
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

            $message = "Olá {$patient->name}! Seu agendamento foi confirmado para " . 
                      $appointment->scheduled_date->format('d/m/Y H:i') . 
                      " com {$appointment->provider->name}.";

            return $this->sendTextMessage(
                $patient->phone,
                $message,
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
     * Send appointment reminder to patient.
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

            $message = "Olá {$patient->name}! Lembrete: seu agendamento está marcado para " . 
                      $appointment->scheduled_date->format('d/m/Y H:i') . 
                      " com {$appointment->provider->name}.";

            return $this->sendTextMessage(
                $patient->phone,
                $message,
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

            $message = "Olá {$patient->name}! Seu agendamento para " . 
                      $appointment->scheduled_date->format('d/m/Y H:i') . 
                      " foi cancelado. Entre em contato conosco para reagendar.";

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

            $message = "Olá {$patient->name}! Seu agendamento foi confirmado para " . 
                      $appointment->scheduled_date->format('d/m/Y H:i') . 
                      " com {$appointment->provider->name}. Aguardamos você!";

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
     * Send NPS survey to patient.
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

            $message = "Olá {$patient->name}! Como foi sua experiência conosco? " .
                      "Responda nossa pesquisa de satisfação: [link da pesquisa]";

            return $this->sendTextMessage(
                $patient->phone,
                $message,
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
