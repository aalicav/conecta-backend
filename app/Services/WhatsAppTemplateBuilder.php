<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Professional;
use App\Models\HealthPlan;
use App\Models\Appointment;
use Carbon\Carbon;

class WhatsAppTemplateBuilder
{
    /**
     * Build the appointment reminder template data
     *
     * @param string $patientName
     * @param string $professionalName
     * @param string $specialty
     * @param string $appointmentDate
     * @param string $appointmentTime
     * @param string $clinicAddress
     * @param string $appointmentToken
     * @return array
     */
    public function buildAppointmentReminder(
        string $healthPlanName,
        string $patientName,
        string $professionalName,
        string $specialty,
        string $appointmentDate,
        string $appointmentTime,
        string $clinicAddress,
        string $appointmentId
    ): array {
        return [
            '1' => $healthPlanName,
            '2' => $patientName,
            '3' => $professionalName,
            '4' => $specialty,
            '5' => $appointmentDate,
            '6' => $appointmentTime,
            '7' => $clinicAddress,
            '8' => $appointmentId
        ];
    }

    /**
     * Build the NPS survey template data
     *
     * @param string $patientName
     * @param string $appointmentDate
     * @param string $professionalName
     * @param string $specialty
     * @param string $appointmentId
     * @return array
     */
    public function buildNpsSurvey(
        string $patientName,
        string $appointmentDate,
        string $professionalName,
        string $specialty,
        string $appointmentId
    ): array {
        return [
            '1' => $patientName,
            '2' => $appointmentDate,
            '3' => $professionalName,
            '4' => $specialty,
            '5' => $appointmentId
        ];
    }

    /**
     * Build the NPS provider survey template data
     *
     * @param string $patientName
     * @param string $professionalName
     * @param string $appointmentDate
     * @param string $appointmentId
     * @return array
     */
    public function buildNpsProviderSurvey(
        string $patientName,
        string $professionalName,
        string $appointmentDate,
        string $appointmentId
    ): array {
        return [
            '1' => $patientName,
            '2' => $professionalName,
            '3' => $appointmentDate,
            '4' => $appointmentId
        ];
    }

    /**
     * Build the NPS question template data
     *
     * @param string $appointmentId
     * @return array
     */
    public function buildNpsQuestion(string $appointmentId): array
    {
        return [
            '1' => $appointmentId
        ];
    }

    /**
     * Build the operator message template data
     *
     * @param string $operatorName
     * @param string $patientName
     * @param string $professionalName
     * @param string $specialty
     * @param string $appointmentDate
     * @param string $appointmentTime
     * @param string $clinicAddress
     * @return array
     */
    public function buildOperatorMessage(
        string $operatorName,
        string $patientName,
        string $professionalName,
        string $specialty,
        string $appointmentDate,
        string $appointmentTime,
        string $clinicAddress
    ): array {
        return [
            '1' => $operatorName,
            '2' => $patientName,
            '3' => $professionalName,
            '4' => $specialty,
            '5' => $appointmentDate,
            '6' => $appointmentTime,
            '7' => $clinicAddress
        ];
    }

    /**
     * Build the negotiation created template data
     *
     * @param string $userName
     * @param string $negotiationId
     * @return array
     */
    public function buildNegotiationCreated(
        string $userName,
        string $negotiationId
    ): array {
        return [
            '1' => $userName,
            '2' => $negotiationId
        ];
    }

    /**
     * Build the new professional template data
     *
     * @param string $professionalName
     * @param string $specialty
     * @param string $professionalId
     * @return array
     */
    public function buildNewProfessional(
        string $professionalName,
        string $specialty,
        string $professionalId
    ): array {
        return [
            '1' => $professionalName,
            '2' => $specialty,
            '3' => $professionalId
        ];
    }

    /**
     * Build provider availability confirmation template data
     *
     * @param string $providerName
     * @param string $patientName
     * @param string $serviceType
     * @param string $date
     * @param string $time
     * @param string $requestId
     * @return array
     */
    public function buildProviderAvailabilityRequest(
        string $providerName,
        string $patientName,
        string $serviceType,
        string $date,
        string $time,
        string $requestId
    ): array {
        return [
            '1' => $providerName,
            '2' => $patientName,
            '3' => $serviceType,
            '4' => $date,
            '5' => $time,
            '6' => $requestId
        ];
    }

