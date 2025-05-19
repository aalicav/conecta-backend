<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ContractApprovalController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->middleware('auth:api');
        $this->notificationService = $notificationService;
    }

    /**
     * Display a paginated list of contracts in the approval pipeline.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Contract::with(['contractable', 'creator', 'approvals'])
                ->whereIn('status', ['pending_approval', 'legal_review', 'commercial_review', 'pending_director_approval']);

            // Filter by role access
            $user = Auth::user();
            
            if (!$user->hasRole(['admin', 'super_admin'])) {
                // For legal team
                if ($user->hasRole('legal')) {
                    $query->where(function($q) {
                        $q->where('status', 'legal_review');
                    });
                }
                
                // For commercial team
                if ($user->hasRole('commercial')) {
                    $query->where(function($q) {
                        $q->where('status', 'commercial_review')
                          ->orWhere('status', 'pending_approval');
                    });
                }
                
                // For director
                if ($user->hasRole('director')) {
                    $query->where('status', 'pending_director_approval');
                }
            }

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }
            
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            
            if ($request->filled('search')) {
                $query->where('contract_number', 'like', '%' . $request->input('search') . '%');
            }

            $perPage = $request->input('per_page', 15);
            $contracts = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $contracts
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list contracts for approval: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list contracts for approval',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit a contract for approval.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function submitForApproval(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            // Ensure contract is in draft status
            if ($contract->status !== 'draft') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Only draft contracts can be submitted for approval'
                ], 422);
            }
            
            DB::beginTransaction();
            
            // Update contract status
            $contract->status = 'pending_approval';
            $contract->submitted_at = now();
            $contract->save();
            
            // Create first approval record
            $contract->approvals()->create([
                'step' => 'submission',
                'user_id' => Auth::id(),
                'status' => 'completed',
                'notes' => $request->input('notes'),
                'completed_at' => now()
            ]);
            
            // Create legal review approval record
            $contract->approvals()->create([
                'step' => 'legal_review',
                'status' => 'pending'
            ]);
            
            // Send notification to legal team
            $this->notificationService->sendToRole('legal', [
                'title' => 'Novo contrato para análise jurídica',
                'body' => "O contrato #{$contract->contract_number} está aguardando análise jurídica.",
                'action_link' => "/contracts/{$contract->id}/approval",
                'icon' => 'file-text',
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract submitted for approval successfully',
                'data' => $contract->load(['contractable', 'creator', 'approvals'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to submit contract for approval: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to submit contract for approval',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Legal team review of contract.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function legalReview(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            // Ensure contract is in the correct status
            if ($contract->status !== 'pending_approval') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contract is not awaiting legal review'
                ], 422);
            }
            
            // Validate inputs
            $validated = $request->validate([
                'action' => 'required|in:approve,reject',
                'notes' => 'required|string|min:5',
                'suggested_changes' => 'nullable|string'
            ]);
            
            DB::beginTransaction();
            
            // Find the legal review approval record
            $approvalRecord = $contract->approvals()->where('step', 'legal_review')->first();
            
            if (!$approvalRecord) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal review record not found'
                ], 404);
            }
            
            // Update approval record
            $approvalRecord->user_id = Auth::id();
            $approvalRecord->status = $validated['action'] === 'approve' ? 'completed' : 'rejected';
            $approvalRecord->notes = $validated['notes'];
            $approvalRecord->completed_at = now();
            $approvalRecord->save();
            
            if ($validated['action'] === 'approve') {
                // Update contract status
                $contract->status = 'legal_review';
                $contract->save();
                
                // Create commercial review approval record
                $contract->approvals()->create([
                    'step' => 'commercial_review',
                    'status' => 'pending'
                ]);
                
                // Send notification to commercial team
                $this->notificationService->sendToRole('commercial', [
                    'title' => 'Contrato aprovado pelo Jurídico',
                    'body' => "O contrato #{$contract->contract_number} foi aprovado pelo Jurídico e aguarda liberação comercial.",
                    'action_link' => "/contracts/{$contract->id}/approval",
                    'icon' => 'file-text',
                ]);
            } else {
                // Reject - return to draft
                $contract->status = 'draft';
                $contract->save();
                
                // Send notification to creator
                $this->notificationService->sendToUser($contract->creator_id, [
                    'title' => 'Contrato rejeitado pelo Jurídico',
                    'body' => "O contrato #{$contract->contract_number} foi rejeitado pelo Jurídico e precisa de revisão.",
                    'action_link' => "/contracts/{$contract->id}/edit",
                    'icon' => 'file-text',
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => $validated['action'] === 'approve' 
                    ? 'Contract approved by legal team' 
                    : 'Contract rejected by legal team',
                'data' => $contract->fresh(['contractable', 'creator', 'approvals'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to complete legal review: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete legal review',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Commercial team review of contract.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function commercialReview(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            // Ensure contract is in the correct status
            if ($contract->status !== 'legal_review') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contract is not awaiting commercial review'
                ], 422);
            }
            
            // Validate inputs
            $validated = $request->validate([
                'action' => 'required|in:approve,reject',
                'notes' => 'required|string|min:5',
            ]);
            
            DB::beginTransaction();
            
            // Find the commercial review approval record
            $approvalRecord = $contract->approvals()->where('step', 'commercial_review')->first();
            
            if (!$approvalRecord) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Commercial review record not found'
                ], 404);
            }
            
            // Update approval record
            $approvalRecord->user_id = Auth::id();
            $approvalRecord->status = $validated['action'] === 'approve' ? 'completed' : 'rejected';
            $approvalRecord->notes = $validated['notes'];
            $approvalRecord->completed_at = now();
            $approvalRecord->save();
            
            if ($validated['action'] === 'approve') {
                // Update contract status - transition to pending director approval
                $contract->status = 'pending_director_approval';
                $contract->save();
                
                // Create director approval record
                $contract->approvals()->create([
                    'step' => 'director_approval',
                    'status' => 'pending'
                ]);
                
                // Get all directors
                $directors = \App\Models\User::role('director')->get();
                
                // If Dr. Ítalo is specifically in the system, prioritize sending to him
                $drItalo = $directors->first(function($user) {
                    return strpos(strtolower($user->name), 'ítalo') !== false || strpos(strtolower($user->name), 'italo') !== false;
                });
                
                // Send notification to Dr. Ítalo if found, otherwise to all directors
                if ($drItalo) {
                    $this->notificationService->sendToUser($drItalo->id, [
                        'title' => 'Contrato aguardando aprovação final',
                        'body' => "O contrato #{$contract->contract_number} foi aprovado pela equipe Comercial e aguarda sua aprovação final, Dr. Ítalo.",
                        'action_link' => "/contracts/{$contract->id}/approval",
                        'icon' => 'file-text',
                        'priority' => 'high'
                    ]);
                } else {
                    // Send notification to director role
                    $this->notificationService->sendToRole('director', [
                        'title' => 'Contrato aguardando aprovação final',
                        'body' => "O contrato #{$contract->contract_number} foi aprovado pela equipe Comercial e aguarda aprovação da Direção.",
                        'action_link' => "/contracts/{$contract->id}/approval",
                        'icon' => 'file-text',
                    ]);
                }
                
                // Also log this transition for auditing
                Log::info("Contract #{$contract->contract_number} approved by commercial team and sent for final director approval");
            } else {
                // Reject - return to draft for revision
                $contract->status = 'draft';
                $contract->save();
                
                // Send notification to creator
                $this->notificationService->sendToUser($contract->creator_id, [
                    'title' => 'Contrato rejeitado pela equipe Comercial',
                    'body' => "O contrato #{$contract->contract_number} foi rejeitado pela equipe Comercial e precisa de revisão.",
                    'action_link' => "/contracts/{$contract->id}/edit",
                    'icon' => 'file-text',
                ]);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => $validated['action'] === 'approve' 
                    ? 'Contract approved by commercial team and sent for director approval' 
                    : 'Contract rejected by commercial team',
                'data' => $contract->fresh(['contractable', 'creator', 'approvals'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to complete commercial review: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete commercial review',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Director approval of contract.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function directorApproval(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            // Ensure contract is in the correct status
            if ($contract->status !== 'pending_director_approval') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contract is not awaiting director approval'
                ], 422);
            }
            
            // Validate inputs
            $validated = $request->validate([
                'action' => 'required|in:approve,reject',
                'notes' => 'required|string|min:5',
            ]);
            
            DB::beginTransaction();
            
            // Find the director approval record
            $approvalRecord = $contract->approvals()->where('step', 'director_approval')->first();
            
            if (!$approvalRecord) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Director approval record not found'
                ], 404);
            }
            
            // Update approval record
            $approvalRecord->user_id = Auth::id();
            $approvalRecord->status = $validated['action'] === 'approve' ? 'completed' : 'rejected';
            $approvalRecord->notes = $validated['notes'];
            $approvalRecord->completed_at = now();
            $approvalRecord->save();
            
            if ($validated['action'] === 'approve') {
                // Update contract status to approved
                $contract->status = 'approved';
                $contract->approved_at = now();
                $contract->approved_by = Auth::id();
                $contract->save();
                
                // Log this final approval
                Log::info("Contract #{$contract->contract_number} received final approval from director", [
                    'contract_id' => $contract->id,
                    'director_id' => Auth::id(),
                    'director_name' => Auth::user()->name
                ]);
                
                // Notify multiple parties about the approval
                
                // 1. Notify the creator
                $this->notificationService->sendToUser($contract->creator_id, [
                    'title' => 'Contrato Aprovado pela Direção',
                    'body' => "O contrato #{$contract->contract_number} foi aprovado por Dr. Ítalo e está pronto para assinatura.",
                    'action_link' => "/contracts/{$contract->id}",
                    'icon' => 'check-circle',
                ]);
                
                // 2. Notify the commercial team
                $this->notificationService->sendToRole('commercial', [
                    'title' => 'Contrato Aprovado pela Direção',
                    'body' => "O contrato #{$contract->contract_number} foi aprovado pela Direção e está pronto para assinatura. Favor proceder com o processo de assinatura digital.",
                    'action_link' => "/contracts/{$contract->id}",
                    'icon' => 'check-circle',
                ]);
                
                // 3. Notify the legal team
                $this->notificationService->sendToRole('legal', [
                    'title' => 'Contrato Aprovado pela Direção',
                    'body' => "O contrato #{$contract->contract_number} foi aprovado pela Direção e está pronto para assinatura.",
                    'action_link' => "/contracts/{$contract->id}",
                    'icon' => 'check-circle',
                ]);
                
                // 4. If this is a contract for an entity, notify the entity admin
                if ($contract->contractable_type && $contract->contractable_id) {
                    // Find admins for this entity
                    $entityAdmins = [];
                    
                    if (strpos($contract->contractable_type, 'HealthPlan') !== false) {
                        $entityAdmins = \App\Models\User::role('plan_admin')
                            ->where('entity_id', $contract->contractable_id)
                            ->get();
                    } elseif (strpos($contract->contractable_type, 'Clinic') !== false) {
                        $entityAdmins = \App\Models\User::role('clinic_admin')
                            ->where('entity_id', $contract->contractable_id)
                            ->get();
                    }
                    
                    foreach ($entityAdmins as $admin) {
                        $this->notificationService->sendToUser($admin->id, [
                            'title' => 'Contrato Aprovado para Assinatura',
                            'body' => "Um contrato envolvendo sua organização foi aprovado e será enviado para assinatura digital em breve.",
                            'action_link' => "/contracts/{$contract->id}",
                            'icon' => 'check-circle',
                        ]);
                    }
                }
                
                // 5. Automatically prepare for electronic signature
                // Get contractable entity details for signature
                $signers = [];
                
                // Add INVICTA signer (typically director)
                $signers[] = [
                    'name' => Auth::user()->name,
                    'email' => Auth::user()->email,
                    'cpf' => Auth::user()->cpf ?? null,
                ];
                
                // Add entity signer based on contractable type
                if ($contract->contractable) {
                    $contractable = $contract->contractable;
                    
                    // Determine the appropriate contact for signing
                    if (method_exists($contractable, 'getLegalRepresentativeAttribute') && $contractable->legal_representative) {
                        $signers[] = [
                            'name' => $contractable->legal_representative,
                            'email' => $contractable->legal_representative_email,
                            'cpf' => $contractable->legal_representative_cpf ?? null,
                        ];
                    } elseif (property_exists($contractable, 'responsible_name') && $contractable->responsible_name) {
                        $signers[] = [
                            'name' => $contractable->responsible_name,
                            'email' => $contractable->responsible_email,
                            'cpf' => $contractable->responsible_cpf ?? null,
                        ];
                    } elseif (property_exists($contractable, 'contact_name') && $contractable->contact_name) {
                        $signers[] = [
                            'name' => $contractable->contact_name,
                            'email' => $contractable->contact_email,
                            'cpf' => null,
                        ];
                    }
                }
                
                // Send for electronic signature if we have valid signers
                if (count($signers) > 1) {
                    try {
                        // Get the AutentiqueService instance
                        $autentiqueService = app(\App\Services\AutentiqueService::class);
                        
                        // Send contract for signature
                        $result = $autentiqueService->sendContractForSignature($contract, $signers);
                        
                        if ($result['success']) {
                            Log::info("Contract #{$contract->contract_number} automatically sent for electronic signature", [
                                'contract_id' => $contract->id,
                                'signers' => $signers
                            ]);
                        } else {
                            Log::error("Failed to automatically send contract for signature", [
                                'contract_id' => $contract->id,
                                'error' => $result['message']
                            ]);
                            
                            // Notify commercial team about the failure
                            $this->notificationService->sendToRole('commercial', [
                                'title' => 'Falha no Envio Automático para Assinatura',
                                'body' => "O contrato #{$contract->contract_number} foi aprovado, mas não pôde ser enviado automaticamente para assinatura. Por favor, verifique e envie manualmente.",
                                'action_link' => "/contracts/{$contract->id}",
                                'icon' => 'alert-triangle',
                                'priority' => 'high'
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error("Exception during automatic signature sending: " . $e->getMessage(), [
                            'contract_id' => $contract->id,
                            'exception' => $e
                        ]);
                    }
                } else {
                    // Notify commercial team that manual signature setup is needed
                    $this->notificationService->sendToRole('commercial', [
                        'title' => 'Configuração de Assinatura Necessária',
                        'body' => "O contrato #{$contract->contract_number} foi aprovado, mas precisa de configuração manual para assinatura eletrônica.",
                        'action_link' => "/contracts/{$contract->id}/signature",
                        'icon' => 'edit-3',
                        'priority' => 'high'
                    ]);
                }
                
            } else {
                // Reject - return to draft or previous step based on rejection reason
                if ($request->has('rejection_target') && $request->input('rejection_target') === 'commercial') {
                    // Send back to commercial review
                    $contract->status = 'legal_review';
                    
                    // Send notification to commercial team
                    $this->notificationService->sendToRole('commercial', [
                        'title' => 'Contrato Devolvido pela Direção',
                        'body' => "O contrato #{$contract->contract_number} foi devolvido pela Direção para revisão comercial.",
                        'action_link' => "/contracts/{$contract->id}/approval",
                        'icon' => 'arrow-left-circle',
                    ]);
                } else {
                    // Default: return to draft
                    $contract->status = 'draft';
                    
                    // Send notification to creator
                    $this->notificationService->sendToUser($contract->creator_id, [
                        'title' => 'Contrato Rejeitado pela Direção',
                        'body' => "O contrato #{$contract->contract_number} foi rejeitado pela Direção e precisa de revisão completa.",
                        'action_link' => "/contracts/{$contract->id}/edit",
                        'icon' => 'x-circle',
                    ]);
                }
                
                $contract->save();
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => $validated['action'] === 'approve' 
                    ? 'Contract approved by director' 
                    : 'Contract rejected by director',
                'data' => $contract->fresh(['contractable', 'creator', 'approvals'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to complete director approval: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to complete director approval',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Get approval history for a contract.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function approvalHistory($id)
    {
        try {
            $contract = Contract::with(['approvals.user'])->findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $contract->approvals
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get contract approval history: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get contract approval history',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
} 