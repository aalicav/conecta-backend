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
    const TEMPLATE_NEGOTIATION_CREATED = 'HXac200d96a677a800c1cf940884d25457';
    const TEMPLATE_NEW_PROFESSIONAL = 'HX537cd859a24a94b9bb7dcf8f87705ea9';
    const TEMPLATE_DISPONIBILIDADE_PRESTADOR = 'SID_A_SER_INSERIDO_DISPONIBILIDADE_PRESTADOR';
    const TEMPLATE_CONFIRMACAO_ATENDIMENTO = 'SID_A_SER_INSERIDO_CONFIRMACAO_ATENDIMENTO';
    const TEMPLATE_PAGAMENTO_REALIZADO = 'SID_A_SER_INSERIDO_PAGAMENTO_REALIZADO';
    const TEMPLATE_LEMBRETE_NOTA_FISCAL = 'SID_A_SER_INSERIDO_LEMBRETE_NOTA_FISCAL';
    const TEMPLATE_TAREFA_CRITICA = 'SID_A_SER_INSERIDO_TAREFA_CRITICA';
    const TEMPLATE_APROVACAO_PENDENTE = 'SID_A_SER_INSERIDO_APROVACAO_PENDENTE';
    const TEMPLATE_PACIENTE_AUSENTE = 'SID_A_SER_INSERIDO_PACIENTE_AUSENTE';
    const TEMPLATE_PREPARO_EXAME = 'SID_A_SER_INSERIDO_PREPARO_EXAME';

    const TEMPLATE_SOLICITATION_INVITE = '';
    
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
        
        $specialty = $professional->specialty ? $professional->specialty->name : 'Especialista';
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($appointment->scheduled_date)->format('H:i');
        
        $payload = $this->templateBuilder->buildAppointmentReminder(
            $patient->name,
            $professional->name,
            $specialty,
            $appointmentDate,
            $appointmentTime,
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
        $specialty = $professional->specialty ? $professional->specialty->name : 'Especialista';
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        
        $payload = $this->templateBuilder->buildNpsSurvey(
            $patient->name,
            $appointmentDate,
            $professional->name,
            $specialty,
            (string)$appointment->id
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
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        
        $payload = $this->templateBuilder->buildNpsProviderSurvey(
            $patient->name,
            $professional->name,
            $appointmentDate,
            (string)$appointment->id
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
        $payload = $this->templateBuilder->buildNpsQuestion((string)$appointment->id);
        
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
        $specialty = $professional->specialty ? $professional->specialty->name : 'Especialista';
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
            $specialty = $professional->specialty ? $professional->specialty->name : 'Especialista';
            
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
                Log::warning("Cannot send verification message: patient #{$patient->id} has no phone number");
                return null;
            }
            
            $message = "Olá {$patient->name}, confirme sua presença no atendimento agendado para " .
                Carbon::parse($appointment->scheduled_date)->format('d/m/Y') . " às " .
                Carbon::parse($appointment->scheduled_date)->format('H:i') . ".\n\n" .
                "Acesse o link: {$verificationUrl}";
            
            return $this->sendTextMessage(
                $patient->phone,
                $message,
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
            
            $message = "Olá {$provider->name}, confirme a realização do atendimento agendado para " .
                Carbon::parse($appointment->scheduled_date)->format('d/m/Y') . " às " .
                Carbon::parse($appointment->scheduled_date)->format('H:i') . ".\n\n" .
                "Acesse o link: {$verificationUrl}";
            
            return $this->sendTextMessage(
                $provider->phone,
                $message,
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
        $variables = $this->templateBuilder->buildNpsQuestion($appointmentId);
        $this->sendTemplateMessage($to, 'nps_pergunta', $variables);
    }
}