    /**
     * Build service completion confirmation template data
     *
     * @param string $providerName
     * @param string $patientName
     * @param string $time
     * @param string $appointmentId
     * @return array
     */
    public function buildServiceCompletionRequest(
        string $providerName,
        string $patientName,
        string $time,
        string $appointmentId
    ): array {
        return [
            '1' => $providerName,
            '2' => $patientName,
            '3' => $time,
            '4' => $appointmentId
        ];
    }

    /**
     * Build payment notification template data
     *
     * @param string $providerName
     * @param string $amount
     * @param string $paymentId
     * @return array
     */
    public function buildPaymentNotification(
        string $providerName,
        string $amount,
        string $paymentId
    ): array {
        return [
            '1' => $providerName,
            '2' => $amount,
            '3' => $paymentId
        ];
    }

    /**
     * Build invoice reminder template data
     *
     * @param string $providerName
     * @param string $pendingCount
     * @param string $documentRequestId
     * @return array
     */
    public function buildInvoiceReminder(
        string $providerName,
        string $pendingCount,
        string $documentRequestId
    ): array {
        return [
            '1' => $providerName,
            '2' => $pendingCount,
            '3' => $documentRequestId
        ];
    }

    /**
     * Build critical task alert template data
     *
     * @param string $taskType
     * @param string $taskDescription
     * @param string $priority
     * @param string $taskId
     * @return array
     */
    public function buildCriticalTaskAlert(
        string $taskType,
        string $taskDescription,
        string $priority,
        string $taskId
    ): array {
        return [
            '1' => $taskType,
            '2' => $taskDescription,
            '3' => $priority,
            '4' => $taskId
        ];
    }

    /**
     * Build approval pending notification template data
     *
     * @param string $approvalType
     * @param string $requesterName
     * @param string $dateRequested
     * @param string $approvalId
     * @return array
     */
    public function buildApprovalPendingNotification(
        string $approvalType,
        string $requesterName,
        string $dateRequested,
        string $approvalId
    ): array {
        return [
            '1' => $approvalType,
            '2' => $requesterName,
            '3' => $dateRequested,
            '4' => $approvalId
        ];
    }

    /**
     * Build no-show notification template data
     *
     * @param string $patientName
     * @param string $appointmentDate
     * @param string $appointmentTime
     * @param string $providerName
     * @return array
     */
    public function buildNoShowNotification(
        string $patientName,
        string $appointmentDate,
        string $appointmentTime,
        string $providerName
    ): array {
        return [
            '1' => $patientName,
            '2' => $appointmentDate,
            '3' => $appointmentTime,
            '4' => $providerName
        ];
    }

    /**
     * Build exam preparation instructions template data
     *
     * @param string $patientName
     * @param string $examType
     * @param string $examDate
     * @param string $examTime
     * @param string $examId
     * @return array
     */
    public function buildExamPreparationInstructions(
        string $patientName,
        string $examType,
        string $examDate,
        string $examTime,
        string $examId
    ): array {
        return [
            '1' => $patientName,
            '2' => $examType,
            '3' => $examDate,
            '4' => $examTime,
            '5' => $examId
        ];
    }

    /**
     * Build appointment cancellation template payload
     *
     * @param Patient $patient
     * @return array
     */
    public function buildAppointmentCancellation(Patient $patient): array
    {
        return [
            'to' => $this->getPatientPhone($patient),
            'template' => 'agendamento_cancelado',
            'variables' => []
        ];
    }
    
    /**
     * Build appointment confirmation template payload
     *
     * @param Patient $patient
     * @return array
     */
    public function buildAppointmentConfirmation(Patient $patient): array
    {
        return [
            'to' => $this->getPatientPhone($patient),
            'template' => 'agendamento_confirmado',
            'variables' => []
        ];
    }
    
