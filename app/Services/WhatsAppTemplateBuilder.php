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
     * Build appointment reminder template payload for a patient
     *
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @param string $clinicAddress
     * @param string $appointmentToken
     * @return array
     */
    public function buildAppointmentReminder(
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress,
        string $appointmentToken
    ): array {
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($appointment->scheduled_time)->format('H:i');
        
        $providerTitle = $professional->professional_type === 'doctor' ? 'Dr.' : 'Especialista';
        
        return [
            'to' => $this->getPatientPhone($patient),
            'template' => 'agendamento_cliente',
            'variables' => [
                '1' => $patient->name,
                '2' => "{$providerTitle} {$professional->name}",
                '3' => $professional->specialty,
                '4' => $appointmentDate,
                '5' => $appointmentTime,
                '6' => $clinicAddress,
                '7' => $appointmentToken
            ]
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
     * Build NPS survey template payload
     *
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @return array
     */
    public function buildNpsSurvey(
        Patient $patient,
        Professional $professional,
        Appointment $appointment
    ): array {
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        
        return [
            'to' => $this->getPatientPhone($patient),
            'template' => 'nps_survey',
            'variables' => [
                '1' => $patient->name,
                '2' => $appointmentDate,
                '3' => $professional->name,
                '4' => $professional->specialty,
                '5' => $appointment->id
            ]
        ];
    }
    
    /**
     * Build provider-specific NPS survey template payload
     *
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @return array
     */
    public function buildProviderNpsSurvey(
        Patient $patient, 
        Professional $professional,
        Appointment $appointment
    ): array {
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        
        return [
            'to' => $this->getPatientPhone($patient),
            'template' => 'nps_survey_prestador',
            'variables' => [
                '2' => $professional->name,
                '3' => $appointmentDate,
                '4' => $appointment->id
            ]
        ];
    }
    
    /**
     * Build NPS question template payload
     *
     * @param Patient $patient
     * @param Appointment $appointment
     * @return array
     */
    public function buildNpsQuestion(Patient $patient, Appointment $appointment): array
    {
        return [
            'to' => $this->getPatientPhone($patient),
            'template' => 'nps_pergunta',
            'variables' => [
                '1' => $appointment->id
            ]
        ];
    }
    
    /**
     * Build operator notification template payload
     *
     * @param string $operatorPhone
     * @param string $operatorName
     * @param Patient $patient
     * @param Professional $professional
     * @param Appointment $appointment
     * @param string $clinicAddress
     * @return array
     */
    public function buildOperatorNotification(
        string $operatorPhone,
        string $operatorName,
        Patient $patient,
        Professional $professional,
        Appointment $appointment,
        string $clinicAddress
    ): array {
        $appointmentDate = Carbon::parse($appointment->scheduled_date)->format('d/m/Y');
        $appointmentTime = Carbon::parse($appointment->scheduled_time)->format('H:i');
        
        return [
            'to' => $operatorPhone,
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
} 