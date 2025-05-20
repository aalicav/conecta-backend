<?php

namespace App\Services;

use App\Models\SchedulingException;
use App\Models\Solicitation;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class SchedulingExceptionService
{
    /**
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Create a new service instance.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Request a scheduling exception
     *
     * @param Solicitation $solicitation
     * @param string $providerType
     * @param int $providerId
     * @param string $reason
     * @param float $price
     * @param Carbon|null $scheduledDate
     * @return SchedulingException
     */
    public function requestException(
        Solicitation $solicitation,
        string $providerType,
        int $providerId,
        string $reason,
        float $price,
        ?Carbon $scheduledDate = null
    ) {
        try {
            Log::info('Requesting scheduling exception', [
                'solicitation_id' => $solicitation->id,
                'provider_type' => $providerType,
                'provider_id' => $providerId,
                'requested_by' => Auth::id()
            ]);
            
            // Validate that provider exists
            $provider = null;
            if ($providerType === 'App\\Models\\Professional') {
                $provider = Professional::find($providerId);
            } else if ($providerType === 'App\\Models\\Clinic') {
                $provider = Clinic::find($providerId);
            }
            
            if (!$provider) {
                throw new \Exception('Provider not found');
            }
            
            // Get recommended provider from automatic scheduling algorithm
            $autoScheduler = app(AutomaticSchedulingService::class);
            $recommendedProviders = $autoScheduler->findEligibleProviders($solicitation);
            
            // Get the recommended price (lowest price from eligible providers)
            $recommendedPrice = $recommendedProviders->isNotEmpty() 
                ? $recommendedProviders->first()['price'] 
                : null;
            
            // Create the exception record
            $exception = SchedulingException::create([
                'solicitation_id' => $solicitation->id,
                'requested_provider_type' => $providerType,
                'requested_provider_id' => $providerId,
                'requested_price' => $price,
                'recommended_price' => $recommendedPrice,
                'price_difference' => $recommendedPrice ? ($price - $recommendedPrice) : null,
                'requested_date' => $scheduledDate,
                'reason' => $reason,
                'status' => 'pending',
                'requested_by' => Auth::id(),
            ]);
            
            // Notify administrators about the new exception
            $this->notificationService->notifyNewSchedulingException($exception);
            
            Log::info('Scheduling exception created successfully', [
                'exception_id' => $exception->id,
                'solicitation_id' => $solicitation->id
            ]);
            
            return $exception;
        } catch (\Exception $e) {
            Log::error('Error creating scheduling exception: ' . $e->getMessage(), [
                'solicitation_id' => $solicitation->id,
                'provider_type' => $providerType,
                'provider_id' => $providerId,
                'exception' => $e
            ]);
            
            throw $e;
        }
    }

    /**
     * Approve a scheduling exception
     *
     * @param SchedulingException $exception
     * @param int $approverId
     * @param string|null $notes
     * @return Appointment|null
     */
    public function approveException(SchedulingException $exception, int $approverId, ?string $notes = null)
    {
        try {
            Log::info('Approving scheduling exception', [
                'exception_id' => $exception->id,
                'approver_id' => $approverId
            ]);
            
            // Mark the exception as approved
            $exception->status = 'approved';
            $exception->approved_by = $approverId;
            $exception->approved_at = Carbon::now();
            $exception->approval_notes = $notes;
            $exception->save();
            
            // Create the appointment with the requested provider
            $appointment = Appointment::create([
                'solicitation_id' => $exception->solicitation_id,
                'provider_type' => $exception->requested_provider_type,
                'provider_id' => $exception->requested_provider_id,
                'status' => Appointment::STATUS_SCHEDULED,
                'scheduled_date' => $exception->requested_date ?: Carbon::now()->addDays(3), // Default to 3 days from now if not specified
                'notes' => 'Agendamento por exceção aprovado por ' . User::find($approverId)->name,
                'price' => $exception->requested_price
            ]);
            
            // Update solicitation status
            $exception->solicitation->markAsScheduled(false);
            
            // Register extemporaneous negotiation
            $this->registerExtemporaneousNegotiation($exception);
            
            // Notify the requester that the exception was approved
            $this->notificationService->notifySchedulingExceptionApproved($exception);
            
            // Send appointment scheduled notification
            $this->notificationService->notifyAppointmentScheduled($appointment);
            
            Log::info('Scheduling exception approved and appointment created', [
                'exception_id' => $exception->id,
                'appointment_id' => $appointment->id
            ]);
            
            return $appointment;
        } catch (\Exception $e) {
            Log::error('Error approving scheduling exception: ' . $e->getMessage(), [
                'exception_id' => $exception->id,
                'exception' => $e
            ]);
            
            return null;
        }
    }

    /**
     * Reject a scheduling exception
     *
     * @param SchedulingException $exception
     * @param int $rejecterId
     * @param string $reason
     * @return bool
     */
    public function rejectException(SchedulingException $exception, int $rejecterId, string $reason)
    {
        try {
            Log::info('Rejecting scheduling exception', [
                'exception_id' => $exception->id,
                'rejecter_id' => $rejecterId,
                'reason' => $reason
            ]);
            
            // Mark the exception as rejected
            $exception->status = 'rejected';
            $exception->rejected_by = $rejecterId;
            $exception->rejected_at = Carbon::now();
            $exception->rejection_reason = $reason;
            $exception->save();
            
            // Notify the requester that the exception was rejected
            $this->notificationService->notifySchedulingExceptionRejected($exception);
            
            Log::info('Scheduling exception rejected', [
                'exception_id' => $exception->id
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error rejecting scheduling exception: ' . $e->getMessage(), [
                'exception_id' => $exception->id,
                'exception' => $e
            ]);
            
            return false;
        }
    }

    /**
     * Register an extemporaneous negotiation for the commercial team
     *
     * @param SchedulingException $exception
     * @return void
     */
    protected function registerExtemporaneousNegotiation(SchedulingException $exception)
    {
        try {
            // Check if ExtemporaneousNegotiation model exists
            if (!class_exists('App\\Models\\ExtemporaneousNegotiation')) {
                Log::warning('ExtemporaneousNegotiation model not found');
                return;
            }
            
            // Get provider and procedure details
            $provider = null;
            if ($exception->requested_provider_type === 'App\\Models\\Professional') {
                $provider = Professional::find($exception->requested_provider_id);
            } else if ($exception->requested_provider_type === 'App\\Models\\Clinic') {
                $provider = Clinic::find($exception->requested_provider_id);
            }
            
            if (!$provider) {
                Log::warning('Provider not found for extemporaneous negotiation');
                return;
            }
            
            $solicitation = $exception->solicitation;
            $procedure = $solicitation->tuss;
            $healthPlan = $solicitation->healthPlan;
            
            // Create the extemporaneous negotiation record
            \App\Models\ExtemporaneousNegotiation::create([
                'health_plan_id' => $healthPlan->id,
                'provider_type' => $exception->requested_provider_type,
                'provider_id' => $exception->requested_provider_id,
                'tuss_id' => $procedure->id,
                'price' => $exception->requested_price,
                'scheduling_exception_id' => $exception->id,
                'status' => 'pending',
                'requested_by' => $exception->approved_by,
            ]);
            
            // Alert commercial team
            // Implementation depends on the notification system
            
            Log::info('Extemporaneous negotiation registered for the commercial team', [
                'exception_id' => $exception->id,
                'provider' => $provider->name,
                'procedure' => $procedure->name
            ]);
        } catch (\Exception $e) {
            Log::error('Error registering extemporaneous negotiation: ' . $e->getMessage(), [
                'exception_id' => $exception->id,
                'exception' => $e
            ]);
        }
    }

    /**
     * Get pending exceptions that need approval
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getPendingExceptions()
    {
        return SchedulingException::with(['solicitation.patient', 'solicitation.tuss', 'solicitation.healthPlan', 'requestedBy'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Calculate the price difference percentage
     *
     * @param SchedulingException $exception
     * @return float|null
     */
    public function calculatePriceDifferencePercentage(SchedulingException $exception)
    {
        if (!$exception->recommended_price || $exception->recommended_price <= 0) {
            return null;
        }
        
        $difference = $exception->requested_price - $exception->recommended_price;
        $percentage = ($difference / $exception->recommended_price) * 100;
        
        return round($percentage, 2);
    }
} 