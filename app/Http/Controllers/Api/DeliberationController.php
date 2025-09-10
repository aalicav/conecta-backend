<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Deliberation;
use App\Services\NotificationService;

class DeliberationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:api');
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of deliberations.
     */
    public function index(Request $request)
    {
        try {
            $query = Deliberation::with([
                'healthPlan',
                'clinic',
                'professional',
                'medicalSpecialty',
                'tussProcedure',
                'appointment',
                'solicitation',
                'createdBy',
                'approvedBy',
                'rejectedBy',
                'cancelledBy',
                'operatorApprovedBy'
            ]);
            
            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            
            // Filter by date range
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->input('from_date'));
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->input('to_date'));
            }
            
            // Filter by health plan
            if ($request->filled('health_plan_id')) {
                $query->where('health_plan_id', $request->input('health_plan_id'));
            }
            
            // Filter by clinic
            if ($request->filled('clinic_id')) {
                $query->where('clinic_id', $request->input('clinic_id'));
            }
            
            // Filter by medical specialty
            if ($request->filled('medical_specialty_id')) {
                $query->where('medical_specialty_id', $request->input('medical_specialty_id'));
            }
            
            // Filter by reason
            if ($request->filled('reason')) {
                $query->where('reason', $request->input('reason'));
            }
            
            // Filter by operator approval requirement
            if ($request->filled('requires_operator_approval')) {
                $query->where('requires_operator_approval', $request->input('requires_operator_approval'));
            }
            
            // Filter by operator approval status
            if ($request->filled('operator_approved')) {
                $query->where('operator_approved', $request->input('operator_approved'));
            }
            
            // Filter by search term
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->where('deliberation_number', 'like', "%{$search}%")
                      ->orWhere('justification', 'like', "%{$search}%")
                      ->orWhereHas('healthPlan', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('clinic', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('professional', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('tussProcedure', function($q2) use ($search) {
                          $q2->where('code', 'like', "%{$search}%")
                             ->orWhere('description', 'like', "%{$search}%");
                      });
                });
            }
            
            // Role-based access
            $user = Auth::user();
            if (!$user->hasRole(['admin', 'super_admin', 'director'])) {
                if ($user->hasRole('commercial')) {
                    // Commercial team can see all
                } else {
                    // Others can only see their own requests
                    $query->where('created_by', $user->id);
                }
            }
            
            $perPage = $request->input('per_page', 15);
            $deliberations = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $deliberations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list deliberations: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list deliberations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created deliberation.
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'health_plan_id' => 'required|exists:health_plans,id',
                'clinic_id' => 'required|exists:clinics,id',
                'professional_id' => 'nullable|exists:professionals,id',
                'medical_specialty_id' => 'required|exists:medical_specialties,id',
                'tuss_procedure_id' => 'nullable|exists:tuss_procedures,id',
                'appointment_id' => 'nullable|exists:appointments,id',
                'solicitation_id' => 'nullable|exists:solicitations,id',
                'negotiated_value' => 'required|numeric|min:0',
                'medlar_percentage' => 'required|numeric|min:0|max:100',
                'original_table_value' => 'nullable|numeric|min:0',
                'reason' => 'required|in:no_table_value,specific_doctor_value,special_agreement,emergency_case,other',
                'justification' => 'required|string|min:10',
                'notes' => 'nullable|string',
                'requires_operator_approval' => 'boolean|nullable'
            ], [
                'health_plan_id.required' => 'O plano de saúde é obrigatório',
                'health_plan_id.exists' => 'O plano de saúde selecionado não existe',
                'clinic_id.required' => 'A clínica é obrigatória',
                'clinic_id.exists' => 'A clínica selecionada não existe',
                'professional_id.exists' => 'O profissional selecionado não existe',
                'medical_specialty_id.required' => 'A especialidade médica é obrigatória',
                'medical_specialty_id.exists' => 'A especialidade médica selecionada não existe',
                'tuss_procedure_id.exists' => 'O procedimento TUSS selecionado não existe',
                'appointment_id.exists' => 'O agendamento selecionado não existe',
                'solicitation_id.exists' => 'A solicitação selecionada não existe',
                'negotiated_value.required' => 'O valor negociado é obrigatório',
                'negotiated_value.min' => 'O valor negociado deve ser maior que zero',
                'medlar_percentage.required' => 'O percentual da Medlar é obrigatório',
                'medlar_percentage.min' => 'O percentual da Medlar deve ser maior ou igual a zero',
                'medlar_percentage.max' => 'O percentual da Medlar deve ser menor ou igual a 100',
                'original_table_value.min' => 'O valor original da tabela deve ser maior ou igual a zero',
                'reason.required' => 'O motivo é obrigatório',
                'reason.in' => 'O motivo selecionado é inválido',
                'justification.required' => 'A justificativa é obrigatória',
                'justification.min' => 'A justificativa deve ter pelo menos 10 caracteres'
            ]);
            
            DB::beginTransaction();
            
            // Calculate Medlar amount and total value
            $medlarAmount = $validated['negotiated_value'] * ($validated['medlar_percentage'] / 100);
            $totalValue = $validated['negotiated_value'] + $medlarAmount;
            
            $deliberation = Deliberation::create([
                'health_plan_id' => $validated['health_plan_id'],
                'clinic_id' => $validated['clinic_id'],
                'professional_id' => $validated['professional_id'] ?? null,
                'medical_specialty_id' => $validated['medical_specialty_id'],
                'tuss_procedure_id' => $validated['tuss_procedure_id'] ?? null,
                'appointment_id' => $validated['appointment_id'] ?? null,
                'solicitation_id' => $validated['solicitation_id'] ?? null,
                'negotiated_value' => $validated['negotiated_value'],
                'medlar_percentage' => $validated['medlar_percentage'],
                'medlar_amount' => $medlarAmount,
                'total_value' => $totalValue,
                'original_table_value' => $validated['original_table_value'] ?? null,
                'reason' => $validated['reason'],
                'justification' => $validated['justification'],
                'notes' => $validated['notes'] ?? null,
                'requires_operator_approval' => $validated['requires_operator_approval'] ?? false,
                'status' => Deliberation::STATUS_PENDING_APPROVAL,
                'created_by' => Auth::id()
            ]);
            
            // Send notification to approvers
            $this->notificationService->sendToRole('network_manager', [
                'title' => 'Nova deliberação de valor',
                'body' => "Foi criada uma nova deliberação de valor: {$deliberation->deliberation_number}",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'dollar-sign',
                'priority' => 'normal'
            ]);
            
            // If requires operator approval, notify operator team
            if ($deliberation->requires_operator_approval) {
                $this->notificationService->sendToRole('operator', [
                    'title' => 'Deliberação requer aprovação da operadora',
                    'body' => "A deliberação {$deliberation->deliberation_number} requer aprovação da operadora",
                    'action_link' => "/deliberations/{$deliberation->id}",
                    'icon' => 'alert-circle',
                    'priority' => 'high'
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Deliberação criada com sucesso',
                'data' => $deliberation->load([
                    'healthPlan',
                    'clinic',
                    'professional',
                    'medicalSpecialty',
                    'tussProcedure',
                    'appointment',
                    'solicitation',
                    'createdBy'
                ])
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar deliberação: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao criar deliberação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified deliberation.
     */
    public function show($id)
    {
        try {
            $deliberation = Deliberation::with([
                'healthPlan',
                'clinic',
                'professional',
                'medicalSpecialty',
                'tussProcedure',
                'appointment',
                'solicitation',
                'billingItem',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'rejectedBy',
                'cancelledBy',
                'operatorApprovedBy'
            ])->findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $deliberation
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get deliberation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'deliberation_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get deliberation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // Not implemented for deliberations - they are immutable once created
        return response()->json([
            'status' => 'error',
            'message' => 'Deliberações não podem ser editadas após criação'
        ], 405);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        // Not implemented - use cancel method instead
        return response()->json([
            'status' => 'error',
            'message' => 'Use o método cancel para cancelar deliberações'
        ], 405);
    }

    /**
     * Approve a deliberation.
     */
    public function approve(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['network_manager', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para aprovar deliberações'
                ], 403);
            }
            
            $validated = $request->validate([
                'approval_notes' => 'nullable|string'
            ]);
            
            $deliberation = Deliberation::findOrFail($id);
            
            if ($deliberation->status !== Deliberation::STATUS_PENDING_APPROVAL) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A deliberação não está aguardando aprovação'
                ], 422);
            }
            
            // Check if operator approval is required and not yet given
            if ($deliberation->requires_operator_approval && $deliberation->operator_approved === null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta deliberação requer aprovação da operadora antes da aprovação interna'
                ], 422);
            }
            
            DB::beginTransaction();
            
            if (!$deliberation->approve($user->id, $validated['approval_notes'] ?? null)) {
                throw new \Exception('Failed to approve deliberation');
            }
            
            // Notify the creator
            $this->notificationService->sendToUser($deliberation->created_by, [
                'title' => 'Deliberação aprovada',
                'body' => "Sua deliberação {$deliberation->deliberation_number} foi aprovada",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'check-circle',
                'priority' => 'high'
            ]);
            
            // Notify billing team
            $this->notificationService->sendToRole('billing', [
                'title' => 'Deliberação aprovada para faturamento',
                'body' => "A deliberação {$deliberation->deliberation_number} foi aprovada e está pronta para faturamento",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'dollar-sign',
                'priority' => 'normal'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Deliberação aprovada com sucesso',
                'data' => $deliberation->fresh([
                    'healthPlan',
                    'clinic',
                    'professional',
                    'medicalSpecialty',
                    'tussProcedure',
                    'appointment',
                    'solicitation',
                    'createdBy',
                    'approvedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve deliberation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'deliberation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve deliberation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a deliberation.
     */
    public function reject(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['network_manager', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para rejeitar deliberações'
                ], 403);
            }
            
            $validated = $request->validate([
                'rejection_reason' => 'required|string|min:10'
            ]);
            
            $deliberation = Deliberation::findOrFail($id);
            
            if ($deliberation->status !== Deliberation::STATUS_PENDING_APPROVAL) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'A deliberação não está aguardando aprovação'
                ], 422);
            }
            
            DB::beginTransaction();
            
            if (!$deliberation->reject($user->id, $validated['rejection_reason'])) {
                throw new \Exception('Failed to reject deliberation');
            }
            
            // Notify the creator
            $this->notificationService->sendToUser($deliberation->created_by, [
                'title' => 'Deliberação rejeitada',
                'body' => "Sua deliberação {$deliberation->deliberation_number} foi rejeitada",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'x-circle',
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Deliberação rejeitada com sucesso',
                'data' => $deliberation->fresh([
                    'healthPlan',
                    'clinic',
                    'professional',
                    'medicalSpecialty',
                    'tussProcedure',
                    'appointment',
                    'solicitation',
                    'createdBy',
                    'rejectedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject deliberation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'deliberation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject deliberation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve by operator.
     */
    public function approveByOperator(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['operator', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para aprovar deliberações em nome da operadora'
                ], 403);
            }
            
            $validated = $request->validate([
                'operator_approval_notes' => 'nullable|string'
            ]);
            
            $deliberation = Deliberation::findOrFail($id);
            
            if (!$deliberation->requires_operator_approval) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta deliberação não requer aprovação da operadora'
                ], 422);
            }
            
            if ($deliberation->operator_approved !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta deliberação já foi avaliada pela operadora'
                ], 422);
            }
            
            DB::beginTransaction();
            
            if (!$deliberation->approveByOperator($user->id, $validated['operator_approval_notes'] ?? null)) {
                throw new \Exception('Failed to approve deliberation by operator');
            }
            
            // Notify the creator
            $this->notificationService->sendToUser($deliberation->created_by, [
                'title' => 'Deliberação aprovada pela operadora',
                'body' => "A deliberação {$deliberation->deliberation_number} foi aprovada pela operadora",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'check-circle',
                'priority' => 'high'
            ]);
            
            // Notify network managers for internal approval
            $this->notificationService->sendToRole('network_manager', [
                'title' => 'Deliberação aprovada pela operadora - aguardando aprovação interna',
                'body' => "A deliberação {$deliberation->deliberation_number} foi aprovada pela operadora e aguarda aprovação interna",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'alert-circle',
                'priority' => 'normal'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Deliberação aprovada pela operadora com sucesso',
                'data' => $deliberation->fresh([
                    'healthPlan',
                    'clinic',
                    'professional',
                    'medicalSpecialty',
                    'tussProcedure',
                    'appointment',
                    'solicitation',
                    'createdBy',
                    'operatorApprovedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve deliberation by operator: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'deliberation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve deliberation by operator',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject by operator.
     */
    public function rejectByOperator(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['operator', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para rejeitar deliberações em nome da operadora'
                ], 403);
            }
            
            $validated = $request->validate([
                'operator_rejection_reason' => 'required|string|min:10'
            ]);
            
            $deliberation = Deliberation::findOrFail($id);
            
            if (!$deliberation->requires_operator_approval) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta deliberação não requer aprovação da operadora'
                ], 422);
            }
            
            if ($deliberation->operator_approved !== null) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta deliberação já foi avaliada pela operadora'
                ], 422);
            }
            
            DB::beginTransaction();
            
            if (!$deliberation->rejectByOperator($user->id, $validated['operator_rejection_reason'])) {
                throw new \Exception('Failed to reject deliberation by operator');
            }
            
            // Notify the creator
            $this->notificationService->sendToUser($deliberation->created_by, [
                'title' => 'Deliberação rejeitada pela operadora',
                'body' => "A deliberação {$deliberation->deliberation_number} foi rejeitada pela operadora",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'x-circle',
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Deliberação rejeitada pela operadora com sucesso',
                'data' => $deliberation->fresh([
                    'healthPlan',
                    'clinic',
                    'professional',
                    'medicalSpecialty',
                    'tussProcedure',
                    'appointment',
                    'solicitation',
                    'createdBy',
                    'operatorApprovedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject deliberation by operator: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'deliberation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject deliberation by operator',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a deliberation.
     */
    public function cancel(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['network_manager', 'commercial', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para cancelar deliberações'
                ], 403);
            }
            
            $validated = $request->validate([
                'cancellation_reason' => 'required|string|min:10'
            ]);
            
            $deliberation = Deliberation::findOrFail($id);
            
            if ($deliberation->isBilled() || $deliberation->isCancelled()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta deliberação não pode ser cancelada'
                ], 422);
            }
            
            DB::beginTransaction();
            
            if (!$deliberation->cancel($user->id, $validated['cancellation_reason'])) {
                throw new \Exception('Failed to cancel deliberation');
            }
            
            // Notify the creator
            $this->notificationService->sendToUser($deliberation->created_by, [
                'title' => 'Deliberação cancelada',
                'body' => "A deliberação {$deliberation->deliberation_number} foi cancelada",
                'action_link' => "/deliberations/{$deliberation->id}",
                'icon' => 'x-circle',
                'priority' => 'normal'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Deliberação cancelada com sucesso',
                'data' => $deliberation->fresh([
                    'healthPlan',
                    'clinic',
                    'professional',
                    'medicalSpecialty',
                    'tussProcedure',
                    'appointment',
                    'solicitation',
                    'createdBy',
                    'cancelledBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to cancel deliberation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'deliberation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel deliberation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get deliberation statistics.
     */
    public function statistics(Request $request)
    {
        try {
            $query = Deliberation::query();
            
            // Apply date filters
            if ($request->filled('from_date')) {
                $query->whereDate('created_at', '>=', $request->input('from_date'));
            }
            if ($request->filled('to_date')) {
                $query->whereDate('created_at', '<=', $request->input('to_date'));
            }
            
            $statistics = [
                'total' => $query->count(),
                'pending_approval' => $query->clone()->pendingApproval()->count(),
                'approved' => $query->clone()->approved()->count(),
                'rejected' => $query->clone()->rejected()->count(),
                'billed' => $query->clone()->billed()->count(),
                'cancelled' => $query->clone()->cancelled()->count(),
                'requiring_operator_approval' => $query->clone()->requiringOperatorApproval()->count(),
                'operator_approved' => $query->clone()->operatorApproved()->count(),
                'operator_rejected' => $query->clone()->operatorRejected()->count(),
                'total_negotiated_value' => $query->clone()->sum('negotiated_value'),
                'total_medlar_amount' => $query->clone()->sum('medlar_amount'),
                'total_value' => $query->clone()->sum('total_value'),
            ];
            
            return response()->json([
                'status' => 'success',
                'data' => $statistics
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get deliberation statistics: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get deliberation statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
