<?php

namespace App\Http\Controllers;

use App\Http\Resources\NegotiationResource;
use App\Models\HealthPlan;
use App\Models\Professional;
use App\Models\Clinic;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use App\Models\Tuss;
use App\Models\ContractTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class NegotiationController extends Controller
{
    /**
     * Display a listing of negotiations.
     */
    public function index(Request $request)
    {
        $negotiations = Negotiation::with(['negotiable', 'creator', 'items.tuss'])
            ->when($request->has('status'), function ($query) use ($request) {
                return $query->where('status', $request->status);
            })
            ->when($request->has('entity_type') && $request->has('entity_id'), function ($query) use ($request) {
                return $query->where('negotiable_type', $request->entity_type)
                             ->where('negotiable_id', $request->entity_id);
            })
            ->when($request->has('health_plan_id'), function ($query) use ($request) {
                // Backward compatibility
                return $query->where('negotiable_type', HealthPlan::class)
                             ->where('negotiable_id', $request->health_plan_id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        return NegotiationResource::collection($negotiations);
    }

    /**
     * Store a newly created negotiation in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => 'required|in:App\\Models\\HealthPlan,App\\Models\\Professional,App\\Models\\Clinic',
            'entity_id' => 'required|integer',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'contract_template_id' => 'required|exists:contract_templates,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.tuss_id' => 'required|exists:tuss_procedures,id',
            'items.*.proposed_value' => 'required|numeric|min:0',
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

            // Create the negotiation
            $negotiation = Negotiation::create([
                'negotiable_type' => $validated['entity_type'],
                'negotiable_id' => $validated['entity_id'],
                'contract_template_id' => $validated['contract_template_id'],
                'creator_id' => Auth::id(),
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'notes' => $validated['notes'] ?? null,
                'status' => Negotiation::STATUS_DRAFT,
            ]);

            // Create the negotiation items
            foreach ($validated['items'] as $item) {
                $negotiation->items()->create([
                    'tuss_id' => $item['tuss_id'],
                    'proposed_value' => $item['proposed_value'],
                    'status' => NegotiationItem::STATUS_PENDING,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Negotiation created successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error creating negotiation: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified negotiation.
     */
    public function show(Negotiation $negotiation)
    {
        $negotiation->load(['negotiable', 'creator', 'items.tuss', 'contractTemplate']);
        return new NegotiationResource($negotiation);
    }

    /**
     * Respond to a negotiation item.
     */
    public function respondToItem(Request $request, Negotiation $negotiation, NegotiationItem $item): JsonResponse
    {
        // Check if the negotiation is still pending
        if ($negotiation->status !== Negotiation::STATUS_PENDING) {
            return response()->json(['message' => 'This negotiation is no longer active'], 422);
        }

        // Validate the request
        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject', 'counter'])],
            'counter_value' => 'required_if:action,counter|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            switch ($validated['action']) {
                case 'accept':
                    $item->update([
                        'status' => NegotiationItem::STATUS_APPROVED,
                        'approved_value' => $item->proposed_value,
                        'notes' => $validated['notes'] ?? $item->notes,
                        'responded_at' => now(),
                    ]);
                    break;
                case 'reject':
                    $item->update([
                        'status' => NegotiationItem::STATUS_REJECTED,
                        'notes' => $validated['notes'] ?? $item->notes,
                        'responded_at' => now(),
                    ]);
                    break;
                case 'counter':
                    $item->update([
                        'status' => NegotiationItem::STATUS_COUNTER_OFFERED,
                        'approved_value' => $validated['counter_value'],
                        'notes' => $validated['notes'] ?? $item->notes,
                        'responded_at' => now(),
                    ]);
                    break;
            }

            // Check if all items have been responded to
            if ($negotiation->items()->where('status', NegotiationItem::STATUS_PENDING)->count() === 0) {
                // If all items are rejected, reject the negotiation
                if ($negotiation->items()->where('status', NegotiationItem::STATUS_REJECTED)->count() === $negotiation->items()->count()) {
                    $negotiation->update([
                        'status' => Negotiation::STATUS_REJECTED,
                        'rejected_at' => now(),
                    ]);
                } 
                // If all items are approved or counter-offered, complete the negotiation
                elseif ($negotiation->items()->whereIn('status', [
                    NegotiationItem::STATUS_APPROVED, 
                    NegotiationItem::STATUS_COUNTER_OFFERED
                ])->count() === $negotiation->items()->count()) {
                    $negotiation->update([
                        'status' => Negotiation::STATUS_COMPLETE,
                        'completed_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Response recorded successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error responding to item: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Respond to a counter offer for a negotiation item.
     */
    public function respondToCounterOffer(Request $request, Negotiation $negotiation, NegotiationItem $item): JsonResponse
    {
        // Check if the negotiation is in the correct state
        if ($negotiation->status !== Negotiation::STATUS_COMPLETE) {
            return response()->json(['message' => 'This negotiation is not ready for counter offer responses'], 422);
        }

        // Check if the item is counter-offered
        if ($item->status !== NegotiationItem::STATUS_COUNTER_OFFERED) {
            return response()->json(['message' => 'This item does not have a counter offer'], 422);
        }

        // Validate the request
        $validated = $request->validate([
            'action' => ['required', Rule::in(['accept', 'reject'])],
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            if ($validated['action'] === 'accept') {
                $item->update([
                    'status' => NegotiationItem::STATUS_APPROVED,
                    'notes' => $validated['notes'] ?? $item->notes,
                    'responded_at' => now(),
                ]);
            } else {
                $item->update([
                    'status' => NegotiationItem::STATUS_REJECTED,
                    'notes' => $validated['notes'] ?? $item->notes,
                    'responded_at' => now(),
                ]);
            }

            // Check if all counter offers have been responded to
            if ($negotiation->items()->where('status', NegotiationItem::STATUS_COUNTER_OFFERED)->count() === 0) {
                // If all items are either accepted or rejected, generate contract or reject
                if ($negotiation->items()->where('status', NegotiationItem::STATUS_APPROVED)->count() > 0) {
                    // Generate contract with accepted items
                    $contract = $this->generateContractForNegotiation($negotiation);
                    
                    if ($contract) {
                        return response()->json([
                            'message' => 'Contract generated successfully',
                            'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
                            'contract_id' => $contract->id,
                        ]);
                    }
                } else {
                    // All items were rejected
                    $negotiation->update([
                        'status' => Negotiation::STATUS_REJECTED,
                        'rejected_at' => now(),
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Response to counter offer recorded successfully',
                'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error responding to counter offer: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a pending negotiation.
     */
    public function cancel(Negotiation $negotiation): JsonResponse
    {
        if ($negotiation->status !== Negotiation::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending negotiations can be cancelled'], 422);
        }

        $negotiation->update(['status' => Negotiation::STATUS_CANCELLED]);

        return response()->json([
            'message' => 'Negotiation cancelled successfully',
            'data' => new NegotiationResource($negotiation->fresh(['negotiable', 'creator', 'items.tuss'])),
        ]);
    }

    /**
     * Generate a contract for an approved negotiation.
     */
    protected function generateContractForNegotiation(Negotiation $negotiation)
    {
        // This is a placeholder - actual implementation will depend on your contract generation logic
        $negotiation->update([
            'status' => Negotiation::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        // Here you'd create a contract record and link it to the negotiation
        // $contract = Contract::create([...]);
        // $negotiation->update(['contract_id' => $contract->id]);
        
        // Return the created contract or null if not implemented yet
        return null;
    }
} 