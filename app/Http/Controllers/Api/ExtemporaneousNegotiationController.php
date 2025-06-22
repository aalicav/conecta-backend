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
                'negotiable_type' => 'required|in:App\\Models\\Clinic,App\\Models\\HealthPlan',
                'negotiable_id' => 'required|integer',
                'tuss_procedure_id' => 'required|exists:tuss_procedures,id',
                'negotiated_price' => 'required|numeric|min:0',
                'justification' => 'required|string|min:10',
                'solicitation_id' => 'nullable|exists:solicitations,id'
            ]);
            
            // Check if entity exists
            $entityClass = $validated['negotiable_type'];
            $entity = $entityClass::findOrFail($validated['negotiable_id']);
            
            DB::beginTransaction();
            
            $negotiation = ExtemporaneousNegotiation::create([
                'negotiable_type' => $validated['negotiable_type'],
                'negotiable_id' => $validated['negotiable_id'],
                'tuss_procedure_id' => $validated['tuss_procedure_id'],
                'negotiated_price' => $validated['negotiated_price'],
                'justification' => $validated['justification'],
                'status' => ExtemporaneousNegotiation::STATUS_PENDING_APPROVAL,
                'created_by' => Auth::id(),
                'solicitation_id' => $validated['solicitation_id'] ?? null
            ]);
            
            // Send notification to network managers
            $this->notificationService->sendToRole('network_manager', [
                'title' => 'Nova negociação extemporânea',
                'body' => "Foi solicitada uma negociação extemporânea para {$entity->name}.",
                'action_link' => "/negotiations/extemporaneous/{$negotiation->id}",
                'icon' => 'alert-circle',
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation created successfully',
                'data' => $negotiation->load(['negotiable', 'tussProcedure', 'createdBy'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create extemporaneous negotiation: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create extemporaneous negotiation',
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
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            $validated = $request->validate([
                'approval_notes' => 'nullable|string'
            ]);
            
            DB::beginTransaction();
            
            if (!$negotiation->approve($user->id, $validated['approval_notes'] ?? null)) {
                throw new \Exception('Failed to approve negotiation');
            }
            
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