<?php

namespace App\Http\Controllers\Examples;

use App\Http\Controllers\Controller;
use App\Services\WhapiWhatsAppService;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Professional;
use App\Models\HealthPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Exception;

class WhatsAppNotificationController extends Controller
{
    /**
     * The WhatsApp service instance.
     *
     * @var \App\Services\WhatsAppService
     */
    protected $whatsAppService;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\WhatsAppService  $whatsAppService
     * @return void
     */
    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Send an appointment reminder to a patient.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendAppointmentReminder(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'clinic_address' => 'required|string',
        ]);

        try {
            $appointment = Appointment::findOrFail($request->appointment_id);
            $patient = Patient::findOrFail($appointment->solicitation->patient_id);
            $professional = Professional::findOrFail($appointment->provider_id);
            
            $message = $this->whatsAppService->sendAppointmentReminderToPatient(
                $patient,
                $professional,
                $appointment,
                $request->clinic_address
            );

            return response()->json([
                'success' => true,
                'message' => 'Appointment reminder sent successfully',
                'data' => [
                    'sid' => $message->sid,
                    'status' => $message->status,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send appointment reminder', [
                'appointment_id' => $request->appointment_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send appointment reminder',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send an NPS survey after an appointment.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendNpsSurvey(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
        ]);

        try {
            $appointment = Appointment::findOrFail($request->appointment_id);
            $patient = Patient::findOrFail($appointment->solicitation->patient_id);
            $professional = Professional::findOrFail($appointment->provider_id);
            
            $message = $this->whatsAppService->sendNpsSurveyToPatient(
                $patient,
                $professional,
                $appointment
            );

            return response()->json([
                'success' => true,
                'message' => 'NPS survey sent successfully',
                'data' => [
                    'sid' => $message->sid,
                    'status' => $message->status,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send NPS survey', [
                'appointment_id' => $request->appointment_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send NPS survey',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send appointment notifications to all relevant parties.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendAppointmentNotifications(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'clinic_address' => 'required|string',
            'operator_phone' => 'sometimes|string',
            'operator_name' => 'sometimes|string',
        ]);

        try {
            $appointment = Appointment::findOrFail($request->appointment_id);
            $patient = Patient::findOrFail($appointment->solicitation->patient_id);
            $professional = Professional::findOrFail($appointment->provider_id);
            $healthPlan = HealthPlan::findOrFail($patient->health_plan_id);
            
            $results = [];
            
            // Send to patient
            $patientMessage = $this->whatsAppService->sendAppointmentReminderToPatient(
                $patient,
                $professional,
                $appointment,
                $request->clinic_address
            );
            $results['patient'] = [
                'sid' => $patientMessage->sid,
                'status' => $patientMessage->status,
            ];
            
            // Send to health plan if applicable
            if ($healthPlan) {
                $healthPlanMessage = $this->whatsAppService->sendAppointmentNotificationToHealthPlan(
                    $healthPlan,
                    $patient,
                    $professional,
                    $appointment,
                    $request->clinic_address
                );
                $results['health_plan'] = [
                    'sid' => $healthPlanMessage->sid,
                    'status' => $healthPlanMessage->status,
                ];
            }
            
            // Send to operator if provided
            if ($request->filled('operator_phone') && $request->filled('operator_name')) {
                $operatorMessage = $this->whatsAppService->sendAppointmentNotificationToOperator(
                    $request->operator_phone,
                    $request->operator_name,
                    $patient,
                    $professional,
                    $appointment,
                    $request->clinic_address
                );
                $results['operator'] = [
                    'sid' => $operatorMessage->sid,
                    'status' => $operatorMessage->status,
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Appointment notifications sent successfully',
                'data' => $results,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send appointment notifications', [
                'appointment_id' => $request->appointment_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send appointment notifications',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send appointment cancellation notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendAppointmentCancellation(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
        ]);

        try {
            $appointment = Appointment::findOrFail($request->appointment_id);
            $patient = Patient::findOrFail($appointment->solicitation->patient_id);
            
            $message = $this->whatsAppService->sendAppointmentCancellationToPatient($patient);

            return response()->json([
                'success' => true,
                'message' => 'Cancellation notification sent successfully',
                'data' => [
                    'sid' => $message->sid,
                    'status' => $message->status,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send cancellation notification', [
                'appointment_id' => $request->appointment_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send cancellation notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send appointment confirmation notification.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendAppointmentConfirmation(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
        ]);

        try {
            $appointment = Appointment::findOrFail($request->appointment_id);
            $patient = Patient::findOrFail($appointment->solicitation->patient_id);
            
            $message = $this->whatsAppService->sendAppointmentConfirmationToPatient($patient);

            return response()->json([
                'success' => true,
                'message' => 'Confirmation notification sent successfully',
                'data' => [
                    'sid' => $message->sid,
                    'status' => $message->status,
                ],
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send confirmation notification', [
                'appointment_id' => $request->appointment_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send confirmation notification',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
} 