<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ExtemporaneousNegotiation;
use App\Models\Clinic;
use App\Models\HealthPlan;
use App\Models\TussProcedure;
use App\Services\NotificationService;
use Carbon\Carbon;
use App\Models\Solicitation;

class ExtemporaneousNegotiationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:api');
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of extemporaneous negotiations.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = ExtemporaneousNegotiation::with([
                'negotiable', 
                'tussProcedure', 
                'createdBy', 
                'approvedBy'
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
            
            // Filter by entity type
            if ($request->filled('entity_type')) {
                $query->where('negotiable_type', $request->input('entity_type'));
            }
            
            // Filter by entity id
            if ($request->filled('entity_id')) {
                $query->where('negotiable_id', $request->input('entity_id'));
            }
            
            // Filter by search term
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->whereHas('tussProcedure', function($q2) use ($search) {
                        $q2->where('code', 'like', "%{$search}%")
                           ->orWhere('description', 'like', "%{$search}%");
                    })
                    ->orWhereHas('negotiable', function($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%")
                           ->orWhere('cnpj', 'like', "%{$search}%");
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
            $negotiations = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $negotiations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list extemporaneous negotiations: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list extemporaneous negotiations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created extemporaneous negotiation.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'negotiable_type' => 'required|string|in:App\\Models\\Clinic,App\\Models\\Professional',
                'negotiable_id' => 'required|integer|exists:' . strtolower(class_basename($request->negotiable_type)) . 's,id',
                'solicitation_id' => 'nullable|exists:solicitations,id',
                'requested_value' => 'required|numeric|min:0',
                'justification' => 'required|string|min:10',
                'urgency_level' => 'required|in:low,medium,high',
                'is_requiring_addendum' => 'boolean|nullable',
                'addendum_included' => 'boolean|nullable'
            ], [
                'negotiable_type.required' => 'O tipo da entidade é obrigatório',
                'negotiable_type.in' => 'O tipo da entidade deve ser Clinic ou Professional',
                'negotiable_id.required' => 'O ID da entidade é obrigatório',
                'negotiable_id.exists' => 'A entidade selecionada não existe',
                'solicitation_id.exists' => 'A solicitação selecionada não existe',
                'requested_value.required' => 'O valor solicitado é obrigatório',
                'requested_value.min' => 'O valor solicitado deve ser maior que zero',
                'justification.required' => 'A justificativa é obrigatória',
                'justification.min' => 'A justificativa deve ter pelo menos 10 caracteres',
                'urgency_level.required' => 'O nível de urgência é obrigatório',
                'urgency_level.in' => 'O nível de urgência deve ser baixo, médio ou alto'
            ]);
            
            // Get the solicitation to get the TUSS procedure
            $tussId = null;
            $tussProcedureId = null;
            
            if (!empty($validated['solicitation_id'])) {
                $solicitation = Solicitation::with('tuss')->findOrFail($validated['solicitation_id']);
                $tussId = $solicitation->tuss->id;
                $tussProcedureId = $solicitation->tuss->id;
            }
            
            DB::beginTransaction();
            
            $negotiation = ExtemporaneousNegotiation::create([
                'negotiable_type' => $validated['negotiable_type'],
                'negotiable_id' => $validated['negotiable_id'],
                'tuss_id' => $tussId,
                'tuss_procedure_id' => $tussProcedureId,
                'requested_value' => $validated['requested_value'],
                'justification' => $validated['justification'],
                'urgency_level' => $validated['urgency_level'],
                'is_requiring_addendum' => $validated['is_requiring_addendum'] ?? true,
                'addendum_included' => $validated['addendum_included'] ?? false,
                'status' => ExtemporaneousNegotiation::STATUS_PENDING_APPROVAL,
                'requested_by' => Auth::id(),
                'created_by' => Auth::id(),
                'solicitation_id' => $validated['solicitation_id']
            ]);
            
            // Send notification to network managers
            $this->notificationService->sendToRole('network_manager', [
                'title' => 'Nova negociação extemporânea',
                'body' => "Foi solicitada uma negociação extemporânea para " . $negotiation->negotiable->name,
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'alert-circle',
                'priority' => $validated['urgency_level'] === 'high' ? 'high' : 'normal'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Negociação extemporânea criada com sucesso',
                'data' => $negotiation->load(['negotiable', 'tussProcedure', 'requestedBy', 'solicitation'])
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erro de validação',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Erro ao criar negociação extemporânea: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Erro ao criar negociação extemporânea',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified extemporaneous negotiation.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $negotiation = ExtemporaneousNegotiation::with([
                'negotiable', 
                'tussProcedure', 
                'createdBy', 
                'approvedBy',
                'rejectedBy',
                'formalizedBy',
                'cancelledBy',
                'solicitation'
            ])->findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $negotiation
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get extemporaneous negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get extemporaneous negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Approve an extemporaneous negotiation.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['network_manager', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to approve extemporaneous negotiations'
                ], 403);
            }
            
            $validated = $request->validate([
                'approval_notes' => 'nullable|string'
            ], [
            ]);
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            DB::beginTransaction();
            
            $negotiation->update([
                'status' => ExtemporaneousNegotiation::STATUS_APPROVED,
                'approved_by' => $user->id,
                'approved_at' => now(),
                'approval_notes' => $validated['approval_notes'],
                'negotiated_price' => $negotiation->requested_value
            ]);

            // Desativar contratos de preço anteriores para a mesma entidade e código TUSS
            \App\Models\PricingContract::where('contractable_type', $negotiation->negotiable_type)
                ->where('contractable_id', $negotiation->negotiable_id)
                ->where('tuss_procedure_id', $negotiation->tuss_id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'end_date' => now(),
                    'notes' => 'Desativado pela negociação extemporânea #' . $negotiation->id
                ]);

            // Criar novo contrato de preço
            \App\Models\PricingContract::create([
                'tuss_procedure_id' => $negotiation->tuss_id,
                'contractable_type' => $negotiation->negotiable_type,
                'contractable_id' => $negotiation->negotiable_id,
                'price' => $negotiation->requested_value,
                'is_active' => true,
                'start_date' => now(),
                'end_date' => null, // Sem data de término para negociações extemporâneas
                'created_by' => $user->id,
                'medical_specialty_id' => $negotiation->medical_specialty_id,
                'notes' => 'Criado a partir da negociação extemporânea #' . $negotiation->id
            ]);
            
            // Notify the requester
            $this->notificationService->sendToUser($negotiation->created_by, [
                'title' => 'Negociação extemporânea aprovada',
                'body' => "Sua solicitação de negociação extemporânea foi aprovada.",
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'check-circle',
                'priority' => 'high'
            ]);
            
            // Notify commercial team for formalization
            $this->notificationService->sendToRole('commercial', [
                'title' => 'Negociação extemporânea requer formalização',
                'body' => "Uma negociação extemporânea foi aprovada e requer formalização via aditivo.",
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'file-text',
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation approved successfully',
                'data' => $negotiation->fresh([
                    'negotiable', 
                    'tussProcedure', 
                    'createdBy', 
                    'approvedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve extemporaneous negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve extemporaneous negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Reject an extemporaneous negotiation.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['network_manager', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to reject extemporaneous negotiations'
                ], 403);
            }
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            $validated = $request->validate([
                'rejection_notes' => 'required|string|min:10'
            ]);
            
            DB::beginTransaction();
            
            if (!$negotiation->reject($user->id, $validated['rejection_notes'])) {
                throw new \Exception('Failed to reject negotiation');
            }
            
            // Notify the requester
            $this->notificationService->sendToUser($negotiation->created_by, [
                'title' => 'Negociação extemporânea rejeitada',
                'body' => "Sua solicitação de negociação extemporânea foi rejeitada.",
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'x-circle',
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation rejected successfully',
                'data' => $negotiation->fresh([
                    'negotiable', 
                    'tussProcedure', 
                    'createdBy', 
                    'rejectedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject extemporaneous negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject extemporaneous negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Formalize an extemporaneous negotiation.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function formalize(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['commercial', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to formalize extemporaneous negotiations'
                ], 403);
            }
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            $validated = $request->validate([
                'addendum_number' => 'required|string',
                'formalization_notes' => 'nullable|string'
            ]);
            
            DB::beginTransaction();
            
            if (!$negotiation->formalize(
                $user->id, 
                $validated['addendum_number'],
                $validated['formalization_notes'] ?? null
            )) {
                throw new \Exception('Failed to formalize negotiation');
            }
            
            // Notify the requester
            $this->notificationService->sendToUser($negotiation->created_by, [
                'title' => 'Negociação extemporânea formalizada',
                'body' => "Sua negociação extemporânea foi formalizada via aditivo.",
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'check-circle',
                'priority' => 'normal'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation formalized successfully',
                'data' => $negotiation->fresh([
                    'negotiable', 
                    'tussProcedure', 
                    'createdBy', 
                    'formalizedBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to formalize extemporaneous negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to formalize extemporaneous negotiation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel an extemporaneous negotiation.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user->hasRole(['network_manager', 'commercial', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to cancel extemporaneous negotiations'
                ], 403);
            }
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            $validated = $request->validate([
                'cancellation_notes' => 'required|string|min:10'
            ]);
            
            DB::beginTransaction();
            
            if (!$negotiation->cancel($user->id, $validated['cancellation_notes'])) {
                throw new \Exception('Failed to cancel negotiation');
            }
            
            // Notify relevant parties
            $this->notificationService->sendToUser($negotiation->created_by, [
                'title' => 'Negociação extemporânea cancelada',
                'body' => "A negociação extemporânea foi cancelada.",
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'x-circle',
                'priority' => 'normal'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation cancelled successfully',
                'data' => $negotiation->fresh([
                    'negotiable', 
                    'tussProcedure', 
                    'createdBy', 
                    'cancelledBy'
                ])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to cancel extemporaneous negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to cancel extemporaneous negotiation',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 