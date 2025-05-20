<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AppointmentConfirmationService
{
    /**
     * @var NotificationService
     */
    protected $notificationService;
    
    /**
     * @var DocumentGenerationService
     */
    protected $documentService;

    /**
     * Create a new service instance.
     *
     * @param NotificationService $notificationService
     * @param DocumentGenerationService $documentService
     * @return void
     */
    public function __construct(
        NotificationService $notificationService, 
        DocumentGenerationService $documentService
    ) {
        $this->notificationService = $notificationService;
        $this->documentService = $documentService;
    }

    /**
     * Get appointments pending 48-hour confirmation
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingForConfirmation()
    {
        // Get appointments scheduled in ~48 hours
        $targetDate = Carbon::now()->addHours(48);
        
        return Appointment::where('status', Appointment::STATUS_SCHEDULED)
            ->whereBetween('scheduled_date', [
                $targetDate->copy()->subHours(2), // 46 hours from now
                $targetDate->copy()->addHours(2)  // 50 hours from now
            ])
            ->with(['solicitation.patient', 'provider'])
            ->get();
    }

    /**
     * Confirm an appointment by the operational team
     * 
     * @param Appointment $appointment
     * @param int $userId User confirming the appointment
     * @param bool $patientConfirmed Whether patient confirmed
     * @param bool $providerConfirmed Whether provider confirmed
     * @return bool
     */
    public function confirmPreAppointment(Appointment $appointment, int $userId, bool $patientConfirmed, bool $providerConfirmed)
    {
        try {
            Log::info('Processing pre-appointment confirmation', [
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'patient_confirmed' => $patientConfirmed,
                'provider_confirmed' => $providerConfirmed
            ]);
            
            // Only proceed if both patient and provider confirmed
            if (!$patientConfirmed || !$providerConfirmed) {
                Log::warning('Cannot confirm appointment - missing confirmation', [
                    'appointment_id' => $appointment->id,
                    'patient_confirmed' => $patientConfirmed,
                    'provider_confirmed' => $providerConfirmed
                ]);
                
                // If not confirmed, we could cancel the appointment here
                if (!$patientConfirmed && !$providerConfirmed) {
                    $this->cancelAppointment($appointment, $userId, 'Neither patient nor provider confirmed appointment');
                    return false;
                }
                
                return false;
            }
            
            // Update appointment status
            $appointment->status = Appointment::STATUS_CONFIRMED;
            $appointment->confirmed_date = Carbon::now();
            $appointment->confirmed_by = $userId;
            $appointment->save();
            
            // Notify financial department
            $this->notifyFinancialDepartment($appointment);
            
            // Generate and send appointment guide
            $this->generateAppointmentGuide($appointment);
            
            // Send confirmation notification
            $this->notificationService->notifyAppointmentConfirmed($appointment);
            
            Log::info('Appointment confirmed successfully', ['appointment_id' => $appointment->id]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error confirming appointment: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
            
            return false;
        }
    }

    /**
     * Register the attendance confirmation on the day of the appointment
     * 
     * @param Appointment $appointment
     * @param bool $patientAttended
     * @param bool $providerConfirmed
     * @param string|null $guidePath Path to uploaded signed guide
     * @return bool
     */
    public function confirmAttendance(Appointment $appointment, bool $patientAttended, bool $providerConfirmed, ?string $guidePath = null)
    {
        try {
            Log::info('Processing attendance confirmation', [
                'appointment_id' => $appointment->id,
                'patient_attended' => $patientAttended,
                'provider_confirmed' => $providerConfirmed,
                'guide_uploaded' => !empty($guidePath)
            ]);
            
            // Check if appointment should be marked as completed or missed
            if ($patientAttended && $providerConfirmed) {
                // Mark as completed
                $appointment->status = Appointment::STATUS_COMPLETED;
                $appointment->completed_date = Carbon::now();
                $appointment->patient_attended = true;
                
                // Store the guide upload path if available
                if ($guidePath) {
                    $appointment->signed_guide_path = $guidePath;
                }
                
                $appointment->save();
                
                // If payment exists, mark as approved
                if ($appointment->payment) {
                    $appointment->payment->status = 'approved';
                    $appointment->payment->save();
                }
                
                // Send completion notification
                $this->notificationService->notifyAppointmentCompleted($appointment);
                
                Log::info('Appointment marked as completed', ['appointment_id' => $appointment->id]);
            } else {
                // Mark as missed
                $appointment->status = Appointment::STATUS_MISSED;
                $appointment->patient_attended = false;
                $appointment->save();
                
                // Notify about missed appointment
                $this->notificationService->notifyAppointmentMissed($appointment);
                
                // Notify health plan about absenteeism
                $this->notifyHealthPlanAboutAbsenteeism($appointment);
                
                // Process financial implications of missed appointment
                $this->processAbsenteeismFinancial($appointment);
                
                Log::info('Appointment marked as missed', ['appointment_id' => $appointment->id]);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error confirming attendance: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
            
            return false;
        }
    }

    /**
     * Cancel an appointment
     * 
     * @param Appointment $appointment
     * @param int $userId
     * @param string|null $reason
     * @return bool
     */
    public function cancelAppointment(Appointment $appointment, int $userId, ?string $reason = null)
    {
        try {
            $appointment->status = Appointment::STATUS_CANCELLED;
            $appointment->cancelled_date = Carbon::now();
            $appointment->cancelled_by = $userId;
            $appointment->notes = $reason ?: $appointment->notes;
            $appointment->save();
            
            // Cancel any pending payment
            if ($appointment->payment && $appointment->payment->status === 'pending') {
                $appointment->payment->status = 'cancelled';
                $appointment->payment->save();
            }
            
            // Send cancellation notification
            $this->notificationService->notifyAppointmentCancelled($appointment, $reason);
            
            Log::info('Appointment cancelled successfully', [
                'appointment_id' => $appointment->id,
                'user_id' => $userId,
                'reason' => $reason
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error cancelling appointment: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
            
            return false;
        }
    }

    /**
     * Generate the appointment guide document
     * 
     * @param Appointment $appointment
     * @return string|null Path to the generated guide
     */
    public function generateAppointmentGuide(Appointment $appointment)
    {
        try {
            // Get necessary data for the guide
            $patient = $appointment->solicitation->patient;
            $procedure = $appointment->solicitation->tuss;
            $provider = $appointment->provider;
            $healthPlan = $appointment->solicitation->healthPlan;
            
            // Generate the guide document
            $guidePath = $this->documentService->generateAppointmentGuide(
                $appointment->id,
                $patient,
                $provider,
                $procedure,
                $healthPlan,
                $appointment->scheduled_date,
                $appointment->price
            );
            
            // Store the guide path
            $appointment->guide_path = $guidePath;
            $appointment->save();
            
            // Send the guide to the provider
            $this->notificationService->sendGuideToProvider($appointment, $guidePath);
            
            Log::info('Appointment guide generated and sent', [
                'appointment_id' => $appointment->id,
                'guide_path' => $guidePath
            ]);
            
            return $guidePath;
        } catch (\Exception $e) {
            Log::error('Error generating appointment guide: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
            
            return null;
        }
    }

    /**
     * Notify the financial department about a confirmed appointment
     * 
     * @param Appointment $appointment
     */
    protected function notifyFinancialDepartment(Appointment $appointment)
    {
        try {
            // Create a payment record if not exists
            if (!$appointment->payment) {
                Payment::create([
                    'appointment_id' => $appointment->id,
                    'original_amount' => $appointment->price,
                    'final_amount' => $appointment->price,
                    'status' => 'pending',
                    'due_date' => Carbon::now()->addDays(1) // Payment typically due the day before appointment
                ]);
            }
            
            // Trigger financial team notification
            // Implement according to your notification system
            
            Log::info('Financial department notified about confirmed appointment', [
                'appointment_id' => $appointment->id,
                'amount' => $appointment->price
            ]);
        } catch (\Exception $e) {
            Log::error('Error notifying financial department: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Notify health plan about patient absenteeism
     * 
     * @param Appointment $appointment
     */
    protected function notifyHealthPlanAboutAbsenteeism(Appointment $appointment)
    {
        try {
            $healthPlan = $appointment->solicitation->healthPlan;
            $patient = $appointment->solicitation->patient;
            
            // Implement health plan notification about absenteeism
            // This could be via email, API call, or system notification
            
            Log::info('Health plan notified about patient absenteeism', [
                'appointment_id' => $appointment->id,
                'health_plan_id' => $healthPlan->id,
                'patient_id' => $patient->id
            ]);
        } catch (\Exception $e) {
            Log::error('Error notifying health plan about absenteeism: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Process financial implications of missed appointment
     * 
     * @param Appointment $appointment
     */
    protected function processAbsenteeismFinancial(Appointment $appointment)
    {
        try {
            // Check if payment was already made
            $payment = $appointment->payment;
            
            if ($payment && $payment->status === 'paid') {
                // Create credit note or register for future use
                // Depends on business rules
                
                // Notify financial team about the missed appointment with payment
                
                Log::info('Financial department notified about missed appointment with payment', [
                    'appointment_id' => $appointment->id,
                    'payment_id' => $payment->id,
                    'amount' => $payment->final_amount
                ]);
            } else if ($payment) {
                // If payment is still pending, cancel it
                $payment->status = 'cancelled';
                $payment->save();
                
                Log::info('Payment cancelled due to missed appointment', [
                    'appointment_id' => $appointment->id,
                    'payment_id' => $payment->id
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error processing financial absenteeism: ' . $e->getMessage(), [
                'appointment_id' => $appointment->id,
                'exception' => $e
            ]);
        }
    }
} 