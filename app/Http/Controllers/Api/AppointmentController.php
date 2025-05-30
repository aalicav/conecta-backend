<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Services\NotificationService;
use App\Models\Solicitation;
use App\Models\Patient;
use App\Models\User;
use App\Services\AutomaticSchedulingService;
use App\Services\AppointmentConfirmationService;
use App\Services\SchedulingExceptionService;
use App\Services\DocumentGenerationService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use App\Mail\AppointmentGuide;
use Barryvdh\DomPDF\Facade\Pdf;

class AppointmentController extends Controller
{
    /**
     * @var AutomaticSchedulingService
     */
    protected $automaticSchedulingService;
    
    /**
     * @var AppointmentConfirmationService
     */
    protected $confirmationService;
    
    /**
     * @var SchedulingExceptionService
     */
    protected $exceptionService;
    
    /**
     * @var DocumentGenerationService
     */
    protected $documentService;

    /** 
     * @var NotificationService
     */
    protected $notificationService;

    /**
     * Create a new controller instance.
     *
     * @param NotificationService $notificationService
     * @param AutomaticSchedulingService $automaticSchedulingService
     * @param AppointmentConfirmationService $confirmationService
     * @param SchedulingExceptionService $exceptionService
     * @param DocumentGenerationService $documentService
     * @return void
     */
    public function __construct(

    ) {
        $this->middleware('auth:sanctum');
        $this->notificationService = new NotificationService();
        $this->automaticSchedulingService = new AutomaticSchedulingService();
        $this->confirmationService = new AppointmentConfirmationService();
        $this->exceptionService = new SchedulingExceptionService();
        $this->documentService = new DocumentGenerationService();
    }

    /**
     * Display a listing of appointments.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Appointment::with(['solicitation.healthPlan', 'solicitation.patient', 'solicitation.tuss', 'provider']);
        
        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by date range if provided
        if ($request->has('date_from')) {
            $query->where('scheduled_date', '>=', $request->date_from);
        }
        
        if ($request->has('date_to')) {
            $query->where('scheduled_date', '<=', $request->date_to);
        }
        
        // Filter by solicitation if provided
        if ($request->has('solicitation_id')) {
            $query->where('solicitation_id', $request->solicitation_id);
        }
        
        // Filter by provider type and id if provided
        if ($request->has('provider_type') && $request->has('provider_id')) {
            $query->where('provider_type', $request->provider_type)
                  ->where('provider_id', $request->provider_id);
        }

        // Apply user-specific filters based on roles
        $user = Auth::user();
        
        if ($user->hasRole('plan_admin')) {
            // Health plan admins can only see appointments for their health plan
            $query->whereHas('solicitation.healthPlan', function ($q) use ($user) {
                $q->where('id', $user->health_plan_id);
            });
        } elseif ($user->hasRole('clinic_admin')) {
            // Clinic admins can only see appointments for their clinic
            $query->where(function ($q) use ($user) {
                $q->where('provider_type', Clinic::class)
                  ->where('provider_id', $user->clinic_id);
            });
        } elseif ($user->hasRole('professional')) {
            // Professionals can only see their own appointments
            $query->where(function ($q) use ($user) {
                $q->where('provider_type', Professional::class)
                  ->where('provider_id', $user->professional_id);
            });
        }
        
        // Order by scheduled date by default
        $appointments = $query->orderBy('scheduled_date', $request->order ?? 'desc')
            ->paginate($request->per_page ?? 15);
        
        return AppointmentResource::collection($appointments);
    }

    /**
     * Display the specified appointment.
     *
     * @param Appointment $appointment
     * @return AppointmentResource|JsonResponse
     */
    public function show(Appointment $appointment)
    {
        try {
            // Check if user has permission to view this appointment
            if (!$this->canAccessAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this appointment'
                ], 403);
            }

            // Load relationships
            $appointment->load([
                'solicitation.healthPlan', 
                'solicitation.patient', 
                'solicitation.tuss', 
                'solicitation.requestedBy', 
                'provider',
                'confirmedBy',
                'completedBy',
                'cancelledBy'
            ]);

