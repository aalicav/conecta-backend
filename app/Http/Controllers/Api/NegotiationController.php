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
    // Define approval levels as constants
    const APPROVAL_LEVEL_COMMERCIAL = 'commercial';
    const APPROVAL_LEVEL_FINANCIAL = 'financial';
    const APPROVAL_LEVEL_MANAGEMENT = 'management';
    const APPROVAL_LEVEL_LEGAL = 'legal';
    const APPROVAL_LEVEL_DIRECTION = 'direction';

    // Define approval statuses
    const STATUS_DRAFT = 'draft';
    const STATUS_PENDING_COMMERCIAL = 'pending_commercial';
    const STATUS_PENDING_FINANCIAL = 'pending_financial';
    const STATUS_PENDING_MANAGEMENT = 'pending_management';
    const STATUS_PENDING_LEGAL = 'pending_legal';
    const STATUS_PENDING_DIRECTION = 'pending_direction';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

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
     * Submit the negotiation to the entity for review.
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
            $negotiation->status = 'submitted';
            $negotiation->save();
            
            // Notificar representantes da entidade
            $this->notificationService->notifyNegotiationSubmitted($negotiation);
            
            return response()->json([
                'message' => 'Negotiation submitted successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to submit negotiation', 'error' => $e->getMessage()], 500);
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
        if (in_array($negotiation->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Cannot cancel a completed or already cancelled negotiation'], 403);
        }
        
        try {
            $negotiation->status = 'cancelled';
            $negotiation->save();
            
            // Notificar todas as partes envolvidas
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
        if ($negotiation->status !== 'approved') {
            return response()->json(['message' => 'Can only generate contracts for approved negotiations'], 403);
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
        
        if ($negotiation->status !== 'submitted') {
            return response()->json(['message' => 'Can only respond to items in a submitted negotiation'], 403);
        }
        
        try {
            DB::beginTransaction();
            
            $item->update([
                'status' => $validated['status'],
                'approved_value' => $validated['status'] === 'approved' ? $validated['approved_value'] : null,
                'notes' => $validated['notes'] ?? $item->notes,
            ]);
            
            // Check if all items have been responded to
            $allResponded = $negotiation->items()->whereNotIn('status', ['pending'])->count() == $negotiation->items()->count();
            $allApproved = $negotiation->items()->where('status', '!=', 'approved')->count() == 0;
            
            if ($allResponded) {
                $previousStatus = $negotiation->status;
                $negotiation->status = $allApproved ? 'approved' : 'partially_approved';
                $negotiation->save();
                
                // Se a negociação foi parcialmente aprovada, enviar notificação especial
                if ($negotiation->status === 'partially_approved') {
                    $this->notificationService->notifyPartialApproval($negotiation);
                }
            }
            
            DB::commit();
            
            // Notificar o criador da negociação sobre a resposta
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
     * Submit a counter offer for a negotiation item (entity).
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
            return response()->json(['message' => 'Can only counter items in a submitted negotiation'], 403);
        }
        
        try {
            $item->update([
                'status' => 'counter_offered',
                'approved_value' => $validated['counter_value'],
                'notes' => $validated['notes'] ?? $item->notes,
            ]);
            
            // Notificar o criador sobre a contra-oferta
            $this->notificationService->notifyCounterOffer($item);
            
            return response()->json([
                'message' => 'Counter offer submitted successfully',
                'data' => new NegotiationItemResource($item->fresh(['tuss'])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to submit counter offer', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Submit the negotiation for internal approval.
     *
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitForApproval(Negotiation $negotiation)
    {
        if ($negotiation->status !== self::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft negotiations can be submitted for approval'], 403);
        }
        
        if ($negotiation->items->isEmpty()) {
            return response()->json(['message' => 'Cannot submit a negotiation without items'], 400);
        }
        
        try {
            DB::beginTransaction();
            
            // Start with commercial approval
            $negotiation->status = self::STATUS_PENDING_COMMERCIAL;
            $negotiation->current_approval_level = self::APPROVAL_LEVEL_COMMERCIAL;
            $negotiation->save();
            
            // Create approval history record
            $negotiation->approvalHistory()->create([
                'level' => self::APPROVAL_LEVEL_COMMERCIAL,
                'status' => 'pending',
                'user_id' => Auth::id(),
                'notes' => 'Initial submission for commercial approval'
            ]);
            
            // Notify commercial team
            $this->notificationService->notifyApprovalRequired($negotiation, self::APPROVAL_LEVEL_COMMERCIAL);
            
            DB::commit();
            
            return response()->json([
                'message' => 'Negotiation submitted for approval',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to submit negotiation for approval', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Approve or reject a negotiation at the current approval level.
     *
     * @param Request $request
     * @param Negotiation $negotiation
     * @return \Illuminate\Http\JsonResponse
     */
    public function processApproval(Request $request, Negotiation $negotiation)
    {
        $validated = $request->validate([
            'action' => 'required|in:approve,reject',
            'notes' => 'nullable|string',
        ]);

        // Check if user has permission for current approval level
        if (!$this->userHasApprovalPermission($negotiation->current_approval_level)) {
            return response()->json(['message' => 'You do not have permission to approve at this level'], 403);
        }

        try {
            DB::beginTransaction();

            // Record the current approval decision
            $negotiation->approvalHistory()->create([
                'level' => $negotiation->current_approval_level,
                'status' => $validated['action'],
                'user_id' => Auth::id(),
                'notes' => $validated['notes'] ?? null
            ]);

            if ($validated['action'] === 'reject') {
                $negotiation->status = self::STATUS_REJECTED;
                $negotiation->save();
                
                $this->notificationService->notifyApprovalRejected($negotiation);
                
                DB::commit();
                return response()->json([
                    'message' => 'Negotiation rejected',
                    'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
                ]);
            }

            // If approved, move to next approval level
            $nextLevel = $this->getNextApprovalLevel($negotiation->current_approval_level);
            
            if ($nextLevel) {
                $negotiation->status = 'pending_' . $nextLevel;
                $negotiation->current_approval_level = $nextLevel;
                $negotiation->save();
                
                $this->notificationService->notifyApprovalRequired($negotiation, $nextLevel);
            } else {
                // If no next level, negotiation is fully approved
                $negotiation->status = self::STATUS_APPROVED;
                $negotiation->approved_at = now();
                $negotiation->save();
                
                $this->notificationService->notifyNegotiationApproved($negotiation);
            }

            DB::commit();
            
            return response()->json([
                'message' => 'Approval processed successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to process approval', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Check if the current user has permission for the given approval level.
     *
     * @param string $level
     * @return bool
     */
    private function userHasApprovalPermission(string $level): bool
    {
        $user = Auth::user();
        
        return match($level) {
            self::APPROVAL_LEVEL_COMMERCIAL => $user->hasPermissionTo('approve_negotiation_commercial'),
            self::APPROVAL_LEVEL_FINANCIAL => $user->hasPermissionTo('approve_negotiation_financial'),
            self::APPROVAL_LEVEL_MANAGEMENT => $user->hasPermissionTo('approve_negotiation_management'),
            self::APPROVAL_LEVEL_LEGAL => $user->hasPermissionTo('approve_negotiation_legal'),
            self::APPROVAL_LEVEL_DIRECTION => $user->hasPermissionTo('approve_negotiation_direction'),
            default => false
        };
    }

    /**
     * Get the next approval level in the workflow.
     *
     * @param string $currentLevel
     * @return string|null
     */
    private function getNextApprovalLevel(string $currentLevel): ?string
    {
        return match($currentLevel) {
            self::APPROVAL_LEVEL_COMMERCIAL => self::APPROVAL_LEVEL_FINANCIAL,
            self::APPROVAL_LEVEL_FINANCIAL => self::APPROVAL_LEVEL_MANAGEMENT,
            self::APPROVAL_LEVEL_MANAGEMENT => self::APPROVAL_LEVEL_LEGAL,
            self::APPROVAL_LEVEL_LEGAL => self::APPROVAL_LEVEL_DIRECTION,
            self::APPROVAL_LEVEL_DIRECTION => null,
            default => null
        };
    }
} 