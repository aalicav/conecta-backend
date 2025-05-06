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

class WhatsAppService
{
    /**
     * The Twilio client instance.
     *
     * @var \Twilio\Rest\Client
     */
    protected $client;

    /**
     * The WhatsApp number that messages will be sent from.
     *
     * @var string
     */
    protected $fromNumber;

    /**
     * The messaging service SID for Twilio.
     *
     * @var string|null
     */
    protected $messagingServiceSid;

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
    
    /**
     * The template builder instance.
     *
     * @var \App\Services\WhatsAppTemplateBuilder
     */
    protected $templateBuilder;

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
            'message' => $message,
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
            'message' => $caption,
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
                $message->message,
                $message->related_model_type,
                $message->related_model_id
            );
        } else {
            // Text message
            return $this->sendTextMessage(
                $message->recipient,
                $message->message,
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
                                    $this->updateMessageStatus($status);
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
    protected function updateMessageStatus(array $statusData)
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
    protected function updateTwilioMessageStatus(array $webhookData)
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
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance|null
     */
    public function sendTemplateMessage(string $to, string $contentSid, array $variables = [])
    {
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

            $message = $this->client->messages->create($to, $messageParams);
            
            Log::info('WhatsApp template message sent', [
                'to' => $to,
                'content_sid' => $contentSid,
                'message_sid' => $message->sid,
                'status' => $message->status,
            ]);

            return $message;
        } catch (Exception $e) {
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
            $templateMap = [
                'agendamento_cliente' => self::TEMPLATE_AGENDAMENTO_CLIENTE,
                'agendamento_cancelado' => self::TEMPLATE_AGENDAMENTO_CANCELADO,
                'agendamento_confirmado' => self::TEMPLATE_AGENDAMENTO_CONFIRMADO,
                'nps_survey' => self::TEMPLATE_NPS_SURVEY,
                'nps_survey_prestador' => self::TEMPLATE_NPS_SURVEY_PRESTADOR,
                'nps_pergunta' => self::TEMPLATE_NPS_PERGUNTA,
                'copy_menssagem_operadora' => self::TEMPLATE_COPY_MENSAGEM_OPERADORA
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
                $payload['variables'] ?? []
            );
        } catch (Exception $e) {
            Log::error('Failed to send WhatsApp template message', [
                'payload' => $payload,
                'error' => $e->getMessage(),
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
     * @return \Twilio\Rest\Api\V2010\Account\MessageInstance
     */
    public function sendAppointmentReminderToPatient(
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress
    ) {
        $token = $this->templateBuilder->generateAppointmentToken($appointment);
        
        $payload = $this->templateBuilder->buildAppointmentReminder(
            $patient,
            $professional,
            $appointment,
            $clinicAddress,
            $token
        );
        
        return $this->sendFromTemplate($payload);
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
        $payload = $this->templateBuilder->buildNpsSurvey(
            $patient,
            $professional,
            $appointment
        );
        
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
        $payload = $this->templateBuilder->buildProviderNpsSurvey(
            $patient,
            $professional,
            $appointment
        );
        
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
        $payload = $this->templateBuilder->buildNpsQuestion($patient, $appointment);
        
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
        $payload = $this->templateBuilder->buildOperatorNotification(
            $operatorPhone,
            $operatorName,
            $patient,
            $professional,
            $appointment,
            $clinicAddress
        );
        
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
    protected function detectMediaType(string $url)
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
    protected function formatNumber(string $number)
    {
        // Remove any non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $number);
        
        // Format as required by Twilio WhatsApp
        return "whatsapp:+{$number}";
    }
} 