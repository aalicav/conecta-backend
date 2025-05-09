<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ExtemporaneousNegotiation;
use App\Models\Contract;
use App\Models\Tuss;
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
            $query = ExtemporaneousNegotiation::with(['contract', 'tuss', 'requestedBy', 'approvedBy']);
            
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
            
            // Filter by search term
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->whereHas('contract', function($q2) use ($search) {
                        $q2->where('contract_number', 'like', "%{$search}%");
                    })
                    ->orWhereHas('tuss', function($q2) use ($search) {
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
                    $query->where('requested_by', $user->id);
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
                'contract_id' => 'required|exists:contracts,id',
                'tuss_id' => 'required|exists:tuss_procedures,id',
                'requested_value' => 'required|numeric|min:0',
                'justification' => 'required|string|min:10',
                'urgency_level' => 'nullable|in:low,medium,high',
            ]);
            
            // Check if contract exists and is active
            $contract = Contract::findOrFail($validated['contract_id']);
            
            if ($contract->status !== 'active' && $contract->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Extemporaneous negotiations can only be created for active contracts'
                ], 422);
            }
            
            DB::beginTransaction();
            
            $negotiation = ExtemporaneousNegotiation::create([
                'contract_id' => $validated['contract_id'],
                'tuss_id' => $validated['tuss_id'],
                'requested_value' => $validated['requested_value'],
                'justification' => $validated['justification'],
                'urgency_level' => $validated['urgency_level'] ?? 'medium',
                'status' => 'pending',
                'requested_by' => Auth::id(),
            ]);
            
            // Send notification to the commercial team (specifically Adla as mentioned in requirements)
            $this->notificationService->sendToRole('commercial', [
                'title' => 'Nova negociação extemporânea',
                'body' => "Foi solicitada uma negociação extemporânea para o contrato #{$contract->contract_number}.",
                'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                'icon' => 'alert-circle',
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation created successfully',
                'data' => $negotiation->load(['contract', 'tuss', 'requestedBy'])
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
                'contract', 'tuss', 'requestedBy', 'approvedBy'
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
            // Only commercial team or director can approve
            $user = Auth::user();
            if (!$user->hasRole(['commercial', 'director', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to approve extemporaneous negotiations'
                ], 403);
            }
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            if ($negotiation->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending negotiations can be approved'
                ], 422);
            }
            
            $validated = $request->validate([
                'approved_value' => 'required|numeric|min:0',
                'approval_notes' => 'nullable|string',
                'is_requiring_addendum' => 'boolean',
            ]);
            
            DB::beginTransaction();
            
            $negotiation->update([
                'approved_value' => $validated['approved_value'],
                'approval_notes' => $validated['approval_notes'] ?? null,
                'status' => 'approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'is_requiring_addendum' => $validated['is_requiring_addendum'] ?? true,
            ]);
            
            // Send notification to the requester
            $this->notificationService->sendToUser($negotiation->requested_by, [
                'title' => 'Negociação extemporânea aprovada',
                'body' => "Sua solicitação de negociação extemporânea foi aprovada.",
                'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                'icon' => 'check-circle',
                'channels' => ['system', 'whatsapp', 'email'],
                'priority' => 'high'
            ]);
            
            // Obter detalhes do contrato e procedimento para mensagens mais informativas
            $contractNumber = $negotiation->contract->contract_number;
            $tussCode = $negotiation->tuss->code;
            $tussDescription = $negotiation->tuss->description;
            $approvedValue = number_format($validated['approved_value'], 2, ',', '.');
            
            // Notificar Adla na equipe comercial (responsável pelos aditivos)
            $commercialAdla = \App\Models\User::where('name', 'like', '%Adla%')
                ->whereHas('roles', function($q) {
                    $q->where('name', 'commercial');
                })
                ->first();
                
            if ($commercialAdla) {
                $this->notificationService->sendToUser($commercialAdla->id, [
                    'title' => 'Ação Necessária: Aditivo Contratual',
                    'body' => "Uma negociação extemporânea para o procedimento {$tussCode} - {$tussDescription} foi aprovada no contrato #{$contractNumber} no valor de R$ {$approvedValue}. É necessário formalizar via aditivo contratual.",
                    'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                    'icon' => 'file-plus',
                    'priority' => 'high',
                    'channels' => ['system', 'whatsapp', 'email'],
                ]);
            } else {
                // Se Adla não for encontrada, envia para toda a equipe comercial
                $this->notificationService->sendToRole('commercial', [
                    'title' => 'Ação Necessária: Aditivo Contratual',
                    'body' => "Uma negociação extemporânea para o procedimento {$tussCode} - {$tussDescription} foi aprovada no contrato #{$contractNumber} no valor de R$ {$approvedValue}. É necessário formalizar via aditivo contratual.",
                    'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                    'icon' => 'file-plus',
                    'priority' => 'high',
                    'channels' => ['system', 'whatsapp', 'email'],
                ]);
            }
            
            // Se requer aditivo, notificar equipe jurídica
            if ($negotiation->is_requiring_addendum) {
                $this->notificationService->sendToRole('legal', [
                    'title' => 'Novo aditivo contratual necessário',
                    'body' => "Foi aprovada uma negociação extemporânea para o contrato #{$contractNumber} que requer aditivo contratual. Procedimento: {$tussCode} - {$tussDescription}. Valor aprovado: R$ {$approvedValue}.",
                    'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                    'icon' => 'file-plus',
                    'priority' => 'high',
                    'channels' => ['system', 'email'],
                ]);
            }
            
            // Criar registro de verificação para dupla checagem dos valores
            $verification = new \App\Models\ValueVerification([
                'entity_type' => 'extemporaneous_negotiation',
                'entity_id' => $negotiation->id,
                'value_type' => 'approved_value',
                'original_value' => $validated['approved_value'],
                'verified_value' => null,
                'status' => 'pending',
                'requester_id' => Auth::id(),
                'verifier_id' => null,
                'notes' => "Valor aprovado para procedimento {$tussCode} no contrato #{$contractNumber}",
            ]);
            $verification->save();
            
            // Notificar diretores (Dr. Ítalo) para confirmar o valor (dupla checagem)
            $this->notificationService->sendToRole('director', [
                'title' => 'Confirmação de valor necessária',
                'body' => "Um valor de R$ {$approvedValue} foi aprovado para o procedimento {$tussCode} - {$tussDescription} no contrato #{$contractNumber}. Por favor, confirme este valor.",
                'action_link' => "/value-verifications/{$verification->id}",
                'icon' => 'alert-triangle',
                'priority' => 'high',
                'channels' => ['system', 'whatsapp', 'email'],
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation approved successfully',
                'data' => $negotiation->fresh(['contract', 'tuss', 'requestedBy', 'approvedBy'])
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
            // Only commercial team or director can reject
            $user = Auth::user();
            if (!$user->hasRole(['commercial', 'director', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to reject extemporaneous negotiations'
                ], 403);
            }
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            if ($negotiation->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only pending negotiations can be rejected'
                ], 422);
            }
            
            $validated = $request->validate([
                'rejection_reason' => 'required|string|min:5',
            ]);
            
            DB::beginTransaction();
            
            $negotiation->update([
                'status' => 'rejected',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);
            
            // Send notification to the requester
            $this->notificationService->sendToUser($negotiation->requested_by, [
                'title' => 'Negociação extemporânea rejeitada',
                'body' => "Sua solicitação de negociação extemporânea foi rejeitada.",
                'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                'icon' => 'x-circle',
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Extemporaneous negotiation rejected successfully',
                'data' => $negotiation->fresh(['contract', 'tuss', 'requestedBy', 'approvedBy'])
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
     * Mark an extemporaneous negotiation as included in an addendum.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function markAsAddendumIncluded(Request $request, $id)
    {
        try {
            // Only legal or commercial team can mark as included in addendum
            $user = Auth::user();
            if (!$user->hasRole(['legal', 'commercial', 'admin', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to update addendum status'
                ], 403);
            }
            
            $negotiation = ExtemporaneousNegotiation::findOrFail($id);
            
            if ($negotiation->status !== 'approved') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only approved negotiations can be marked as included in addendum'
                ], 422);
            }
            
            if (!$negotiation->is_requiring_addendum) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This negotiation does not require an addendum'
                ], 422);
            }
            
            $validated = $request->validate([
                'addendum_number' => 'required|string',
                'addendum_date' => 'required|date',
                'notes' => 'nullable|string',
            ]);
            
            DB::beginTransaction();
            
            $negotiation->update([
                'addendum_included' => true,
                'addendum_number' => $validated['addendum_number'],
                'addendum_date' => $validated['addendum_date'],
                'addendum_notes' => $validated['notes'] ?? null,
                'addendum_updated_by' => Auth::id(),
            ]);
            
            // Send notification to the commercial team
            $this->notificationService->sendToRole('commercial', [
                'title' => 'Aditivo contratual registrado',
                'body' => "A negociação extemporânea foi incluída no aditivo contratual {$validated['addendum_number']}.",
                'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                'icon' => 'check-circle',
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Negotiation marked as included in addendum successfully',
                'data' => $negotiation->fresh(['contract', 'tuss', 'requestedBy', 'approvedBy'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to mark negotiation as included in addendum: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'negotiation_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to mark negotiation as included in addendum',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * List extemporaneous negotiations for a specific contract.
     *
     * @param int $contractId
     * @return \Illuminate\Http\JsonResponse
     */
    public function listByContract($contractId)
    {
        try {
            $contract = Contract::findOrFail($contractId);
            
            $negotiations = ExtemporaneousNegotiation::with(['tuss', 'requestedBy', 'approvedBy'])
                ->where('contract_id', $contractId)
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'status' => 'success',
                'data' => $negotiations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list negotiations by contract: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $contractId
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list negotiations by contract',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
} 