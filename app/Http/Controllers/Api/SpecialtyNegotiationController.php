<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use App\Models\ValueVerification;
use App\Models\Tuss;
use App\Services\NotificationService;

class SpecialtyNegotiationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:api');
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of specialty negotiations for professionals or clinics.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $entityType = $request->input('entity_type', 'professional');
            $entityId = $request->input('entity_id');
            
            if (!$entityId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Entity ID is required'
                ], 400);
            }

            // Get the model type based on entity type
            $modelClass = $entityType === 'professional' ? Professional::class : Clinic::class;
            
            // Get negotiations for the entity
            $query = Negotiation::with(['items.tuss', 'creator'])
                ->where('negotiable_type', $modelClass)
                ->where('negotiable_id', $entityId);
            
            // Apply filters
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            
            // Sort by the most recent first
            $query->orderBy('created_at', 'desc');
            
            $negotiations = $query->paginate($request->input('per_page', 15));
            
            return response()->json([
                'status' => 'success',
                'data' => $negotiations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list specialty negotiations: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list specialty negotiations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created specialty negotiation.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'entity_type' => 'required|in:professional,clinic',
                'entity_id' => 'required|integer',
                'title' => 'required|string',
                'description' => 'nullable|string',
                'items' => 'required|array|min:1',
                'items.*.tuss_id' => 'required|exists:tuss_procedures,id',
                'items.*.proposed_value' => 'required|numeric|min:0',
                'items.*.notes' => 'nullable|string',
            ]);
            
            // Check if entity exists
            $entity = null;
            
            if ($validated['entity_type'] === 'professional') {
                $entity = Professional::find($validated['entity_id']);
                $entityClass = Professional::class;
            } else { // clinic
                $entity = Clinic::find($validated['entity_id']);
                $entityClass = Clinic::class;
            }
            
            if (!$entity) {
                return response()->json([
                    'status' => 'error',
                    'message' => ucfirst($validated['entity_type']) . ' not found'
                ], 404);
            }
            
            DB::beginTransaction();
            
            // Create a negotiation
            $negotiation = Negotiation::create([
                'negotiable_type' => $entityClass,
                'negotiable_id' => $entity->id,
                'creator_id' => Auth::id(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => 'draft',
                'start_date' => now(),
                'end_date' => now()->addMonths(12),
            ]);
            
            // Add negotiation items
            foreach ($validated['items'] as $item) {
                $negotiation->items()->create([
                    'tuss_id' => $item['tuss_id'],
                    'proposed_value' => $item['proposed_value'],
                    'notes' => $item['notes'] ?? null,
                    'status' => 'pending'
                ]);
            }
            
            // Create a value verification for double-checking
            $totalValue = array_sum(array_column($validated['items'], 'proposed_value'));
            $valueVerification = new ValueVerification([
                'entity_type' => 'negotiation',
                'entity_id' => $negotiation->id,
                'original_value' => $totalValue,
                'notes' => "Verificação de valores para " . ($validated['entity_type'] === 'professional' ? 'profissional' : 'clínica') . " {$entity->name}",
                'requester_id' => Auth::id(),
                'status' => 'pending'
            ]);
            $valueVerification->save();
            
            // Notify directors for value verification
            $this->notificationService->sendToRole('director', [
                'title' => 'Nova Verificação de Valor',
                'body' => "Valores de negociação de especialidades para " . ($validated['entity_type'] === 'professional' ? 'o profissional' : 'a clínica') . " {$entity->name} precisam ser verificados.",
                'action_link' => "/value-verifications/{$valueVerification->id}",
                'icon' => 'dollar-sign',
                'channels' => ['system']
            ]);
            
            // Notify commercial team about new negotiation
            $this->notificationService->sendToRole('commercial', [
                'title' => 'Nova Negociação de Especialidades',
                'body' => "Uma nova negociação de especialidades foi iniciada para " . ($validated['entity_type'] === 'professional' ? 'o profissional' : 'a clínica') . " {$entity->name}.",
                'action_link' => "/negotiations/{$negotiation->id}",
                'icon' => 'briefcase',
                'channels' => ['system']
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Negociação de especialidades criada com sucesso',
                'data' => $negotiation->load(['items.tuss', 'creator'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create specialty negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create specialty negotiation',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified specialty negotiation.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $negotiation = Negotiation::with(['items.tuss', 'creator', 'negotiable'])
                ->findOrFail($id);
            
            // Get value verification status if exists
            $valueVerification = ValueVerification::where('entity_type', 'negotiation')
                ->where('entity_id', $negotiation->id)
                ->latest()
                ->first();
            
            $data = $negotiation->toArray();
            $data['value_verification'] = $valueVerification;
            
            return response()->json([
                'status' => 'success',
                'data' => $data
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get specialty negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get specialty negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Submit the negotiation for approval.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit($id)
    {
        try {
            $negotiation = Negotiation::findOrFail($id);
            
            if ($negotiation->status !== 'draft') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only draft negotiations can be submitted'
                ], 422);
            }
            
            $entity = $negotiation->negotiable;
            
            DB::beginTransaction();
            
            // Update status
            $negotiation->status = 'submitted';
            $negotiation->save();
            
            // Notify commercial team for review
            $this->notificationService->sendToRole('commercial', [
                'title' => 'Negociação de Especialidades Submetida',
                'body' => "Uma negociação de especialidades foi submetida para " . (get_class($entity) === Professional::class ? 'o profissional' : 'a clínica') . " {$entity->name} e precisa de revisão.",
                'action_link' => "/negotiations/{$negotiation->id}",
                'icon' => 'clipboard',
                'channels' => ['system', 'email'],
                'priority' => 'medium'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Negociação submetida com sucesso',
                'data' => $negotiation->fresh(['items.tuss', 'creator', 'negotiable'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to submit specialty negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit specialty negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Approve the negotiation after all reviews.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function approve(Request $request, $id)
    {
        try {
            // Only commercial, legal, or director can approve
            $user = Auth::user();
            if (!$user->hasRole(['commercial', 'legal', 'director', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to approve negotiations'
                ], 403);
            }
            
            $negotiation = Negotiation::with(['items', 'negotiable'])
                ->findOrFail($id);
            
            if ($negotiation->status !== 'submitted') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only submitted negotiations can be approved'
                ], 422);
            }
            
            $entity = $negotiation->negotiable;
            
            // Check for value verification
            $valueVerification = ValueVerification::where('entity_type', 'negotiation')
                ->where('entity_id', $negotiation->id)
                ->where('status', 'pending')
                ->first();
                
            if ($valueVerification) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Esta negociação possui valores pendentes de verificação',
                    'value_verification_id' => $valueVerification->id
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Update negotiation
            $negotiation->status = 'approved';
            $negotiation->approved_at = now();
            $negotiation->approver_id = Auth::id();
            $negotiation->save();
            
            // Update items
            foreach ($negotiation->items as $item) {
                if ($item->status === 'pending') {
                    $item->status = 'approved';
                    $item->approved_value = $item->proposed_value; // Approve as proposed
                    $item->responded_at = now();
                    $item->save();
                }
            }
            
            // Update pricing contracts for the entity
            $entityType = get_class($entity);
            $entityName = $entity->name;
            
            // Apply the negotiated prices to the entity's pricing contracts
            foreach ($negotiation->items as $item) {
                $entity->pricingContracts()
                    ->where('tuss_procedure_id', $item->tuss_id)
                    ->update([
                        'is_active' => false // Deactivate any existing contracts
                    ]);
                
                // Create new contract with approved values
                $entity->pricingContracts()->create([
                    'tuss_procedure_id' => $item->tuss_id,
                    'price' => $item->approved_value,
                    'notes' => $item->notes,
                    'is_active' => true,
                    'start_date' => now(),
                    'created_by' => Auth::id(),
                    'negotiation_id' => $negotiation->id
                ]);
            }
            
            // Notify the creator/requester
            $this->notificationService->sendToUser($negotiation->creator_id, [
                'title' => 'Negociação de Especialidades Aprovada',
                'body' => "Sua negociação de especialidades para " . ($entityType === Professional::class ? 'o profissional' : 'a clínica') . " {$entityName} foi aprovada.",
                'action_link' => "/negotiations/{$negotiation->id}",
                'icon' => 'check-circle',
                'channels' => ['system', 'email'],
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Negociação aprovada com sucesso',
                'data' => $negotiation->fresh(['items.tuss', 'creator', 'negotiable'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to approve specialty negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to approve specialty negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Reject the negotiation.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject(Request $request, $id)
    {
        try {
            // Only commercial, legal, or director can reject
            $user = Auth::user();
            if (!$user->hasRole(['commercial', 'legal', 'director', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to reject negotiations'
                ], 403);
            }
            
            $negotiation = Negotiation::findOrFail($id);
            
            if ($negotiation->status !== 'submitted') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only submitted negotiations can be rejected'
                ], 422);
            }
            
            $validated = $request->validate([
                'rejection_reason' => 'required|string|min:5',
            ]);
            
            $entity = $negotiation->negotiable;
            
            DB::beginTransaction();
            
            // Update negotiation
            $negotiation->status = 'rejected';
            $negotiation->rejection_reason = $validated['rejection_reason'];
            $negotiation->approver_id = Auth::id();
            $negotiation->save();
            
            // Notify the creator/requester
            $this->notificationService->sendToUser($negotiation->creator_id, [
                'title' => 'Negociação de Especialidades Rejeitada',
                'body' => "Sua negociação de especialidades para " . (get_class($entity) === Professional::class ? 'o profissional' : 'a clínica') . " {$entity->name} foi rejeitada. Motivo: {$validated['rejection_reason']}",
                'action_link' => "/negotiations/{$negotiation->id}",
                'icon' => 'x-circle',
                'channels' => ['system', 'email'],
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Negociação rejeitada com sucesso',
                'data' => $negotiation->fresh(['items.tuss', 'creator', 'negotiable'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject specialty negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reject specialty negotiation',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Get procedures with pricing for a professional or clinic.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getProcedurePricing(Request $request)
    {
        try {
            $entityType = $request->input('entity_type', 'professional');
            $entityId = $request->input('entity_id');
            
            if (!$entityId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Entity ID is required'
                ], 400);
            }

            // Get the entity
            $entity = null;
            if ($entityType === 'professional') {
                $entity = Professional::find($entityId);
            } else {
                $entity = Clinic::find($entityId);
            }
            
            if (!$entity) {
                return response()->json([
                    'status' => 'error',
                    'message' => ucfirst($entityType) . ' not found'
                ], 404);
            }
            
            // Get active pricing contracts
            $contracts = $entity->pricingContracts()
                ->with('procedure')
                ->where('is_active', true)
                ->get();
                
            $proceduresWithPricing = $contracts->map(function($contract) {
                return [
                    'id' => $contract->procedure->id,
                    'code' => $contract->procedure->code,
                    'name' => $contract->procedure->name,
                    'description' => $contract->procedure->description,
                    'price' => $contract->price,
                    'notes' => $contract->notes,
                    'last_updated' => $contract->updated_at,
                    'negotiation_id' => $contract->negotiation_id,
                ];
            });
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'entity' => [
                        'id' => $entity->id,
                        'name' => $entity->name,
                        'type' => $entityType
                    ],
                    'procedures' => $proceduresWithPricing
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get procedure pricing: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get procedure pricing',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 