    /**
     * Build health plan notification template payload
     *
     * @param HealthPlan $healthPlan
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @param string $clinicAddress
     * @return array
     */
    public function buildHealthPlanNotification(
        HealthPlan $healthPlan,
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress
    ): array {
        // Get administrator or user phone number
        $to = $this->getHealthPlanAdministratorPhone($healthPlan);
        $operatorName = $healthPlan->name;
        
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($appointment->scheduled_time)->format('H:i');
        
        return [
            'to' => $to,
            'template' => 'copy_menssagem_operadora',
            'variables' => [
                '1' => $operatorName,
                '2' => $patient->name,
                '3' => $professional->name,
                '4' => $professional->specialty,
                '5' => $appointmentDate,
                '6' => $appointmentTime,
                '7' => $clinicAddress
            ]
        ];
    }
    
    /**
     * Get the patient's phone number
     *
     * @param Patient $patient
     * @return string
     */
    protected function getPatientPhone(Patient $patient): string
    {
        $phone = $patient->phones()->first();
        return $phone ? $phone->number : '';
    }
    
    /**
     * Get the professional's phone number
     *
     * @param Professional $professional
     * @return string
     */
    protected function getProfessionalPhone(Professional $professional): string
    {
        $phone = $professional->phones()->first();
        return $phone ? $phone->number : '';
    }
    
    /**
     * Get a health plan administrator's phone number
     *
     * @param HealthPlan $healthPlan
     * @return string
     */
    protected function getHealthPlanAdministratorPhone(HealthPlan $healthPlan): string
    {
        $admin = $healthPlan->administrators()->first();
        if ($admin && $admin->phones()->count() > 0) {
            return $admin->phones()->first()->number;
        }
        
        // Fall back to health plan contact phone
        $phone = $healthPlan->phones()->first();
        return $phone ? $phone->number : '';
    }
    
    /**
     * Generate a secure appointment token
     *
     * @param Appointment $appointment
     * @return string
     */
    public function generateAppointmentToken(Appointment $appointment): string
    {
        $payload = [
            'exp' => time() + (86400 * 30), // 30 days
            'agendamento_id' => $appointment->id
        ];
        
        return jwt_encode($payload, config('app.key'));
    }

    /**
     * Build the account created template data
     *
     * @param string $userName
     * @return array
     */
    public function buildAccountCreated(string $userName): array
    {
        return [
            '1' => $userName
        ];
    }

    /**
     * Build the negotiation internal approval required template data
     *
     * @param string $approverName
     * @param string $negotiationName
     * @param string $entityName
     * @param int $itemCount
     * @param string $approvalLevel
     * @param string $negotiationId
     * @return array
     */
    public function buildNegotiationInternalApprovalRequired(
        string $approverName,
        string $negotiationName,
        string $entityName,
        int $itemCount,
        string $approvalLevel,
        string $negotiationId
    ): array {
        return [
            '1' => $approverName,
            '2' => $negotiationName,
            '3' => $entityName,
            '4' => (string)$itemCount,
            '5' => $approvalLevel,
            '6' => $negotiationId
        ];
    }

    /**
     * Build the negotiation counter offer received template data
     *
     * @param string $userName
     * @param string $amount
     * @param string $itemName
     * @param string $negotiationName
     * @param string $negotiationId
     * @return array
     */
    public function buildNegotiationCounterOfferReceived(
        string $userName,
        string $amount,
        string $itemName,
        string $negotiationName,
        string $negotiationId
    ): array {
        return [
            '1' => $userName,
            '2' => $amount,
            '3' => $itemName,
            '4' => $negotiationName,
            '5' => $negotiationId
        ];
    }

    /**
     * Build the negotiation item response template data
     *
     * @param string $userName
     * @param string $itemName
     * @param string $amount
     * @param string $negotiationName
     * @param string $status
     * @param string $negotiationId
     * @return array
     */
    public function buildNegotiationItemResponse(
        string $userName,
        string $itemName,
        string $amount,
        string $negotiationName,
        string $status,
        string $negotiationId
    ): array {
        return [
            '1' => $userName,
            '2' => $itemName,
            '3' => $amount,
            '4' => $negotiationName,
            '5' => $status,
            '6' => $negotiationId
        ];
    }

