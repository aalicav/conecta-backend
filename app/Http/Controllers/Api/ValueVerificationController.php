<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\ValueVerification;
use App\Services\NotificationService;

class ValueVerificationController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:api');
        $this->notificationService = $notificationService;
    }

    /**
     * Display a listing of value verifications.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = ValueVerification::with(['requester', 'verifier']);
            
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
                $query->where('entity_type', $request->input('entity_type'));
            }
            
            // Filter by search term
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function($q) use ($search) {
                    $q->where('notes', 'like', "%{$search}%")
                      ->orWhereHas('requester', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%");
                      });
                });
            }
            
            // Role-based access
            $user = Auth::user();
            
            if ($user->hasRole(['director', 'super_admin'])) {
                // Directors and super admins can see all verifications
            } else if ($user->hasRole(['commercial', 'financial'])) {
                // Commercial and financial teams can see their own requests and ones they need to verify
                $query->where(function($q) use ($user) {
                    $q->where('requester_id', $user->id)
                      ->orWhere('verifier_id', $user->id)
                      ->orWhereNull('verifier_id'); // They can also see pending ones
                });
            } else {
                // Others can only see their own requests
                $query->where('requester_id', $user->id);
            }
            
            $perPage = $request->input('per_page', 15);
            $verifications = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $verifications
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list value verifications: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list value verifications',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created value verification.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'entity_type' => 'required|string|in:contract,extemporaneous_negotiation,negotiation',
                'entity_id' => 'required|integer',
                'original_value' => 'required|numeric|min:0',
                'notes' => 'required|string',
            ]);
            
            DB::beginTransaction();
            
            $verification = new ValueVerification();
            $verification->entity_type = $validated['entity_type'];
            $verification->entity_id = $validated['entity_id'];
            $verification->original_value = $validated['original_value'];
            $verification->notes = $validated['notes'];
            $verification->requester_id = Auth::id();
            $verification->status = 'pending';
            $verification->save();
            
            // Notify directors about the new value verification
            $this->notificationService->sendToRole('director', [
                'title' => 'Nova Verificação de Valor',
                'body' => "Uma nova verificação de valor foi solicitada para {$verification->entity_type} #{$verification->entity_id}.",
                'action_link' => "/value-verifications/{$verification->id}",
                'icon' => 'dollar-sign',
                'channels' => ['system']
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Verificação de valor criada com sucesso',
                'data' => $verification->load('requester')
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create value verification: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create value verification',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified value verification.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $verification = ValueVerification::with(['requester', 'verifier'])
                ->findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $verification
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get value verification: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'verification_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get value verification',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Verify a value (approve it).
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request, $id)
    {
        try {
            // Only directors, super_admins can verify values
            $user = Auth::user();
            if (!$user->hasRole(['director', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para verificar valores'
                ], 403);
            }
            
            $verification = ValueVerification::findOrFail($id);
            
            if ($verification->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Apenas verificações pendentes podem ser aprovadas'
                ], 422);
            }
            
            if ($verification->requester_id === $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não pode verificar um valor que você mesmo solicitou'
                ], 422);
            }
            
            $validated = $request->validate([
                'verified_value' => 'nullable|numeric',
                'notes' => 'nullable|string',
            ]);
            
            DB::beginTransaction();
            
            // Use the model method to verify
            $verification->verify(
                $user->id, 
                $request->filled('verified_value') ? $validated['verified_value'] : null
            );
            
            // If there's an additional note, append it
            if ($request->filled('notes')) {
                $verification->notes = $verification->notes 
                    ? $verification->notes . "\n\nVerificação: " . $validated['notes']
                    : "Verificação: " . $validated['notes'];
                $verification->save();
            }
            
            // Send notification to the requester
            $requesterName = $verification->requester->name;
            $verifierName = $user->name;
            
            $this->notificationService->sendToUser($verification->requester_id, [
                'title' => 'Valor verificado e aprovado',
                'body' => "Seu valor para {$verification->entity_type} foi verificado e aprovado por {$verifierName}.",
                'action_link' => "/value-verifications/{$verification->id}",
                'icon' => 'check-circle',
                'channels' => ['system', 'email']
            ]);
            
            // Determine what to do based on entity type
            if ($verification->entity_type === 'extemporaneous_negotiation') {
                // Get the negotiation
                $negotiation = \App\Models\ExtemporaneousNegotiation::find($verification->entity_id);
                if ($negotiation) {
                    // Send notification to commercial team about value verification
                    $this->notificationService->sendToRole('commercial', [
                        'title' => 'Valor aprovado em verificação dupla',
                        'body' => "O valor para a negociação extemporânea #{$negotiation->id} foi verificado e aprovado.",
                        'action_link' => "/extemporaneous-negotiations/{$negotiation->id}",
                        'icon' => 'check-circle',
                        'channels' => ['system']
                    ]);
                }
            } elseif ($verification->entity_type === 'contract') {
                // Get the contract
                $contract = \App\Models\Contract::find($verification->entity_id);
                if ($contract) {
                    // Send notification to commercial team about value verification
                    $this->notificationService->sendToRole('commercial', [
                        'title' => 'Valor aprovado em verificação dupla',
                        'body' => "O valor para o contrato #{$contract->contract_number} foi verificado e aprovado.",
                        'action_link' => "/contracts/{$contract->id}",
                        'icon' => 'check-circle',
                        'channels' => ['system']
                    ]);
                }
            } elseif ($verification->entity_type === 'negotiation') {
                // Get the negotiation
                $negotiation = \App\Models\Negotiation::find($verification->entity_id);
                if ($negotiation) {
                    // Get the entity being negotiated with
                    $entity = $negotiation->negotiable;
                    $entityName = $entity ? $entity->name : 'Desconhecido';
                    $entityType = $entity instanceof \App\Models\Professional ? 'profissional' : 'clínica';

                    // Send notification to commercial team about value verification
                    $this->notificationService->sendToRole('commercial', [
                        'title' => 'Valor aprovado em verificação dupla',
                        'body' => "O valor para a negociação de especialidades para o {$entityType} {$entityName} foi verificado e aprovado.",
                        'action_link' => "/negotiations/{$negotiation->id}",
                        'icon' => 'check-circle',
                        'channels' => ['system']
                    ]);
                    
                    // Notify creator that they can proceed with the negotiation
                    $this->notificationService->sendToUser($negotiation->creator_id, [
                        'title' => 'Valores de negociação verificados',
                        'body' => "Os valores para a negociação de especialidades foram verificados. Você pode continuar com o processo de aprovação.",
                        'action_link' => "/negotiations/{$negotiation->id}",
                        'icon' => 'check-circle',
                        'channels' => ['system', 'email']
                    ]);
                }
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Valor verificado com sucesso',
                'data' => $verification->fresh(['requester', 'verifier'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to verify value: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'verification_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Falha ao verificar valor',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Reject a value verification.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function reject(Request $request, $id)
    {
        try {
            // Only directors, super_admins can reject values
            $user = Auth::user();
            if (!$user->hasRole(['director', 'super_admin'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não tem permissão para rejeitar valores'
                ], 403);
            }
            
            $verification = ValueVerification::findOrFail($id);
            
            if ($verification->status !== 'pending') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Apenas verificações pendentes podem ser rejeitadas'
                ], 422);
            }
            
            if ($verification->requester_id === $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Você não pode rejeitar um valor que você mesmo solicitou'
                ], 422);
            }
            
            $validated = $request->validate([
                'rejection_reason' => 'required|string|min:5',
            ]);
            
            DB::beginTransaction();
            
            // Use the model method to reject
            $verification->reject($user->id, $validated['rejection_reason']);
            
            // Send notification to the requester
            $requesterName = $verification->requester->name;
            $verifierName = $user->name;
            
            $this->notificationService->sendToUser($verification->requester_id, [
                'title' => 'Valor rejeitado',
                'body' => "Seu valor para {$verification->entity_type} foi rejeitado por {$verifierName}. Motivo: {$validated['rejection_reason']}",
                'action_link' => "/value-verifications/{$verification->id}",
                'icon' => 'x-circle',
                'channels' => ['system', 'email', 'whatsapp'],
                'priority' => 'high'
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Valor rejeitado com sucesso',
                'data' => $verification->fresh(['requester', 'verifier'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to reject value: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'verification_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Falha ao rejeitar valor',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Get count of pending verifications.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPendingCount()
    {
        try {
            $user = Auth::user();
            
            // Count based on role
            if ($user->hasRole(['director', 'super_admin'])) {
                // For directors and admins, count all pending verifications they can approve
                $count = ValueVerification::where('status', 'pending')
                    ->where('requester_id', '!=', $user->id)
                    ->count();
            } else {
                // For others, count their own pending verifications
                $count = ValueVerification::where('status', 'pending')
                    ->where('requester_id', $user->id)
                    ->count();
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'pending_count' => $count
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get pending verification count: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get pending verification count',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 