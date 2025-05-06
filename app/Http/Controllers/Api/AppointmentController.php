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

class AppointmentController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @param NotificationService $notificationService
     * @return void
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:sanctum');
        $this->notificationService = $notificationService;
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
        
        if ($user->hasRole('health_plan_admin')) {
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
     * Cancel the appointment.
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
            if ($appointment->isCompleted() || $appointment->isCancelled()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot cancel completed or already cancelled appointments'
                ], 422);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'notes' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Cancel the appointment
            if (!$appointment->cancel(Auth::id(), $request->notes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to cancel appointment'
                ], 500);
            }

            // Send cancellation notification - will also send via WhatsApp
            $this->notificationService->notifyAppointmentCancelled($appointment, $request->notes);

            // Check if this was the only active appointment for the solicitation
            $otherActiveAppointments = Appointment::where('solicitation_id', $appointment->solicitation_id)
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_COMPLETED])
                ->exists();

            // If no other active appointments, revert the solicitation to pending
            if (!$otherActiveAppointments) {
                $appointment->solicitation->markAsProcessing();
            }

            // Reload relationships
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
        if ($user->hasRole('health_plan_admin')) {
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
        if ($user->hasRole('health_plan_admin')) {
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
            $solicitation = \App\Models\Solicitation::findOrFail($request->solicitation_id);

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
            if ($providerClass === \App\Models\Clinic::class) {
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
            if ($providerClass === \App\Models\Professional::class) {
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
            $appointment = new \App\Models\Appointment([
                'solicitation_id' => $solicitation->id,
                'provider_type' => $request->provider_type,
                'provider_id' => $request->provider_id,
                'scheduled_date' => $request->scheduled_date,
                'notes' => $request->notes,
                'status' => \App\Models\Appointment::STATUS_SCHEDULED,
                'created_by' => Auth::id(),
            ]);

            $appointment->save();

            // Update solicitation status to scheduled
            $solicitation->status = 'scheduled';
            $solicitation->save();

            // Send notification
            $this->notificationService->notifyAppointmentCreated($appointment);

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
        if ($user->hasRole('health_plan_admin')) {
            return $solicitation->health_plan_id === $user->health_plan_id;
        }

        // Patients can access their own solicitations
        if ($user->hasRole('patient')) {
            return $solicitation->patient_id === $user->entity_id;
        }

        return false;
    }
} 