    /**
     * Build the negotiation submitted to entity template data
     *
     * @param string $entityName
     * @param string $negotiationName
     * @param string $negotiationId
     * @return array
     */
    public function buildNegotiationSubmittedToEntity(
        string $entityName,
        string $negotiationName,
        string $negotiationId
    ): array {
        return [
            '1' => $entityName,
            '2' => $negotiationName,
            '3' => $negotiationId
        ];
    }

    /**
     * Build the solicitation invite template data
     *
     * @param string $providerName
     * @param string $procedureName
     * @param string $patientName
     * @param string $preferredDate
     * @param string $solicitationId
     * @return array
     */
    public function buildSolicitationInvite(
        string $providerName,
        string $procedureName,
        string $patientName,
        string $preferredDate,
        string $solicitationId
    ): array {
        return [
                '1' => $providerName,
                '2' => $procedureName,
                '3' => $patientName,
                '4' => $preferredDate,
                '5' => $solicitationId
        ];
    }

    /**
     * Build the health plan availability selected template data
     *
     * @param string $adminName
     * @param string $patientName
     * @param string $providerName
     * @param string $procedureName
     * @param string $scheduledDate
     * @param string $appointmentId
     * @return array
     */
    public function buildHealthPlanAvailabilitySelected(
        string $adminName,
        string $patientName,
        string $providerName,
        string $procedureName,
        string $scheduledDate,
        string $appointmentId
    ): array {
        return [
            '1' => $adminName,
            '2' => $patientName,
            '3' => $providerName,
            '4' => $procedureName,
            '5' => $scheduledDate,
            '6' => $appointmentId
        ];
    }

    /**
     * Build the availability selected template data
     *
     * @param string $providerName
     * @param string $patientName
     * @param string $procedureName
     * @param string $scheduledDate
     * @param string $appointmentId
     * @return array
     */
    public function buildAvailabilitySelected(
        string $providerName,
        string $patientName,
        string $procedureName,
        string $scheduledDate,
        string $appointmentId
    ): array {
        return [
            '1' => $providerName,
            '2' => $patientName,
            '3' => $procedureName,
            '4' => $scheduledDate,
            '5' => $appointmentId
        ];
    }

    /**
     * Build the availability rejected template data
     *
     * @param string $providerName
     * @param string $patientName
     * @param string $procedureName
     * @param string $availableDate
     * @param string $availableTime
     * @return array
     */
    public function buildAvailabilityRejected(
        string $providerName,
        string $patientName,
        string $procedureName,
        string $availableDate,
        string $availableTime
    ): array {
        return [
            '1' => $providerName,
            '2' => $patientName,
            '3' => $procedureName,
            '4' => $availableDate,
            '5' => $availableTime
        ];
    }

    /**
     * Build the appointment verification template data
     *
     * @param string $recipientName
     * @param string $companyName
     * @param string $appointmentTime
     * @param string $appointmentDate
     * @param string $professionalName
     * @param string $procedureName
     * @param string $clinicAddress
     * @param string $appointmentId
     * @return array
     */
    public function buildAppointmentVerification(
        string $recipientName,
        string $companyName,
        string $appointmentTime,
        string $appointmentDate,
        string $professionalName,
        string $procedureName,
        string $clinicAddress,
        string $appointmentId
    ): array {
        return [
            '1' => $recipientName,
            '2' => $companyName,
            '3' => $appointmentTime,
            '4' => $appointmentDate,
            '5' => $professionalName,
            '6' => $procedureName,
            '7' => $clinicAddress,
            '8' => $appointmentId
        ];
    }

    /**
     * Build the appointment confirmation response template data
     *
     * @param bool $confirmed
     * @return array
     */
    public function buildAppointmentConfirmationResponse(bool $confirmed): array
    {
        return [
            '1' => $confirmed ? 'confirmado' : 'cancelado',
            '2' => $confirmed ? 'Aguardamos você no horário agendado' : 'Se precisar reagendar, entre em contato conosco'
        ];
    }
} 