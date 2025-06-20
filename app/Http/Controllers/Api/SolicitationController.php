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
use App\Jobs\ProcessAutomaticScheduling;
use App\Models\Professional;

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
        
        // Filter solicitations requested for the current professional or clinic
        if ($request->has('requested_for_me') && $request->requested_for_me === 'true') {
            if (Auth::user()->hasRole('professional') || Auth::user()->hasRole('clinic')) {
                $query->whereHas('invites', function ($q) {
                    $q->where('provider_type', Auth::user()->hasRole('professional') ? 'professional' : 'clinic')
                      ->where('provider_id', Auth::user()->entity_id)
                      ->where('status', 'accepted');
                });
            }
        }
        
        // Restrict health plan users to only see their own solicitations
        if (Auth::user()->hasRole('plan_admin')) {
            $query->whereHas('healthPlan', function ($q) {
                $q->where('id', Auth::user()->entity_id);
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

    protected function validateSolicitation(Request $request)
    {
        return $request->validate([
            'health_plan_id' => 'required|exists:health_plans,id',
            'patient_id' => 'required|exists:patients,id',
            'tuss_id' => 'required|exists:tuss_procedures,id',
            'state' => 'nullable|string|size:2',
            'city' => 'nullable|string',
            'preferred_date_start' => 'nullable|date|after_or_equal:today',
            'preferred_date_end' => 'nullable|date|after_or_equal:preferred_date_start',
        ]);
    }

    /**
     * Check if a solicitation already has pending invites.
     *
     * @param int $solicitationId
     * @return bool
     */
    protected function hasPendingInvites(int $solicitationId): bool
    {
        return \App\Models\SolicitationInvite::where('solicitation_id', $solicitationId)
            ->where('status', 'pending')
            ->exists();
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
            DB::beginTransaction();

            $validated = $this->validateSolicitation($request);

            // If preferred dates are not provided, set default range (next 14 days)
            if (!isset($validated['preferred_date_start'])) {
                $validated['preferred_date_start'] = now()->addDay();
            }
            if (!isset($validated['preferred_date_end'])) {
                $validated['preferred_date_end'] = now()->addDays(14);
            }
            if(!isset($validated['requested_by'])){
                $validated['requested_by'] = Auth::user()->id;
            }

            // Get health plan
            $healthPlan = HealthPlan::findOrFail($validated['health_plan_id']);

            // Check if health plan is approved
            if (!$healthPlan->approved_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'O plano de saúde não está aprovado'
                ], 422);
            }

            // Check if health plan has signed contract
            if (!$healthPlan->has_signed_contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'O plano de saúde não possui contrato assinado'
                ], 422);
            }

            // Check if health plan has pending status
            if ($healthPlan->status !== 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'O plano de saúde foi editado e requer reaprovação'
                ], 422);
            }

            // Verify that the patient belongs to the health plan
            $patient = Patient::findOrFail($validated['patient_id']);
            if ($patient->health_plan_id != $validated['health_plan_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'O paciente não pertence ao plano de saúde especificado'
                ], 422);
            }

            // Create the solicitation
            $solicitation = Solicitation::create($validated);

            // Load relationships for the resource
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy']);

            // Set as processing immediately before scheduling attempt
            $solicitation->markAsProcessing();

            // This will notify health plan admins and super admins
            $this->notificationService->notifySolicitationCreated($solicitation);

            // Dispatch the automatic scheduling job
            ProcessAutomaticScheduling::dispatch($solicitation);
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Solicitação criada e enviada para processamento',
                'data' => new SolicitationResource($solicitation)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao criar solicitação: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Falha ao criar solicitação',
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
            if (Auth::user()->hasRole('plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a visualizar esta solicitação'
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
            if (Auth::user()->hasRole('plan_admin') && 
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
                    'message' => 'A solicitação não pode ser atualizada no estado atual'
                ], 422);
            }

            DB::beginTransaction();

            $validated = $this->validateSolicitation($request);

            // Only update preferred dates if they are provided
            if (isset($validated['preferred_date_start'])) {
                $solicitation->preferred_date_start = $validated['preferred_date_start'];
            }
            if (isset($validated['preferred_date_end'])) {
                $solicitation->preferred_date_end = $validated['preferred_date_end'];
            }

            // Track changes for notification
            $changes = [];
            $updateFields = ['priority', 'description', 'state', 'city'];
            
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
            if (Auth::user()->hasRole('plan_admin') && 
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
                    'message' => 'Apenas solicitações pendentes podem ser excluídas'
                ], 422);
            }

            $solicitation->delete();

            return response()->json([
                'success' => true,
                'message' => 'Solicitação excluída com sucesso'
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
            if (Auth::user()->hasRole('plan_admin') && 
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
                    'message' => 'A solicitação não pode ser cancelada no estado atual'
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
                'message' => 'Solicitação cancelada com sucesso',
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
            if (Auth::user()->hasRole('plan_admin') && 
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
                    'message' => 'A solicitação não pode ser agendada no estado atual'
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
                    'message' => 'Solicitação agendada com sucesso',
                    'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments.provider']))
                ]);
            } else {
                // If scheduling failed, mark the solicitation as pending
                $solicitation->markAsPending();
                
                return response()->json([
                    'success' => false,
                    'message' => 'Falha ao agendar solicitação',
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
            if (Auth::user()->hasRole('plan_admin') && 
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
                    'message' => 'Não é possível completar a solicitação até que todas as consultas sejam concluídas'
                ], 422);
            }

            // Mark as completed
            if (!$solicitation->markAsCompleted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A solicitação não pode ser concluída no estado atual'
                ], 422);
            }

            // Reload relationships
            $solicitation->load(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments.provider']);

            return response()->json([
                'success' => true,
                'message' => 'Solicitação concluída com sucesso',
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
     * Force automatic scheduling of an existing solicitation, optionally cancelling pending invites.
     *
     * @param Request $request
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function forceSchedule(Request $request, Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to schedule this solicitation
            if (Auth::user()->hasRole('plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a agendar esta solicitação'
                ], 403);
            }

            // Check if solicitation is in a state that can be scheduled
            if (!$solicitation->isPending() && !$solicitation->isProcessing()) {
                return response()->json([
                    'success' => false,
                    'message' => 'A solicitação não pode ser agendada no estado atual'
                ], 422);
            }

            // Check if there are already pending invites for this solicitation
            $existingInvites = \App\Models\SolicitationInvite::where('solicitation_id', $solicitation->id)
                ->where('status', 'pending')
                ->count();

            $cancelPendingInvites = $request->boolean('cancel_pending_invites', false);

            if ($existingInvites > 0) {
                if (!$cancelPendingInvites) {
                    return response()->json([
                        'success' => false,
                        'message' => "A solicitação já possui {$existingInvites} convites pendentes. Use o parâmetro 'cancel_pending_invites=true' para cancelá-los e reprocessar.",
                        'existing_invites' => $existingInvites
                    ], 422);
                }

                // Cancel existing pending invites
                \App\Models\SolicitationInvite::where('solicitation_id', $solicitation->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'responded_at' => now(),
                        'response_notes' => 'Cancelado para reprocessamento da solicitação'
                    ]);

                Log::info("Cancelados {$existingInvites} convites pendentes para reprocessamento da solicitação #{$solicitation->id}");
            }

            // Mark as processing and dispatch the job
            $solicitation->markAsProcessing();
            ProcessAutomaticScheduling::dispatch($solicitation);

            return response()->json([
                'success' => true,
                'message' => 'Solicitação enviada para agendamento automático',
                'cancelled_invites' => $cancelPendingInvites ? $existingInvites : 0,
                'data' => new SolicitationResource($solicitation->fresh(['healthPlan', 'patient', 'tuss', 'requestedBy', 'appointments']))
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao forçar agendamento automático: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Falha ao agendar solicitação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process pending solicitations.
     *
     * @return JsonResponse
     */
    public function processPending(): JsonResponse
    {
        try {
            // Get only pending solicitations (not processing ones)
            $pendingSolicitations = Solicitation::where('status', Solicitation::STATUS_PENDING)->get();

            $processed = 0;
            $scheduled = 0;
            $failed = 0;
            $skipped = 0;

            foreach ($pendingSolicitations as $solicitation) {
                try {
                    // Check if there are already pending invites for this solicitation
                    $existingInvites = \App\Models\SolicitationInvite::where('solicitation_id', $solicitation->id)
                        ->where('status', 'pending')
                        ->count();

                    if ($existingInvites > 0) {
                        Log::info("Solicitação #{$solicitation->id} já possui {$existingInvites} convites pendentes. Pulando processamento.");
                        $skipped++;
                        $processed++;
                        continue;
                    }

                    // Mark as processing
                    $solicitation->markAsProcessing();

                    // Try to find best provider directly
                    $scheduler = new AppointmentScheduler();
                    $result = $scheduler->findBestProvider($solicitation);

                    if ($result['success']) {
                        // Create appointment with the found provider
                        $appointment = new Appointment([
                            'solicitation_id' => $solicitation->id,
                            'provider_type' => $result['provider']['provider_type'],
                            'provider_id' => $result['provider']['provider_id'],
                            'status' => Appointment::STATUS_SCHEDULED,
                            'created_by' => $solicitation->requested_by
                        ]);

                        $appointment->save();
                        
                        // Mark solicitation as scheduled
                        $solicitation->markAsScheduled(true);
                        $scheduled++;
                    } else {
                        // If no provider found, dispatch job to send invites
                        ProcessAutomaticScheduling::dispatch($solicitation);
                        Log::info("Enviado job de agendamento automático para solicitação #{$solicitation->id}");
                        $failed++;
                    }

                    $processed++;
                } catch (\Exception $e) {
                    Log::error("Erro ao processar solicitação #{$solicitation->id}: " . $e->getMessage());
                    $solicitation->markAsPending();
                    $failed++;
                    $processed++;
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Processamento concluído: {$scheduled} agendadas, {$failed} enviadas para convites, {$skipped} puladas (já possuem convites)",
                'data' => [
                    'total_processed' => $processed,
                    'scheduled' => $scheduled,
                    'sent_for_invites' => $failed,
                    'skipped' => $skipped
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao processar solicitações pendentes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Falha ao processar solicitações pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get pending invites for a solicitation.
     *
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function pendingInvites(Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to view this solicitation
            if (Auth::user()->hasRole('plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a visualizar esta solicitação'
                ], 403);
            }

            $pendingInvites = \App\Models\SolicitationInvite::with(['provider'])
                ->where('solicitation_id', $solicitation->id)
                ->where('status', 'pending')
                ->get();

            $invitesSummary = $pendingInvites->map(function ($invite) {
                $provider = $invite->provider;
                return [
                    'id' => $invite->id,
                    'provider_type' => $invite->provider_type,
                    'provider_id' => $invite->provider_id,
                    'provider_name' => $provider ? $provider->name : 'Provider not found',
                    'price' => $invite->price ?? null,
                    'created_at' => $invite->created_at,
                    'status' => $invite->status
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'solicitation_id' => $solicitation->id,
                    'total_pending_invites' => $pendingInvites->count(),
                    'invites' => $invitesSummary
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao buscar convites pendentes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Falha ao buscar convites pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel all pending invites for a solicitation.
     *
     * @param Request $request
     * @param Solicitation $solicitation
     * @return JsonResponse
     */
    public function cancelPendingInvites(Request $request, Solicitation $solicitation): JsonResponse
    {
        try {
            // Check if user has permission to modify this solicitation
            if (Auth::user()->hasRole('plan_admin') && 
                Auth::user()->health_plan_id != $solicitation->health_plan_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não autorizado a modificar esta solicitação'
                ], 403);
            }

            $reason = $request->input('reason', 'Cancelado via API');

            $cancelledCount = \App\Models\SolicitationInvite::where('solicitation_id', $solicitation->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'responded_at' => now(),
                    'response_notes' => $reason
                ]);

            Log::info("Cancelados {$cancelledCount} convites pendentes para solicitação #{$solicitation->id}");

            return response()->json([
                'success' => true,
                'message' => "Cancelados {$cancelledCount} convites pendentes",
                'data' => [
                    'solicitation_id' => $solicitation->id,
                    'cancelled_invites' => $cancelledCount
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao cancelar convites pendentes: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Falha ao cancelar convites pendentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available professionals for a solicitation.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function getAvailableProfessionals($id): JsonResponse
    {
        try {
            $solicitation = Solicitation::findOrFail($id);

            // Get professionals that offer the required procedure
            $professionals = Professional::whereHas('procedures', function ($query) use ($solicitation) {
                $query->where('tuss_procedure_id', $solicitation->tuss_id);
            })
            ->where('status', 'approved')
            ->where('is_active', true)
            ->with(['addresses' => function ($query) {
                $query->where('is_active', true);
            }])
            ->get();

            return response()->json([
                'success' => true,
                'data' => $professionals
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting available professionals: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar profissionais disponíveis',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 