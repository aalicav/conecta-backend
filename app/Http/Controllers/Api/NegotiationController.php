<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NegotiationResource;
use App\Http\Resources\NegotiationItemResource;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use App\Models\Tuss;
use App\Models\HealthPlan;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class NegotiationController extends Controller
{
    // Define approval level as constant
    const APPROVAL_LEVEL = 'approval';

    // Define approval statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_SUBMITTED = 'submitted'; // Sent to entity
    const STATUS_PENDING = 'pending'; // During internal approval
    const STATUS_COMPLETE = 'complete'; // Approved externally
    const STATUS_PARTIALLY_COMPLETE = 'partially_complete'; // Partially completed externally
    const STATUS_APPROVED = 'approved'; // Approved internally
    const STATUS_PARTIALLY_APPROVED = 'partially_approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';
    // New statuses for enhanced negotiation flow
    const STATUS_FORKED = 'forked'; // Negotiation was split into multiple sub-negotiations
    const STATUS_EXPIRED = 'expired'; // Negotiation expired due to time limits
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_PENDING_DIRECTOR_APPROVAL = 'pending_director_approval';

    /**
     * Create a new controller instance.
     */
    public function __construct(protected NotificationService $notificationService)
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of the negotiations.
     *
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $query = Negotiation::with(['negotiable', 'creator', 'items.tuss']);
        
        // Filtering options
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        // Filter by entity type and id
        if ($request->has('entity_type') && $request->has('entity_id')) {
            $query->where('negotiable_type', $request->entity_type)
                  ->where('negotiable_id', $request->entity_id);
        } else {
            // For backward compatibility
            if ($request->has('health_plan_id')) {
                $query->where('negotiable_type', HealthPlan::class)
                      ->where('negotiable_id', $request->health_plan_id);
            }
        }

        // Filter negotiations based on user role
        $user = Auth::user();
        
        if (!$user->hasRole('admin')) {
            $query->where(function($q) use ($user) {
                // Creator can see their own negotiations
                $q->where('creator_id', $user->id);
                
                // Health plan admin can see negotiations for their plan
                if ($user->hasRole('plan_admin')) {
                    $q->orWhere(function($subQ) use ($user) {
                        $subQ->where('negotiable_type', HealthPlan::class)
                             ->where('negotiable_id', $user->entity_id);
                    });
                }
                
                // Professional can see negotiations for their profile
                if ($user->hasRole('professional')) {
                    $q->orWhere(function($subQ) use ($user) {
                        $subQ->where('negotiable_type', Professional::class)
                             ->where('negotiable_id', $user->entity_id);
                    });
                }
                
                // Clinic admin can see negotiations for their clinic
                if ($user->hasRole('clinic_admin')) {
                    $q->orWhere(function($subQ) use ($user) {
                        $subQ->where('negotiable_type', Clinic::class)
                             ->where('negotiable_id', $user->entity_id);
                    });
                }
            });
        }
        
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }
        
        // Sort options
        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortField, $sortOrder);
        
        $negotiations = $query->paginate($request->input('per_page', 15));
        
        return NegotiationResource::collection($negotiations);
    }

    /**
     * Store a newly created negotiation.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Verificar se é uma aprovação automática
        $autoApprove = $request->has('status') && $request->status === 'approved';

        $validated = $request->validate([
            'entity_type' => 'required|in:App\\Models\\HealthPlan,App\\Models\\Professional,App\\Models\\Clinic',
            'entity_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:draft,submitted,approved',
            'items' => 'required|array|min:1',
            'items.*.tuss_id' => 'required|exists:tuss_procedures,id',
            'items.*.proposed_value' => 'required|numeric|min:0',
            'items.*.status' => 'sometimes|in:pending,approved',
            'items.*.approved_value' => 'required_if:items.*.status,approved|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);
        
        // Check if entity exists
        $entityModel = match($validated['entity_type']) {
            'App\\Models\\HealthPlan' => HealthPlan::class,
            'App\\Models\\Professional' => Professional::class,
            'App\\Models\\Clinic' => Clinic::class,
            default => null
        };
        
        if (!$entityModel || !$entityModel::find($validated['entity_id'])) {
            return response()->json(['message' => 'Entity not found'], 404);
        }
        
        try {
            DB::beginTransaction();
            
            // Define negotiation status - use requested status or default to 'draft'
            $status = $validated['status'] ?? 'draft';
            
            $negotiation = Negotiation::create([
                'negotiable_type' => $validated['entity_type'],
                'negotiable_id' => $validated['entity_id'],
                'creator_id' => Auth::id(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'status' => $status,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'notes' => $validated['notes'] ?? null,
            ]);
            
            // Add approved_at timestamp for approved negotiations
            if ($status === 'approved') {
                $negotiation->approved_at = now();
                $negotiation->save();
            }
            
            foreach ($validated['items'] as $itemData) {
                // Determine item status - use requested status or default to 'pending'
                $itemStatus = $itemData['status'] ?? 'pending';
                
                $item = [
                    'tuss_id' => $itemData['tuss_id'],
                    'proposed_value' => $itemData['proposed_value'],
                    'status' => $itemStatus,
                    'notes' => $itemData['notes'] ?? null,
                ];
                
                // If approved, set approved_value and responded_at
                if ($itemStatus === 'approved') {
                    $item['approved_value'] = $itemData['approved_value'] ?? $itemData['proposed_value'];
                    $item['responded_at'] = now();
                }
                
                $negotiation->items()->create($item);
            }
            
            DB::commit();
            
            // Enviar notificação para a entidade envolvida
            $this->notificationService->notifyNegotiationCreated($negotiation);

            return response()->json([
                'message' => 'Negotiation created successfully',
                'data' => new NegotiationResource($negotiation->load(['negotiable', 'creator', 'items.tuss'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create negotiation', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified negotiation.
     *
     * @param Negotiation $negotiation
     * @return NegotiationResource
     */
    public function show(Negotiation $negotiation)
    {
        $negotiation->load(['negotiable', 'creator', 'items.tuss']);
        return new NegotiationResource($negotiation);
    }

    /**
     * Update the specified negotiation.
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, Negotiation $negotiation)
    {
        // Only draft negotiations can be updated
        if ($negotiation->status !== 'draft') {
            return response()->json(['message' => 'Only draft negotiations can be updated'], 403);
        }
        
        $validated = $request->validate([
            'entity_type' => 'sometimes|required|in:App\\Models\\HealthPlan,App\\Models\\Professional,App\\Models\\Clinic',
            'entity_id' => 'required_with:entity_type|integer',
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after:start_date',
            'notes' => 'nullable|string',
            'items' => 'sometimes|required|array|min:1',
            'items.*.id' => 'nullable|exists:negotiation_items,id',
            'items.*.tuss_id' => 'required|exists:tuss,id',
            'items.*.proposed_value' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);
        
        // Check if entity type and id are changed and valid
        if (isset($validated['entity_type']) && isset($validated['entity_id'])) {
            $entityModel = match($validated['entity_type']) {
                'App\\Models\\HealthPlan' => HealthPlan::class,
                'App\\Models\\Professional' => Professional::class,
                'App\\Models\\Clinic' => Clinic::class,
                default => null
            };
            
            if (!$entityModel || !$entityModel::find($validated['entity_id'])) {
                return response()->json(['message' => 'Entity not found'], 404);
            }
        }
        
        try {
            DB::beginTransaction();
            
            $updateData = [
                'title' => $validated['title'] ?? $negotiation->title,
                'description' => $validated['description'] ?? $negotiation->description,
                'start_date' => $validated['start_date'] ?? $negotiation->start_date,
                'end_date' => $validated['end_date'] ?? $negotiation->end_date,
                'notes' => $validated['notes'] ?? $negotiation->notes,
            ];
            
            // Update entity if provided
            if (isset($validated['entity_type']) && isset($validated['entity_id'])) {
                $updateData['negotiable_type'] = $validated['entity_type'];
                $updateData['negotiable_id'] = $validated['entity_id'];
            }
            
            $negotiation->update($updateData);
            
            if (isset($validated['items'])) {
                // Get existing item IDs
                $existingItemIds = $negotiation->items->pluck('id')->toArray();
                $updatedItemIds = collect($validated['items'])->pluck('id')->filter()->toArray();
                
                // Items to delete (existing but not in updated list)
                $itemsToDelete = array_diff($existingItemIds, $updatedItemIds);
                if (!empty($itemsToDelete)) {
                    NegotiationItem::whereIn('id', $itemsToDelete)->delete();
                }
                
                // Update or create items
                foreach ($validated['items'] as $itemData) {
                    if (isset($itemData['id'])) {
                        // Update existing item
                        $item = NegotiationItem::find($itemData['id']);
                        if ($item && $item->negotiation_id == $negotiation->id) {
                            $item->update([
                                'tuss_id' => $itemData['tuss_id'],
                                'proposed_value' => $itemData['proposed_value'],
                                'notes' => $itemData['notes'] ?? $item->notes,
                            ]);
                        }
                    } else {
                        // Create new item
                        $negotiation->items()->create([
                            'tuss_id' => $itemData['tuss_id'],
                            'proposed_value' => $itemData['proposed_value'],
                            'status' => 'pending',
                            'notes' => $itemData['notes'] ?? null,
                        ]);
                    }
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negotiation updated successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update negotiation', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit the negotiation to entity.
     *
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function submit(Negotiation $negotiation)
    {
        if ($negotiation->status !== 'draft') {
            return response()->json(['message' => 'Only draft negotiations can be submitted'], 403);
        }
        
        if ($negotiation->items->isEmpty()) {
            return response()->json(['message' => 'Cannot submit a negotiation without items'], 400);
        }
        
        try {
            DB::beginTransaction();
            
            // Set to submitted status - sent directly to the entity
            $negotiation->status = self::STATUS_SUBMITTED;
            $negotiation->save();
            
            // Notify entity representatives about the negotiation
            $this->notificationService->notifyNegotiationSubmitted($negotiation);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negotiation submitted to entity',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to submit negotiation', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit the negotiation for internal approval.
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitForApproval(Request $request, Negotiation $negotiation)
    {
        // Can only submit for approval if it's in submitted or partially_approved state
        if (!in_array($negotiation->status, [self::STATUS_SUBMITTED, self::STATUS_PARTIALLY_APPROVED])) {
            return response()->json(['message' => 'Apenas negociações submetidas ou parcialmente aprovadas podem ser enviadas para aprovação interna'], 403);
        }
        
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);
        
        try {
            DB::beginTransaction();
            
            $previousStatus = $negotiation->status;
            
            // Update negotiation status
            $negotiation->status = self::STATUS_PENDING_APPROVAL;
            $negotiation->submission_notes = $validated['notes'] ?? null;
            $negotiation->submitted_for_approval_at = Carbon::now();
            $negotiation->submitted_for_approval_by = Auth::id();
            $negotiation->save();
            
            // Create approval history record for submission
            if (method_exists($negotiation, 'approvalHistory')) {
                $negotiation->approvalHistory()->create([
                    'level' => 'submission',
                    'status' => 'submitted',
                    'user_id' => Auth::id(),
                    'notes' => $validated['notes'] ?? 'Submetido para aprovação interna'
                ]);
            }
            
            // Notify appropriate team members
            $this->notificationService->notifyApprovalRequired($negotiation, 'team');
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negociação enviada para aprovação interna',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao enviar para aprovação: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a negotiation.
     *
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancel(Negotiation $negotiation)
    {
        // Only allow cancellation if not in a final state
        if (in_array($negotiation->status, [self::STATUS_COMPLETE, self::STATUS_CANCELLED, self::STATUS_REJECTED])) {
            return response()->json(['message' => 'Cannot cancel a completed, rejected, or already cancelled negotiation'], 403);
        }
        
        try {
            $negotiation->status = self::STATUS_CANCELLED;
            $negotiation->save();
            
            // Notify all involved parties
            $this->notificationService->notifyNegotiationCancelled($negotiation);
            
            return response()->json([
                'message' => 'Negotiation cancelled successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to cancel negotiation', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Generate a contract based on the negotiation.
     *
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateContract(Negotiation $negotiation)
    {
        // Allow contract generation for approved and partially complete negotiations
        if (!in_array($negotiation->status, [self::STATUS_APPROVED, self::STATUS_COMPLETE, self::STATUS_PARTIALLY_COMPLETE])) {
            return response()->json(['message' => 'Can only generate contracts for approved or completed negotiations'], 403);
        }
        
        try {
            // Implementation will depend on contract generation logic
            // This would typically call the ContractController or a contract service
            
            return response()->json([
                'message' => 'Contract generation initiated',
                'negotiation' => new NegotiationResource($negotiation),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to generate contract', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Respond to a negotiation item (entity representative).
     *
     * @param Request $request
     * @param NegotiationItem $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function respondToItem(Request $request, NegotiationItem $item)
    {
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'approved_value' => 'required_if:status,approved|nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        $negotiation = $item->negotiation;
        
        // Can only respond if negotiation is submitted (to entity)
        if ($negotiation->status !== self::STATUS_SUBMITTED) {
            return response()->json(['message' => 'Can only respond to items in a submitted negotiation'], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Store the original proposed value for reference
            $original_value = $item->proposed_value;
            
            // If approved, always set an approved value (default to proposed value if not provided)
            $approved_value = null;
            if ($validated['status'] === 'approved') {
                $approved_value = $validated['approved_value'] ?? $original_value;
            }
            
            $item->update([
                'status' => $validated['status'],
                'approved_value' => $approved_value,
                'notes' => $validated['notes'] ?? $item->notes,
                'responded_at' => now()
            ]);
            
            // Check if all items have been responded to
            $allResponded = $negotiation->items()->whereNotIn('status', ['pending'])->count() == $negotiation->items()->count();
            
            if ($allResponded) {
                // Count approved and rejected items
                $approvedCount = $negotiation->items()->where('status', 'approved')->count();
                $rejectedCount = $negotiation->items()->where('status', 'rejected')->count();
                $totalItems = $negotiation->items()->count();
                
                // If all items were approved
                if ($approvedCount == $totalItems) {
                    // If negotiation is internally approved, mark as complete (external approval)
                    if ($negotiation->status === self::STATUS_APPROVED) {
                        $negotiation->status = self::STATUS_COMPLETE;
                        $negotiation->completed_at = now();
                    } else {
                        // Ready for internal approval - mudança de STATUS_SUBMITTED para STATUS_PENDING
                        $negotiation->status = self::STATUS_PENDING;
                        $negotiation->current_approval_level = self::APPROVAL_LEVEL;
                        
                        // Criar um registro no histórico de aprovação
                        $negotiation->approvalHistory()->create([
                            'level' => self::APPROVAL_LEVEL,
                            'status' => 'pending',
                            'user_id' => Auth::id(),
                            'notes' => 'Enviado automaticamente para aprovação após resposta a todos os itens'
                        ]);
                        
                        // Notificar sobre a necessidade de aprovação
                        $this->notificationService->notifyApprovalRequired($negotiation, self::APPROVAL_LEVEL);
                    }
                } 
                // If all items were rejected
                else if ($rejectedCount == $totalItems) {
                    $negotiation->status = self::STATUS_REJECTED;
                    $negotiation->rejected_at = now();
                } 
                // If some items were approved and others rejected (partial approval)
                else {
                    // If the negotiation was already approved internally, mark as partially complete
                    if ($negotiation->status === self::STATUS_APPROVED) {
                        $negotiation->status = self::STATUS_PARTIALLY_COMPLETE;
                        $negotiation->completed_at = now();
                    } else {
                        $negotiation->status = self::STATUS_PARTIALLY_APPROVED;
                    }
                }
                
                $negotiation->save();
                
                // If the negotiation was partially approved, send special notification
                if ($negotiation->status === self::STATUS_PARTIALLY_APPROVED) {
                    $this->notificationService->notifyPartialApproval($negotiation);
                }
                
                // If the negotiation was completed, send completion notification
                if ($negotiation->status === self::STATUS_COMPLETE) {
                    $this->notificationService->notifyNegotiationCompleted($negotiation);
                }
                
                // If the negotiation was partially completed, send partial completion notification
                if ($negotiation->status === self::STATUS_PARTIALLY_COMPLETE) {
                    $this->notificationService->notifyNegotiationPartiallyCompleted($negotiation);
                }
            }
            
            DB::commit();
            
            // Notify the negotiation creator about the response
            $this->notificationService->notifyItemResponse($item);

            return response()->json([
                'message' => 'Response recorded successfully',
                'data' => new NegotiationItemResource($item->fresh(['tuss'])),
                'negotiation' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to respond to item', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit a proposal for a negotiation item (entity).
     *
     * @param Request $request
     * @param NegotiationItem $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function counterItem(Request $request, NegotiationItem $item)
    {
        $validated = $request->validate([
            'counter_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
        
        $negotiation = $item->negotiation;
        
        if ($negotiation->status !== 'submitted') {
            return response()->json(['message' => 'Can only propose values for items in a submitted negotiation'], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Store original value for comparison
            $original_value = $item->proposed_value;
            
            $item->update([
                'status' => 'counter_offered',
                'approved_value' => $validated['counter_value'],
                'notes' => $validated['notes'] ?? $item->notes,
            ]);
            
            // Verificar se todos os itens foram respondidos
            $allResponded = $negotiation->items()->whereNotIn('status', ['pending'])->count() === $negotiation->items()->count();
            
            if ($allResponded) {
                // Verificar se todos os itens estão com contra-proposta
                $counterOfferedCount = $negotiation->items()->where('status', 'counter_offered')->count();
                $totalItems = $negotiation->items()->count();
                
                // Se todos os itens tiverem contra-proposta, atualizar para STATUS_PENDING
                if ($counterOfferedCount === $totalItems) {
                    $negotiation->status = self::STATUS_PENDING;
                    $negotiation->current_approval_level = self::APPROVAL_LEVEL;
                    $negotiation->save();
                    
                    // Criar um registro no histórico de aprovação
                    $negotiation->approvalHistory()->create([
                        'level' => self::APPROVAL_LEVEL,
                        'status' => 'pending',
                        'user_id' => Auth::id(),
                        'notes' => 'Enviado automaticamente para aprovação após contraproposta para todos os itens'
                    ]);
                    
                    // Notificar sobre a necessidade de aprovação
                    $this->notificationService->notifyApprovalRequired($negotiation, self::APPROVAL_LEVEL);
                }
            }
            
            // Notificar o criador sobre a proposta
            $this->notificationService->notifyCounterOffer($item);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Proposal submitted successfully',
                'data' => new NegotiationItemResource($item->fresh(['tuss'])),
                'negotiation' => $allResponded ? new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])) : null
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to submit proposal', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit batch counter offers for multiple items at once.
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchCounterOffer(Request $request, Negotiation $negotiation)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|integer|exists:negotiation_items,id',
            'items.*.counter_value' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
        ]);
        
        if ($negotiation->status !== 'submitted') {
            return response()->json(['message' => 'Can only counter items in a submitted negotiation'], 403);
        }
        
        try {
            DB::beginTransaction();
            
            $updatedItems = [];
            
            foreach ($validated['items'] as $itemData) {
                $item = NegotiationItem::find($itemData['item_id']);
                
                // Check if item belongs to this negotiation
                if (!$item || $item->negotiation_id !== $negotiation->id) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'One or more items do not belong to this negotiation',
                        'item_id' => $itemData['item_id']
                    ], 400);
                }
                
                // Check if item is pending
                if ($item->status !== 'pending') {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'One or more items are not in pending status',
                        'item_id' => $itemData['item_id'],
                        'status' => $item->status
                    ], 400);
                }
                
                $item->update([
                    'status' => 'counter_offered',
                    'approved_value' => $itemData['counter_value'],
                    'notes' => $itemData['notes'] ?? $item->notes,
                ]);
                
                // Check if item is a collection and extract the first item if needed
                if ($item instanceof \Illuminate\Database\Eloquent\Collection) {
                    if (!$item->isEmpty()) {
                        $this->notificationService->notifyCounterOffer($item->first());
                        $updatedItems[] = new NegotiationItemResource($item->first()->fresh(['tuss']));
                    }
                } else {
                    $this->notificationService->notifyCounterOffer($item);
                    $updatedItems[] = new NegotiationItemResource($item->fresh(['tuss']));
                }
            }
            
            // Verificar se todos os itens foram respondidos após as atualizações em lote
            $allResponded = $negotiation->items()->whereNotIn('status', ['pending'])->count() === $negotiation->items()->count();
            
            // Se todos os itens tiverem sido respondidos, verificar se devemos alterar o status
            if ($allResponded) {
                // Verificar se todos os itens têm contraproposta
                $counterOfferedCount = $negotiation->items()->where('status', 'counter_offered')->count();
                $totalItems = $negotiation->items()->count();
                
                // Se todos os itens tiverem contraproposta, mudar para aprovação interna
                if ($counterOfferedCount === $totalItems) {
                    $negotiation->status = self::STATUS_PENDING;
                    $negotiation->current_approval_level = self::APPROVAL_LEVEL;
                    $negotiation->save();
                    
                    // Criar um registro no histórico de aprovação
                    $negotiation->approvalHistory()->create([
                        'level' => self::APPROVAL_LEVEL,
                        'status' => 'pending',
                        'user_id' => Auth::id(),
                        'notes' => 'Enviado automaticamente para aprovação após contrapropostas em lote'
                    ]);
                    
                    // Notificar sobre a necessidade de aprovação
                    $this->notificationService->notifyApprovalRequired($negotiation, self::APPROVAL_LEVEL);
                }
            }
            
            DB::commit();
            
            return response()->json([
                'message' => 'Batch counter offers submitted successfully',
                'data' => [
                    'items' => $updatedItems,
                    'negotiation' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss']))
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to submit batch counter offers', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Process approval for a negotiation.
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function processApproval(Request $request, Negotiation $negotiation)
    {
        // Verificar se a negociação está em análise
        if (!in_array($negotiation->status, [self::STATUS_PENDING_APPROVAL, self::STATUS_PENDING_DIRECTOR_APPROVAL])) {
            return response()->json(['message' => 'Esta negociação não está pendente de aprovação'], 403);
        }
        
        // Verificar se o usuário tem permissão para aprovar
        if (!$this->userHasApprovalPermission()) {
            return response()->json(['message' => 'Você não tem permissão para aprovar negociações'], 403);
        }
        
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'required|string',
            'rejection_reason' => 'required_if:action,reject|nullable|string',
        ]);
        
        try {
            DB::beginTransaction();
            
            $previousStatus = $negotiation->status;
            
            if ($validated['action'] === 'approve') {
                if ($negotiation->status === self::STATUS_PENDING_APPROVAL) {
                    // Aprovação pela equipe de avaliação inicial (legal/comercial)
                    $negotiation->status = self::STATUS_PENDING_DIRECTOR_APPROVAL;
                    $negotiation->approval_notes = $validated['notes'];
                    $negotiation->approved_at = Carbon::now();
                    $negotiation->approved_by = Auth::id();
                    $negotiation->save();
                    
                    // Notificar sobre a aprovação parcial
                    $this->notificationService->notifyPartialApproval($negotiation);
                    
                    // Notificar que agora precisa da aprovação do diretor
                    $this->notificationService->notifyApprovalRequired($negotiation, 'director');
                } else if ($negotiation->status === self::STATUS_PENDING_DIRECTOR_APPROVAL) {
                    // Aprovação final pela diretoria
                    $negotiation->status = self::STATUS_APPROVED;
                    $negotiation->director_approval_notes = $validated['notes'];
                    $negotiation->director_approved_at = Carbon::now();
                    $negotiation->director_approved_by = Auth::id();
                    $negotiation->save();
                    
                    // Notificar sobre a aprovação final 
                    $this->notificationService->notifyNegotiationApproved($negotiation);
                }
            } else {
                // Rejeitado
                $negotiation->status = self::STATUS_REJECTED;
                $negotiation->rejection_reason = $validated['rejection_reason'];
                $negotiation->rejected_at = Carbon::now();
                $negotiation->rejected_by = Auth::id();
                $negotiation->save();
                
                // Notificar sobre a rejeição
                $this->notificationService->notifyApprovalRejected($negotiation);
            }
            
            DB::commit();
            
            return response()->json([
                'message' => $validated['action'] === 'approve' ? 'Negociação aprovada com sucesso' : 'Negociação rejeitada',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao processar aprovação: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Check if the current user has permission to approve negotiations.
     *
     * @return bool
     */
    private function userHasApprovalPermission(): bool
    {
        $user = Auth::user();
        
        // Check if user has the approval permission or appropriate role
        return $user->hasPermissionTo('approve_negotiations') || 
               $user->hasRole(['super_admin', 'director', 'financial']);
    }

    /**
     * Resend notifications for a negotiation in submitted status.
     *
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendNotifications(Negotiation $negotiation)
    {
        // Check if negotiation is in submitted state
        if ($negotiation->status !== self::STATUS_SUBMITTED) {
            return response()->json([
                'message' => 'Only submitted negotiations can have notifications resent',
                'success' => false
            ], 400);
        }

        try {
            // Resend the approval notification
            $this->notificationService->notifyApprovalRequired($negotiation, self::APPROVAL_LEVEL);
            
            return response()->json([
                'message' => 'Notifications resent successfully',
                'success' => true,
                'data' => [
                    'negotiation_id' => $negotiation->id,
                    'status' => $negotiation->status,
                    'approval_level' => self::APPROVAL_LEVEL
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to resend notifications: ' . $e->getMessage(),
                'success' => false
            ], 500);
        }
    }

    /**
     * Mark a negotiation as complete after external approval.
     *
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsComplete(Request $request, Negotiation $negotiation)
    {
        // Verify that the negotiation is in approved status
        if ($negotiation->status !== self::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Only approved negotiations can be marked as complete',
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Mark as complete
            $negotiation->status = self::STATUS_COMPLETE;
            $negotiation->completed_at = now();
            $negotiation->save();
            
            // Add to approval history
            $negotiation->approvalHistory()->create([
                'level' => 'external',
                'status' => 'approve',
                'user_id' => Auth::id(),
                'notes' => $request->notes ?? 'Approved by external entity'
            ]);
            
            // Notify about completion
            $this->notificationService->notifyNegotiationCompleted($negotiation);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negotiation marked as complete',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to mark negotiation as complete', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mark a negotiation as partially complete after external partial approval.
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsPartiallyComplete(Request $request, Negotiation $negotiation)
    {
        // Verify that the negotiation is in approved status
        if ($negotiation->status !== self::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Only approved negotiations can be marked as partially complete',
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Mark as partially complete
            $negotiation->status = self::STATUS_PARTIALLY_COMPLETE;
            $negotiation->completed_at = now();
            $negotiation->save();
            
            // Add to approval history
            $negotiation->approvalHistory()->create([
                'level' => 'external',
                'status' => 'partial_approve',
                'user_id' => Auth::id(),
                'notes' => $request->notes ?? 'Partially approved by external entity'
            ]);
            
            // Notify about partial completion
            $this->notificationService->notifyNegotiationPartiallyCompleted($negotiation);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negotiation marked as partially complete',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to mark negotiation as partially complete', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get announcements related to negotiations.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAnnouncements()
    {
        try {
            // In a production environment, these would likely come from a database
            $announcements = [
                [
                    'id' => 'negotiation-improvements-2023',
                    'title' => 'Melhorias no fluxo de negociação',
                    'description' => 'Aprimoramos o processo de negociação com aprovações internas antes do envio para a entidade. Agora as aprovações estão mais transparentes e organizadas.',
                    'type' => 'update',
                    'date' => '2023-11-05',
                    'dismissible' => true
                ]
            ];
            
            return response()->json([
                'data' => $announcements
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get negotiation announcements: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to get announcements', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Iniciar um novo ciclo de negociação a partir de uma negociação existente
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function startNewCycle(Request $request, Negotiation $negotiation)
    {
        // Verificar se a negociação está em um estado que permite novo ciclo
        if (!in_array($negotiation->status, [self::STATUS_REJECTED, self::STATUS_PARTIALLY_APPROVED])) {
            return response()->json([
                'message' => 'Apenas negociações rejeitadas ou parcialmente aprovadas podem iniciar um novo ciclo',
            ], 403);
        }
        
        // Verificar se atingiu o limite máximo de ciclos
        if ($negotiation->negotiation_cycle >= $negotiation->max_cycles_allowed) {
            return response()->json([
                'message' => 'Esta negociação atingiu o limite máximo de ciclos permitidos',
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Salvar dados do ciclo atual
            $currentCycleData = [
                'cycle_number' => $negotiation->negotiation_cycle,
                'status' => $negotiation->status,
                'items' => $negotiation->items()->get()->toArray(),
                'ended_at' => now()->toDateTimeString()
            ];
            
            // Atualizar histórico de ciclos
            $previousCycles = $negotiation->previous_cycles_data ?? [];
            $previousCycles[] = $currentCycleData;
            
            // Reset dos itens para novo ciclo
            foreach ($negotiation->items as $item) {
                // Manter o valor original proposto, mas reset do status
                $item->update([
                    'status' => 'pending',
                    'approved_value' => null,
                    'responded_at' => null,
                    'notes' => null
                ]);
            }
            
            // Atualizar a negociação para novo ciclo
            $negotiation->update([
                'status' => self::STATUS_SUBMITTED, // Volta para submitted para iniciar novo ciclo
                'negotiation_cycle' => $negotiation->negotiation_cycle + 1,
                'previous_cycles_data' => $previousCycles,
                'current_approval_level' => null,
                'approved_at' => null,
            ]);
            
            // Notificar entidade sobre o novo ciclo
            $this->notificationService->notifyNewNegotiationCycle($negotiation);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Novo ciclo de negociação iniciado com sucesso',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
                'cycle' => $negotiation->negotiation_cycle
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Falha ao iniciar novo ciclo de negociação', 
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reverter status da negociação para um estado anterior
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function rollbackStatus(Request $request, Negotiation $negotiation)
    {
        $validated = $request->validate([
            'target_status' => 'required|in:draft,submitted,pending',
            'reason' => 'required|string|max:500'
        ]);
        
        $currentStatus = $negotiation->status;
        $targetStatus = $validated['target_status'];
        
        // Validar se o rollback é permitido
        $allowedRollbacks = [
            self::STATUS_PENDING => [self::STATUS_SUBMITTED, self::STATUS_DRAFT],
            self::STATUS_APPROVED => [self::STATUS_PENDING, self::STATUS_SUBMITTED],
            self::STATUS_PARTIALLY_APPROVED => [self::STATUS_SUBMITTED],
            // Não permitir rollback de status finais (complete, rejected, cancelled)
        ];
        
        if (!isset($allowedRollbacks[$currentStatus]) || 
            !in_array($targetStatus, $allowedRollbacks[$currentStatus])) {
            return response()->json([
                'message' => 'Rollback não permitido para este status',
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Registrar o rollback no histórico
            $negotiation->statusHistory()->create([
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
                'user_id' => Auth::id(),
                'reason' => $validated['reason'],
                'created_at' => now()
            ]);
            
            // Atualizar o status
            $negotiation->status = $targetStatus;
            
            // Resetar campos relacionados ao status, se necessário
            if ($targetStatus === self::STATUS_SUBMITTED) {
                $negotiation->current_approval_level = null;
            }
            
            $negotiation->save();
            
            // Notificar as partes sobre o rollback
            $this->notificationService->notifyStatusRollback($negotiation, $currentStatus, $targetStatus, $validated['reason']);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Status da negociação revertido com sucesso',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Falha ao reverter status da negociação', 
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bifurcar uma negociação em múltiplas baseado nos itens selecionados
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function forkNegotiation(Request $request, Negotiation $negotiation)
    {
        $validated = $request->validate([
            'item_groups' => 'required|array|min:2', // Pelo menos 2 grupos para bifurcar
            'item_groups.*.items' => 'required|array|min:1',
            'item_groups.*.items.*' => 'required|integer|exists:negotiation_items,id',
            'item_groups.*.title' => 'required|string|max:255',
            'item_groups.*.notes' => 'nullable|string'
        ]);
        
        // Verificar se o status permite bifurcação
        if (!in_array($negotiation->status, [self::STATUS_SUBMITTED, self::STATUS_PARTIALLY_APPROVED])) {
            return response()->json([
                'message' => 'Apenas negociações em andamento podem ser bifurcadas',
            ], 403);
        }
        
        try {
            DB::beginTransaction();
            
            // Armazenar novas negociações criadas
            $forkedNegotiations = [];
            
            // Para cada grupo, criar uma nova negociação
            foreach ($validated['item_groups'] as $group) {
                $itemIds = $group['items'];
                
                // Verificar se os itens pertencem à negociação original
                $items = $negotiation->items()->whereIn('id', $itemIds)->get();
                
                if ($items->count() !== count($itemIds)) {
                    DB::rollBack();
                    return response()->json([
                        'message' => 'Um ou mais itens não pertencem a esta negociação',
                    ], 400);
                }
                
                // Criar nova negociação
                $forkedNegotiation = new Negotiation([
                    'negotiable_type' => $negotiation->negotiable_type,
                    'negotiable_id' => $negotiation->negotiable_id,
                    'creator_id' => Auth::id(),
                    'title' => $group['title'],
                    'description' => "Bifurcado da negociação #{$negotiation->id}: {$negotiation->title}",
                    'status' => self::STATUS_SUBMITTED,
                    'start_date' => $negotiation->start_date,
                    'end_date' => $negotiation->end_date,
                    'notes' => $group['notes'] ?? $negotiation->notes,
                    'parent_negotiation_id' => $negotiation->id,
                    'is_fork' => true,
                    'negotiation_cycle' => 1, // Começa um novo ciclo
                ]);
                
                $forkedNegotiation->save();
                
                // Clonar os itens para a nova negociação
                foreach ($items as $item) {
                    $newItem = $item->replicate();
                    $newItem->negotiation_id = $forkedNegotiation->id;
                    $newItem->save();
                }
                
                $forkedNegotiations[] = $forkedNegotiation;
            }
            
            // Atualizar a negociação original
            $negotiation->status = self::STATUS_FORKED;
            $negotiation->forked_at = now();
            $negotiation->fork_count = count($validated['item_groups']);
            $negotiation->save();
            
            // Notificar sobre a bifurcação
            $this->notificationService->notifyNegotiationFork($negotiation, $forkedNegotiations);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negociação bifurcada com sucesso',
                'original_negotiation' => new NegotiationResource($negotiation->fresh()),
                'forked_negotiations' => NegotiationResource::collection(collect($forkedNegotiations)),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Falha ao bifurcar negociação', 
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 