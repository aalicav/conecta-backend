<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SolicitationResource;
use App\Models\Solicitation;
use App\Models\HealthPlan;
use App\Models\Patient;
use App\Models\Tuss;
use App\Models\SystemSetting;
use App\Services\SchedulingService;
use App\Services\AppointmentScheduler;
use App\Services\SchedulingConfigService;
use App\Services\NotificationService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Models\Appointment;

class SolicitationController extends Controller
{
    protected SchedulingService $schedulingService;
    protected NotificationService $notificationService;

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->schedulingService = new SchedulingService();
        $this->notificationService = new NotificationService();
    }

    /**
     * Display a listing of solicitations.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Solicitation::with(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments']);
        
        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by health plan if provided
        if ($request->has('health_plan_id')) {
            $query->where('health_plan_id', $request->health_plan_id);
        }
        
        // Filter by patient if provided
        if ($request->has('patient_id')) {
            $query->where('patient_id', $request->patient_id);
        }
        
        // Filter by priority if provided
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        
        // Filter by TUSS code if provided
        if ($request->has('tuss_id')) {
            $query->where('tuss_id', $request->tuss_id);
        }
        
        // Restrict health plan users to only see their own solicitations
        if (Auth::user()->hasRole('health_plan_admin')) {
            $query->whereHas('healthPlan', function ($q) {
                $q->where('id', Auth::user()->health_plan_id);
            });
        }
        
        // Order by creation date, newest first
        $orderBy = $request->input('order_by', 'created_at');
        $direction = $request->input('direction', 'desc');
        $query->orderBy($orderBy, $direction);
        
        // Paginate results
        $perPage = $request->input('per_page', 15);
        
        return SolicitationResource::collection($query->paginate($perPage));
    }

    /**
     * Store a newly created solicitation in storage.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'health_plan_id' => 'required|exists:health_plans,id',
                'patient_id' => 'required|exists:patients,id',
                'tuss_id' => 'required|exists:tuss_procedures,id',
                'description' => 'required|string',
                'priority' => 'required|in:low,normal,high',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get health plan
            $healthPlan = HealthPlan::findOrFail($request->health_plan_id);

            // Check if health plan is approved
            if (!$healthPlan->approved_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Health plan is not approved'
                ], 422);
            }

            // Check if health plan has signed contract
            if (!$healthPlan->has_signed_contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Health plan has not signed the contract'
                ], 422);
            }

            // Check if health plan has pending status
            if ($healthPlan->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Health plan has been edited and requires re-approval'
                ], 422);
            }

            // Verify that the patient belongs to the health plan
            $patient = Patient::findOrFail($request->patient_id);
            if ($patient->health_plan_id != $request->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Patient does not belong to the specified health plan'
                ], 422);
            }

            DB::beginTransaction();

            // Create the solicitation
            $solicitation = Solicitation::create([
                'health_plan_id' => $request->health_plan_id,
                'patient_id' => $request->patient_id,
                'tuss_id' => $request->tuss_id,
                'status' => Solicitation::STATUS_PENDING,
                'priority' => $request->priority ?? 'normal',
                'description' => $request->description,
                'requested_by' => Auth::id(),
            ]);

            // Load relationships for the resource
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy']);

            // Set as processing immediately before scheduling attempt
            $solicitation->markAsProcessing();

            // This will notify health plan admins and super admins
            $this->notificationService->notifySolicitationCreated($solicitation);

            // Attempt to find best provider based on TUSS specialty
            $scheduler = new AppointmentScheduler();
            $result = $scheduler->findBestProvider($solicitation);

            if ($result['success']) {
                // Create appointment with the found provider
                $appointment = new Appointment([
                    'solicitation_id' => $solicitation->id,
                    'provider_type' => $result['provider']['provider_type'],
                    'provider_id' => $result['provider']['provider_id'],
                    'status' => Appointment::STATUS_SCHEDULED,
                    'created_by' => Auth::id()
                ]);

                $appointment->save();
                
                // Mark solicitation as scheduled
                $solicitation->markAsScheduled(true);
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Solicitation created and provider found',
                    'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments']))
                ]);
            } else {
                // If no provider found, keep as pending
                $solicitation->markAsPending();
                
                DB::commit();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Solicitation created but no provider found immediately',
                    'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy']))
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating solicitation: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified solicitation.
     *
     * @param Solicitation $solicitation
     * @return SolicitationResource|JsonResponse
     */
    public function show(Solicitation $solicitation)
    {
        try {
            // Check if user has permission to view this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this solicitation'
                ], 403);
            }

            // Load relationships
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments.provider']);

            return new SolicitationResource($solicitation);
        } catch (\Exception $e) {
            Log::error('Error retrieving solicitation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified solicitation in storage.
     *
     * @param Request $request
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function update(Request $request, Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to update this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this solicitation'
                ], 403);
            }

            // Check if solicitation can be updated
            if (!$solicitation->isPending() && !$solicitation->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solicitation cannot be updated in its current state'
                ], 422);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'priority' => 'sometimes|in:low,normal,high',
                'description' => 'sometimes|required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Track changes for notification
            $changes = [];
            $updateFields = ['priority', 'description'];
            
            // Save original values for tracking changes
            foreach ($updateFields as $field) {
                if ($request->has($field) && $solicitation->{$field} != $request->{$field}) {
                    $changes[$field] = [
                        'from' => $solicitation->{$field},
                        'to' => $request->{$field}
                    ];
                }
            }

            // Update the solicitation
            $solicitation->update($request->only($updateFields));

            // Reload relationships
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy']);
            
            // Send notification to super admins about the update
            if (!empty($changes)) {
                $this->notificationService->notifySolicitationUpdated($solicitation, $changes);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Solicitation updated successfully',
                'data' => new SolicitationResource($solicitation)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating solicitation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified solicitation from storage.
     *
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function destroy(Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to delete this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this solicitation'
                ], 403);
            }

            // Check if solicitation can be deleted
            if (!$solicitation->isPending()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending solicitations can be deleted'
                ], 422);
            }

            $solicitation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Solicitation deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting solicitation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel the specified solicitation.
     *
     * @param Request $request
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function cancel(Request $request, Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to cancel this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to cancel this solicitation'
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'cancel_reason' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Cancel the solicitation
            if (!$solicitation->cancel($request->cancel_reason)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solicitation cannot be cancelled in its current state'
                ], 422);
            }

            // Cancel related appointments if any
            foreach ($solicitation->appointments as $appointment) {
                if ($appointment->status !== 'completed' && $appointment->status !== 'cancelled') {
                    $appointment->update([
                        'status' => 'cancelled',
                        'cancelled_date' => now(),
                        'notes' => 'Cancelled due to solicitation cancellation. ' . ($request->cancel_reason ?? '')
                    ]);
                }
            }

            // Reload relationships
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments']);

            return response()->json([
                'success' => true,
                'message' => 'Solicitation cancelled successfully',
                'data' => new SolicitationResource($solicitation)
            ]);
        } catch (\Exception $e) {
            Log::error('Error cancelling solicitation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger manual scheduling for a solicitation.
     *
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function schedule(Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to schedule this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to schedule this solicitation'
                ], 403);
            }

            // Check if solicitation can be scheduled
            if (!$solicitation->isPending() && !$solicitation->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solicitation cannot be scheduled in its current state'
                ], 422);
            }

            // Set the solicitation to processing status
            $solicitation->markAsProcessing();

            // Attempt scheduling
            $scheduler = new AppointmentScheduler();
            $result = $scheduler->findBestProvider($solicitation);

            if ($result['success']) {
                // Create appointment with the found provider
                $appointment = new Appointment([
                    'solicitation_id' => $solicitation->id,
                    'provider_type' => $result['provider']['provider_type'],
                    'provider_id' => $result['provider']['provider_id'],
                    'status' => Appointment::STATUS_SCHEDULED,
                    'created_by' => Auth::id()
                ]);

                $appointment->save();
                
                // Mark solicitation as scheduled
                $solicitation->markAsScheduled(true);

                return response()->json([
                    'success' => true,
                    'message' => 'Solicitation scheduled successfully',
                    'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments.provider']))
                ]);
            } else {
                // If scheduling failed, mark the solicitation as pending
                $solicitation->markAsPending();
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to schedule solicitation',
                    'error' => $result['message']
                ], 422);
            }
        } catch (\Exception $e) {
            Log::error('Error scheduling solicitation: ' . $e->getMessage());
            
            // Mark the solicitation as pending
            $solicitation->markAsPending();
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete a solicitation.
     *
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function complete(Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to complete this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to complete this solicitation'
                ], 403);
            }

            // Check if all appointments are completed
            $allCompleted = true;
            foreach ($solicitation->appointments as $appointment) {
                if ($appointment->status !== 'completed') {
                    $allCompleted = false;
                    break;
                }
            }

            if (!$allCompleted) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot complete solicitation until all appointments are completed'
                ], 422);
            }

            // Mark as completed
            if (!$solicitation->markAsCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solicitation cannot be completed in its current state'
                ], 422);
            }

            // Reload relationships
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments.provider']);

            return response()->json([
                'success' => true,
                'message' => 'Solicitation completed successfully',
                'data' => new SolicitationResource($solicitation)
            ]);
        } catch (\Exception $e) {
            Log::error('Error completing solicitation: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to complete solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Attempt to schedule a solicitation automatically.
     *
     * @param Solicitation $solicitation
     * @return bool
     */
    protected function attemptAutoScheduling(Solicitation $solicitation): bool
    {
        // Check if automatic scheduling is enabled
        if (!SchedulingConfigService::isAutomaticSchedulingEnabled()) {
            Log::info("Automatic scheduling disabled for solicitation #{$solicitation->id}");
            return false;
        }

        try {
            Log::info("Starting automatic scheduling for solicitation #{$solicitation->id}");
            
            // Find best provider based on TUSS specialty only
            $scheduler = new AppointmentScheduler();
            $result = $scheduler->findBestProvider($solicitation);
            
            if ($result['success']) {
                // Create appointment with the found provider
                $appointment = new Appointment([
                    'solicitation_id' => $solicitation->id,
                    'provider_type' => $result['provider']['provider_type'],
                    'provider_id' => $result['provider']['provider_id'],
                    'status' => Appointment::STATUS_SCHEDULED,
                    'created_by' => Auth::id()
                ]);

                $appointment->save();
                
                // Mark solicitation as scheduled
                $solicitation->markAsScheduled(true);
                
                Log::info("Successfully scheduled appointment for solicitation #{$solicitation->id}");
                return true;
            } else {
                // If no provider found and solicitation is still in processing, mark it as pending
                if ($solicitation->isProcessing()) {
                    $solicitation->markAsPending();
                    Log::info("No provider found for solicitation #{$solicitation->id}, marked as pending");
                }
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error in automatic scheduling for solicitation #{$solicitation->id}: " . $e->getMessage());
            
            // Make sure to mark as pending if an exception occurred
            if ($solicitation->isProcessing()) {
                $solicitation->markAsPending();
            }
            
            return false;
        }
    }

    /**
     * Force automatic scheduling of an existing solicitation.
     *
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function forceSchedule(Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to schedule this solicitation
            if (Auth::user()->hasRole('health_plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to schedule this solicitation'
                ], 403);
            }

            // Check if solicitation is in a state that can be scheduled
            if (!$solicitation->isPending() && !$solicitation->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Solicitation cannot be scheduled in its current state'
                ], 422);
            }

            // Mark as processing and attempt auto-scheduling
            $solicitation->markAsProcessing();
            $success = $this->attemptAutoScheduling($solicitation);

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to schedule solicitation automatically',
                    'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments']))
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Solicitation scheduled automatically',
                'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments.provider']))
            ]);
        } catch (\Exception $e) {
            Log::error('Error forcing automatic scheduling: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to schedule solicitation',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 