            return new AppointmentResource($appointment);
        } catch (\Exception $e) {
            Log::error('Error retrieving appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified appointment.
     *
     * @param Request $request
     * @param Appointment $appointment
     * @return JsonResponse
     */
    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        try {
            // Check if user has permission to update this appointment
            if (!$this->canManageAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this appointment'
                ], 403);
            }

            // Check if appointment can be updated
            if (!$appointment->isScheduled() && !$appointment->isConfirmed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment cannot be updated in its current state'
                ], 422);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'scheduled_date' => 'sometimes|date',
                'notes' => 'sometimes|nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update the appointment
            $appointment->update($request->only([
                'scheduled_date',
                'notes',
            ]));

            // Reload relationships
            $appointment->load([
                'solicitation.healthPlan', 
                'solicitation.patient', 
                'solicitation.tuss', 
                'provider'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment updated successfully',
                'data' => new AppointmentResource($appointment)
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm the patient's presence at the appointment.
     *
     * @param Request $request
     * @param Appointment $appointment
     * @return JsonResponse
     */
    public function confirmPresence(Request $request, Appointment $appointment): JsonResponse
    {
        try {
            // Check if user has permission to confirm this appointment
            if (!$this->canManageAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to confirm this appointment'
                ], 403);
            }

            // Check if appointment can be confirmed
            if (!$appointment->isScheduled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only scheduled appointments can be confirmed'
                ], 422);
            }

            // Confirm the appointment
            if (!$appointment->confirm(Auth::id())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to confirm appointment'
                ], 500);
            }

            // Send confirmation notification
            $this->notificationService->notifyAppointmentConfirmed($appointment);

            // Reload relationships
            $appointment->load([
                'solicitation.healthPlan', 
                'solicitation.patient', 
                'solicitation.tuss', 
                'provider',
                'confirmedBy'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment confirmed successfully',
                'data' => new AppointmentResource($appointment)
            ]);
        } catch (\Exception $e) {
            Log::error('Error confirming appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark the appointment as completed.
     *
     * @param Request $request
     * @param Appointment $appointment
     * @return JsonResponse
     */
    public function completeAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        try {
            // Check if user has permission to complete this appointment
            if (!$this->canManageAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to complete this appointment'
                ], 403);
            }

            // Check if appointment can be completed
            if (!$appointment->isScheduled() && !$appointment->isConfirmed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only scheduled or confirmed appointments can be completed'
                ], 422);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update notes if provided
            if ($request->has('notes')) {
                $appointment->notes = $request->notes;
                $appointment->save();
            }

            // Complete the appointment
            if (!$appointment->complete(Auth::id())) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to complete appointment'
                ], 500);
            }

            // Send completed notification - will also send NPS survey via WhatsApp
            $this->notificationService->notifyAppointmentCompleted($appointment);

            // Check if all appointments for this solicitation are completed
            $pendingAppointments = Appointment::where('solicitation_id', $appointment->solicitation_id)
                ->whereNotIn('status', [Appointment::STATUS_COMPLETED, Appointment::STATUS_CANCELLED])
                ->exists();

            // If no pending appointments, mark the solicitation as completed
            if (!$pendingAppointments) {
                $appointment->solicitation->markAsCompleted();
            }

            // Reload relationships
            $appointment->load([
                'solicitation.healthPlan',
                'solicitation.patient',
                'solicitation.tuss',
                'provider',
                'completedBy'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment completed successfully',
                'data' => new AppointmentResource($appointment)
            ]);
        } catch (\Exception $e) {
            Log::error('Error completing appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel an appointment.
     *
     * @param Request $request
     * @param Appointment $appointment
     * @return JsonResponse
     */
    public function cancelAppointment(Request $request, Appointment $appointment): JsonResponse
    {
        try {
            // Check if user has permission to cancel this appointment
            if (!$this->canManageAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to cancel this appointment'
                ], 403);
            }

            // Check if appointment can be cancelled
            if ($appointment->isCompleted() || $appointment->isCancelled() || $appointment->isMissed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment cannot be cancelled in its current state'
                ], 422);
            }

            DB::beginTransaction();

            // Cancel the appointment
            if (!$appointment->cancel(Auth::id())) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to cancel appointment'
                ], 422);
            }

            // If this was the only appointment for the solicitation, revert to pending
            $otherAppointments = Appointment::where('solicitation_id', $appointment->solicitation_id)
                ->where('id', '!=', $appointment->id)
                ->where('status', '!=', Appointment::STATUS_CANCELLED)
                ->exists();

            if (!$otherAppointments) {
                $appointment->solicitation->markAsPending();
            }

            // Send notifications
            $this->notificationService->notifyAppointmentCancelled($appointment);

            DB::commit();

            // Load relationships for response
            $appointment->load([
                'solicitation.healthPlan', 
                'solicitation.patient', 
                'solicitation.tuss', 
                'provider',
                'cancelledBy'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment cancelled successfully',
                'data' => new AppointmentResource($appointment)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error cancelling appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark the appointment as missed.
     *
     * @param Appointment $appointment
     * @return JsonResponse
     */
    public function markAsMissed(Appointment $appointment): JsonResponse
    {
        try {
            // Check if user has permission to mark this appointment as missed
            if (!$this->canManageAppointment($appointment)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to mark this appointment as missed'
                ], 403);
            }

            // Check if appointment can be marked as missed
            if (!$appointment->isScheduled() && !$appointment->isConfirmed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only scheduled or confirmed appointments can be marked as missed'
                ], 422);
            }

            // Mark as missed
            $previousStatus = $appointment->status;
            if (!$appointment->markAsMissed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to mark appointment as missed'
                ], 500);
            }

            // Send missed notification
            $this->notificationService->notifyAppointmentMissed($appointment);

            // Reload relationships
            $appointment->load([
                'solicitation.healthPlan',
                'solicitation.patient',
                'solicitation.tuss',
                'provider'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment marked as missed successfully',
                'data' => new AppointmentResource($appointment)
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking appointment as missed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark appointment as missed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the current user can access the appointment.
     *
     * @param Appointment $appointment
     * @return bool
     */
    protected function canAccessAppointment(Appointment $appointment): bool
    {
        $user = Auth::user();

        // Super admins can access any appointment
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Health plan admins can access appointments for their health plan
        if ($user->hasRole('plan_admin')) {
            return $appointment->solicitation->health_plan_id === $user->health_plan_id;
        }

        // Clinic admins can access appointments for their clinic
        if ($user->hasRole('clinic_admin') && 
            $appointment->provider_type === Clinic::class) {
            return $appointment->provider_id === $user->clinic_id;
        }

        // Professionals can access their own appointments
        if ($user->hasRole('professional') && 
            $appointment->provider_type === Professional::class) {
            return $appointment->provider_id === $user->professional_id;
        }

        return false;
    }

    /**
     * Check if the current user can manage the appointment (update, cancel, etc.).
     *
     * @param Appointment $appointment
     * @return bool
     */
    protected function canManageAppointment(Appointment $appointment): bool
    {
        $user = Auth::user();

        // Super admins can manage any appointment
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Health plan admins can manage appointments for their health plan
        if ($user->hasRole('plan_admin')) {
            return $appointment->solicitation->health_plan_id === $user->health_plan_id;
        }

        // Clinic admins can manage appointments for their clinic
        if ($user->hasRole('clinic_admin') && 
            $appointment->provider_type === Clinic::class) {
            return $appointment->provider_id === $user->clinic_id;
        }

        // Professionals can manage their own appointments
        if ($user->hasRole('professional') && 
            $appointment->provider_type === Professional::class) {
            return $appointment->provider_id === $user->professional_id;
        }

        return false;
    }

    /**
     * Create a new appointment manually.
     * This is specifically for solicitations with failed status that need manual scheduling.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createManually(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'solicitation_id' => 'required|exists:solicitations,id',
                'provider_type' => 'required|in:App\\Models\\Clinic,App\\Models\\Professional',
                'provider_id' => 'required|integer',
                'scheduled_date' => 'required|date|after:now',
                'notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get the solicitation
            $solicitation = Solicitation::findOrFail($request->solicitation_id);
            
            // Check if solicitation is a Collection and extract the first item if needed
            if ($solicitation instanceof \Illuminate\Database\Eloquent\Collection) {
                $solicitation = $solicitation->first();
            }

            // Check if user has permission to create appointment for this solicitation
            if (!$this->canAccessSolicitation($solicitation)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to create appointment for this solicitation'
                ], 403);
            }

            // Check if solicitation has failed status - only failed solicitations can be manually scheduled
            if ($solicitation->status !== 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only solicitations with failed status can be manually scheduled'
                ], 422);
            }

            // Verify that the provider exists
            $providerClass = $request->provider_type;
            $provider = $providerClass::findOrFail($request->provider_id);

            // For clinics, check if they offer the required procedure
            if ($providerClass === Clinic::class) {
                $procedureOffered = $provider->procedures()
                    ->where('tuss_procedure_id', $solicitation->tuss_id)
                    ->exists();

                if (!$procedureOffered) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected clinic does not offer the required procedure'
                    ], 422);
                }
            }

            // For professionals, check if they offer the required procedure
            if ($providerClass === Professional::class) {
                $procedureOffered = $provider->procedures()
                    ->where('tuss_procedure_id', $solicitation->tuss_id)
                    ->exists();

                if (!$procedureOffered) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected professional does not offer the required procedure'
                    ], 422);
                }
            }

            // Create the appointment
            $appointment = new Appointment([
                'solicitation_id' => $solicitation->id,
                'provider_type' => $request->provider_type,
                'provider_id' => $request->provider_id,
                'scheduled_date' => $request->scheduled_date,
                'notes' => $request->notes,
                'status' => Appointment::STATUS_SCHEDULED,
                'created_by' => Auth::id(),
            ]);

            $appointment->save();

            // Update solicitation status to scheduled
            $solicitation->status = 'scheduled';
            $solicitation->save();

            // Send notification
            $this->notificationService->notifyAppointmentScheduled($appointment);

            // Reload relationships
            $appointment->load([
                'solicitation.healthPlan',
                'solicitation.patient',
                'solicitation.tuss',
                'provider'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Appointment created successfully',
                'data' => new AppointmentResource($appointment)
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating appointment manually: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the current user can access the solicitation.
     *
     * @param \App\Models\Solicitation $solicitation
     * @return bool
     */
    protected function canAccessSolicitation($solicitation): bool
    {
        $user = Auth::user();

        // Super admins can access any solicitation
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Health plan admins can access solicitations for their health plan
        if ($user->hasRole('plan_admin')) {
            return $solicitation->health_plan_id === $user->health_plan_id;
        }

        // Patients can access their own solicitations
        if ($user->hasRole('patient')) {
            return $solicitation->patient_id === $user->entity_id;
        }

        return false;
    }

    /**
     * Display appointments pending confirmation (48h).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function pendingConfirmation()
    {
        try {
            $pendingAppointments = $this->confirmationService->getPendingForConfirmation();
            
            return response()->json([
                'status' => 'success',
                'data' => $pendingAppointments,
                'meta' => [
                    'count' => $pendingAppointments->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving pending confirmations: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve pending confirmations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified appointment.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAppointmentDetails($id)
    {
        try {
            $appointment = Appointment::with([
                'solicitation.patient', 
                'solicitation.healthPlan', 
                'solicitation.tuss', 
                'provider',
                'payment'
            ])->findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $appointment
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving appointment: ' . $e->getMessage(), [
                'exception' => $e,
                'appointment_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve appointment',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Schedule an appointment automatically.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function scheduleAutomatically(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'solicitation_id' => 'required|exists:solicitations,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            DB::beginTransaction();
            
            $solicitation = Solicitation::findOrFail($request->solicitation_id);
            
            // Check if solicitation is a Collection and extract the first item if needed
            if ($solicitation instanceof \Illuminate\Database\Eloquent\Collection) {
                $solicitation = $solicitation->first();
            }
            
            // Check if solicitation is in a valid state for scheduling
            if (!$solicitation->isPending() && !$solicitation->isFailed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solicitation is not in a valid state for scheduling'
                ], 422);
            }
            
            // Schedule the appointment using the automatic service
            $appointment = $this->automaticSchedulingService->scheduleAppointment($solicitation);
            
            if (!$appointment) {
                DB::rollBack();
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to schedule appointment automatically',
                    'suggest_manual' => true
                ], 400);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Appointment scheduled successfully',
                'data' => $appointment->load(['solicitation.patient', 'solicitation.healthPlan', 'solicitation.tuss', 'provider'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error scheduling appointment automatically: ' . $e->getMessage(), [
                'exception' => $e,
                'solicitation_id' => $request->solicitation_id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to schedule appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Request scheduling exception (manual scheduling).
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function requestException(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'solicitation_id' => 'required|exists:solicitations,id',
            'provider_type' => 'required|in:App\\Models\\Professional,App\\Models\\Clinic',
            'provider_id' => 'required|integer',
            'reason' => 'required|string|min:10',
            'price' => 'required|numeric|min:0',
            'scheduled_date' => 'nullable|date_format:Y-m-d H:i:s|after:now',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $solicitation = Solicitation::findOrFail($request->solicitation_id);
            
            // Check if solicitation is a Collection and extract the first item if needed
            if ($solicitation instanceof \Illuminate\Database\Eloquent\Collection) {
                $solicitation = $solicitation->first();
            }
            
            // Check if solicitation is in a valid state for scheduling
            if (!$solicitation->isPending() && !$solicitation->isFailed()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Solicitation is not in a valid state for scheduling'
                ], 422);
            }
            
            // Mark solicitation as processing
            $solicitation->markAsProcessing();
            
            // Parse the scheduled date if provided
            $scheduledDate = $request->filled('scheduled_date') 
                ? Carbon::parse($request->scheduled_date) 
                : null;
            
            // Request the scheduling exception
            $exception = $this->exceptionService->requestException(
                $solicitation,
                $request->provider_type,
                $request->provider_id,
                $request->reason,
                $request->price,
                $scheduledDate
            );
            
            return response()->json([
                'status' => 'success',
                'message' => 'Scheduling exception requested successfully',
                'data' => $exception->load(['solicitation', 'requestedBy'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error requesting scheduling exception: ' . $e->getMessage(), [
                'exception' => $e,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to request scheduling exception',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm an appointment (48h before).
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirm(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'patient_confirmed' => 'required|boolean',
            'provider_confirmed' => 'required|boolean',
            'notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $appointment = Appointment::findOrFail($id);
            
            // Check if appointment is in scheduled status
            if ($appointment->status !== Appointment::STATUS_SCHEDULED) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only scheduled appointments can be confirmed'
                ], 422);
            }
            
            DB::beginTransaction();
            
            $result = $this->confirmationService->confirmPreAppointment(
                $appointment,
                Auth::id(),
                $request->patient_confirmed,
                $request->provider_confirmed
            );
            
            if (!$result) {
                DB::rollBack();
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to confirm appointment',
                    'patient_confirmed' => $request->patient_confirmed,
                    'provider_confirmed' => $request->provider_confirmed
                ], 400);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Appointment confirmed successfully',
                'data' => $appointment->fresh(['solicitation.patient', 'solicitation.healthPlan', 'solicitation.tuss', 'provider', 'payment'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error confirming appointment: ' . $e->getMessage(), [
                'exception' => $e,
                'appointment_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to confirm appointment',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Generate and download appointment guide.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateGuide($id)
    {
        try {
            $appointment = Appointment::with([
                'solicitation.patient', 
                'solicitation.healthPlan', 
                'solicitation.tuss', 
                'provider'
            ])->findOrFail($id);
            
            // Check if appointment is confirmed
            if ($appointment->status !== Appointment::STATUS_CONFIRMED) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only confirmed appointments can have guides generated'
                ], 422);
            }
            
            // Generate the guide
            $guidePath = $this->confirmationService->generateAppointmentGuide($appointment);
            
            if (!$guidePath) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to generate appointment guide'
                ], 500);
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Appointment guide generated successfully',
                'data' => [
                    'appointment' => $appointment,
                    'guide_path' => $guidePath,
                    'download_url' => url("api/appointments/{$id}/guide/download")
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating appointment guide: ' . $e->getMessage(), [
                'exception' => $e,
                'appointment_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate appointment guide',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Download appointment guide.
     *
     * @param int $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function downloadGuide($id)
    {
        try {
            $appointment = Appointment::findOrFail($id);
            
            if (empty($appointment->guide_path)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No guide available for this appointment'
                ], 404);
            }
            
            $path = storage_path('app/' . $appointment->guide_path);
            
            if (!file_exists($path)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Guide file not found'
                ], 404);
            }
            
            return response()->download($path, "guide_appointment_{$id}.pdf");
        } catch (\Exception $e) {
            Log::error('Error downloading appointment guide: ' . $e->getMessage(), [
                'exception' => $e,
                'appointment_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download appointment guide',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Upload signed guide and confirm attendance.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function confirmAttendance(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'patient_attended' => 'required|boolean',
            'provider_confirmed' => 'required|boolean',
            'signed_guide' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:10240', // 10MB max
            'notes' => 'nullable|string',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $appointment = Appointment::findOrFail($id);
            
            // Check if appointment is in confirmed status
            if ($appointment->status !== Appointment::STATUS_CONFIRMED) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only confirmed appointments can be marked as completed'
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Handle file upload if present
            $guidePath = null;
            if ($request->hasFile('signed_guide') && $request->file('signed_guide')->isValid()) {
                $file = $request->file('signed_guide');
                $filename = "signed_guide_{$id}." . $file->getClientOriginalExtension();
                $path = "guides/signed/{$appointment->solicitation->health_plan_id}/{$id}";
                $guidePath = $file->storeAs($path, $filename);
            }
            
            // Confirm attendance
            $result = $this->confirmationService->confirmAttendance(
                $appointment,
                $request->patient_attended,
                $request->provider_confirmed,
                $guidePath
            );
            
            if (!$result) {
                DB::rollBack();
                
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to confirm attendance'
                ], 400);
            }
            
            // Add notes if provided
            if ($request->filled('notes')) {
                $appointment->notes = $appointment->notes ? $appointment->notes . "\n" . $request->notes : $request->notes;
                $appointment->save();
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => $request->patient_attended ? 'Appointment completed successfully' : 'Appointment marked as missed',
                'data' => $appointment->fresh(['solicitation.patient', 'solicitation.healthPlan', 'solicitation.tuss', 'provider', 'payment'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error confirming attendance: ' . $e->getMessage(), [
                'exception' => $e,
                'appointment_id' => $id,
                'request' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to confirm attendance',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Get appointments that need 48-hour confirmation.
     *
     * @return JsonResponse
     */
    public function getPendingConfirmations(): JsonResponse
    {
        try {
            // Get appointments scheduled for the next 2-3 days that haven't been confirmed yet
            $startDate = Carbon::now()->addHours(47);
            $endDate = Carbon::now()->addHours(49);
            
            $appointments = Appointment::with(['solicitation.patient', 'solicitation.tuss', 'provider'])
                ->whereBetween('scheduled_date', [$startDate, $endDate])
                ->where('status', 'scheduled')
                ->whereNull('pre_confirmed_at')
                ->get();
                
            return response()->json([
                'success' => true,
                'data' => $appointments->map(function($appointment) {
                    return [
                        'id' => $appointment->id,
                        'scheduled_date' => $appointment->scheduled_date,
                        'patient_name' => $appointment->solicitation->patient->name,
                        'patient_phone' => $appointment->solicitation->patient->phone,
                        'procedure_name' => $appointment->solicitation->tuss->name,
                        'provider_name' => $appointment->provider->name ?? 'N/A',
                        'provider_type' => class_basename($appointment->provider_type),
                        'provider_id' => $appointment->provider_id
                    ];
                })
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting pending confirmations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar confirmações pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm appointment 48 hours before.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function confirm48h(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'confirmed' => 'required|boolean',
                'confirmation_notes' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $appointment = Appointment::findOrFail($id);
            
            if ($appointment->status !== 'scheduled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment cannot be confirmed in its current state'
                ], 422);
            }

            // Update appointment with confirmation data
            $appointment->pre_confirmed_at = now();
            $appointment->pre_confirmed_by = Auth::id();
            $appointment->pre_confirmation_notes = $request->confirmation_notes;
            
            // If not confirmed, mark as missed
            if (!$request->confirmed) {
                $appointment->status = 'missed';
                $appointment->missed_at = now();
                $appointment->missed_reason = 'Patient unavailable at 48h confirmation';
            }
            
            $appointment->save();
            
            // If confirmed, notify finance department
            if ($request->confirmed) {
                // Trigger finance department notification for payment
                $this->notificationService->notifyFinanceDepartment($appointment);
                
                // Generate and send guide to provider
                $this->generateAndSendGuide($appointment);
            } else {
                // Notify health plan about patient absence
                $this->notificationService->notifyHealthPlanAboutAbsence($appointment);
                
                // Notify finance department about the absence
                $this->notificationService->notifyFinanceDepartmentAboutAbsence($appointment);
            }

            return response()->json([
                'success' => true,
                'message' => $request->confirmed ? 'Appointment confirmed successfully' : 'Appointment marked as missed',
                'data' => $appointment
            ]);
        } catch (\Exception $e) {
            Log::error('Error confirming appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error confirming appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate and send appointment guide to provider.
     *
     * @param Appointment $appointment
     * @return bool
     */
    protected function generateAndSendGuide(Appointment $appointment): bool
    {
        try {
            // Load relationships
            $appointment->load(['solicitation.patient', 'solicitation.healthPlan', 'solicitation.tuss', 'provider']);
            
            // Generate unique token for the guide
            $token = Str::random(32);
            $appointment->guide_token = $token;
            $appointment->guide_generated_at = now();
            $appointment->save();
            
            // Generate PDF guide
            $pdf = PDF::loadView('pdfs.appointment_guide', [
                'appointment' => $appointment,
                'token' => $token
            ]);
            
            // Save guide to storage
            $filename = 'guide_' . $appointment->id . '_' . time() . '.pdf';
            $path = Storage::disk('guides')->put($filename, $pdf->output());
            
            // Record guide path
            $appointment->guide_path = $filename;
            $appointment->save();
            
            // Send guide to provider via email
            if ($appointment->provider_type === 'App\\Models\\Clinic') {
                $clinic = $appointment->provider;
                if ($clinic && $clinic->email) {
                    Mail::to($clinic->email)
                        ->send(new AppointmentGuide($appointment, $pdf->output()));
                }
            } elseif ($appointment->provider_type === 'App\\Models\\Professional') {
                $professional = $appointment->provider;
                if ($professional && $professional->email) {
                    Mail::to($professional->email)
                        ->send(new AppointmentGuide($appointment, $pdf->output()));
                }
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Error generating guide: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate appointment verification token and send notification links.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function generateVerificationToken(int $id): JsonResponse
    {
        try {
            $appointment = Appointment::findOrFail($id);
            
            if ($appointment->status !== 'scheduled' || !$appointment->pre_confirmed_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment cannot have verification token generated in its current state'
                ], 422);
            }
            
            // Generate unique token
            $token = Str::random(32);
            $appointment->verification_token = $token;
            $appointment->verification_token_expires_at = now()->addHours(24);
            $appointment->save();
            
            // Send verification link to patient and provider
            $verificationUrl = url("/verify-appointment/{$token}");
            
            // Send to patient via WhatsApp and email
            $this->notificationService->sendAppointmentVerificationToPatient(
                $appointment->solicitation->patient,
                $verificationUrl,
                $appointment
            );
            
            // Send to provider
            if ($appointment->provider_type === 'App\\Models\\Clinic') {
                $clinic = $appointment->provider;
                if ($clinic && $clinic->email) {
                    $this->notificationService->sendAppointmentVerificationToProvider(
                        $clinic,
                        $verificationUrl,
                        $appointment
                    );
                }
            } elseif ($appointment->provider_type === 'App\\Models\\Professional') {
                $professional = $appointment->provider;
                if ($professional && $professional->email) {
                    $this->notificationService->sendAppointmentVerificationToProvider(
                        $professional,
                        $verificationUrl,
                        $appointment
                    );
                }
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Verification token generated and sent successfully',
                'data' => [
                    'verification_url' => $verificationUrl
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error generating verification token: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error generating verification token',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify appointment completion based on token.
     *
     * @param string $token
     * @return JsonResponse
     */
    public function verifyAppointment(string $token): JsonResponse
    {
        try {
            $appointment = Appointment::where('verification_token', $token)
                ->where('verification_token_expires_at', '>', now())
                ->first();
                
            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired verification token'
                ], 404);
            }
            
            // Load relationships
            $appointment->load(['solicitation.patient', 'solicitation.tuss', 'provider']);
            
            return response()->json([
                'success' => true,
                'message' => 'Valid verification token',
                'data' => [
                    'appointment_id' => $appointment->id,
                    'scheduled_date' => $appointment->scheduled_date,
                    'patient_name' => $appointment->solicitation->patient->name,
                    'procedure_name' => $appointment->solicitation->tuss->name,
                    'provider_name' => $appointment->provider->name ?? 'N/A',
                    'provider_type' => class_basename($appointment->provider_type),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying appointment: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error verifying appointment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Confirm appointment completion or absence based on token.
     *
     * @param Request $request
     * @param string $token
     * @return JsonResponse
     */
    public function confirmAppointment(Request $request, string $token): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'confirmed' => 'required|boolean',
                'notes' => 'nullable|string',
                'guide_image' => 'nullable|string', // Base64 encoded image
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $appointment = Appointment::where('verification_token', $token)
                ->where('verification_token_expires_at', '>', now())
                ->first();
                
            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired verification token'
                ], 404);
            }
            
            if ($appointment->status !== 'scheduled') {
                return response()->json([
                    'success' => false,
                    'message' => 'Appointment cannot be confirmed in its current state'
                ], 422);
            }
            
            // Process guide image if provided
            if ($request->guide_image) {
                $imageData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $request->guide_image));
                $filename = 'signed_guide_' . $appointment->id . '_' . time() . '.jpg';
                Storage::disk('guides')->put($filename, $imageData);
                $appointment->signed_guide_path = $filename;
            }
            
            // Update appointment status
            if ($request->confirmed) {
                $appointment->status = 'completed';
                $appointment->completed_at = now();
                $appointment->completion_notes = $request->notes;
            } else {
                $appointment->status = 'missed';
                $appointment->missed_at = now();
                $appointment->missed_reason = $request->notes ?: 'Patient did not show up';
                
                // Notify health plan about patient absence
                $this->notificationService->notifyHealthPlanAboutAbsence($appointment);
                
                // Notify finance department about the absence
                $this->notificationService->notifyFinanceDepartmentAboutAbsence($appointment);
            }
            
            // Invalidate verification token
            $appointment->verification_token_expires_at = now()->subMinute();
            $appointment->save();
            
            // Check if all appointments for the solicitation are completed
            $solicitation = $appointment->solicitation;
            $allCompleted = true;
            
            foreach ($solicitation->appointments as $app) {
                if ($app->status !== 'completed' && $app->status !== 'cancelled') {
                    $allCompleted = false;
                    break;
                }
            }
            
            // If all appointments are completed, mark the solicitation as completed
            if ($allCompleted) {
                $solicitation->markAsCompleted();
            }
            
            return response()->json([
                'success' => true,
                'message' => $request->confirmed ? 'Appointment confirmed as completed' : 'Appointment marked as missed',
                'data' => $appointment
            ]);
        } catch (\Exception $e) {
            Log::error('Error confirming appointment completion: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error confirming appointment completion',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process pending solicitations automatically.
     * Only network_manager and admin roles can access this endpoint.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function processPendingSolicitations(Request $request): JsonResponse
    {
        // Check if user has required role
        if (!Auth::user()->hasRole(['network_manager', 'super_admin'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized to process pending solicitations'
            ], 403);
        }

        try {
            // Get pending solicitations
            $solicitations = Solicitation::where('status', Solicitation::STATUS_PENDING)
                ->orWhere('status', Solicitation::STATUS_FAILED)
                ->get();

            if ($solicitations->isEmpty()) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No pending solicitations found',
                    'data' => [
                        'processed' => 0,
                        'successful' => 0,
                        'failed' => 0
                    ]
                ]);
            }

            $processed = 0;
            $successful = 0;
            $failed = 0;

            foreach ($solicitations as $solicitation) {
                try {
                    // Mark as processing
                    $solicitation->markAsProcessing();

                    // Attempt to schedule
                    $appointment = $this->automaticSchedulingService->scheduleAppointment($solicitation);

                    if ($appointment) {
                        $successful++;
                    } else {
                        $failed++;
                    }

                    $processed++;
                } catch (\Exception $e) {
                    Log::error("Error processing solicitation #{$solicitation->id}: " . $e->getMessage());
                    $failed++;
                    $processed++;
                }
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Finished processing pending solicitations',
                'data' => [
                    'processed' => $processed,
                    'successful' => $successful,
                    'failed' => $failed
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error processing pending solicitations: ' . $e->getMessage());
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process pending solicitations',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 