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
            
            // Use the new notification method instead
            $this->notificationService->notifyContractSubmittedForApproval($contract);
            
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
            $legalApproval = $contract->approvals()
                ->where('step', 'legal_review')
                ->first();
                
            if (!$legalApproval) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Legal review approval record not found'
                ], 404);
            }
            
            $previousStatus = $contract->status;
            
            // Update the approval record
            $legalApproval->update([
                'user_id' => Auth::id(),
                'status' => $validated['action'] === 'approve' ? 'completed' : 'rejected',
                'notes' => $validated['notes'],
                'suggested_changes' => $validated['suggested_changes'] ?? null,
                'completed_at' => now()
            ]);
            
            if ($validated['action'] === 'approve') {
                // Move to commercial review
                $contract->status = 'commercial_review';
                $contract->save();
                
                // Create commercial review approval record
                $contract->approvals()->create([
                    'step' => 'commercial_review',
                    'status' => 'pending'
                ]);
                
                // Use the new notification method
                $this->notificationService->notifyContractStatusChanged($contract, $previousStatus, $validated['notes']);
            } else {
                // Rejected by legal
                $contract->status = 'legal_review';
                $contract->save();
                
                // Use the new notification method
                $this->notificationService->notifyContractStatusChanged($contract, $previousStatus, $validated['notes']);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Legal review completed successfully',
                'data' => $contract->load(['contractable', 'creator', 'approvals'])
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
            if ($contract->status !== 'commercial_review') {
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
            $commercialApproval = $contract->approvals()
                ->where('step', 'commercial_review')
                ->first();
                
            if (!$commercialApproval) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Commercial review approval record not found'
                ], 404);
            }
            
            $previousStatus = $contract->status;
            
            // Update the approval record
            $commercialApproval->update([
                'user_id' => Auth::id(),
                'status' => $validated['action'] === 'approve' ? 'completed' : 'rejected',
                'notes' => $validated['notes'],
                'completed_at' => now()
            ]);
            
            if ($validated['action'] === 'approve') {
                // Move to director approval
                $contract->status = 'pending_director_approval';
                $contract->save();
                
                // Create director approval record
                $contract->approvals()->create([
                    'step' => 'director_approval',
                    'status' => 'pending'
                ]);
                
                // Use the new notification method
                $this->notificationService->notifyContractStatusChanged($contract, $previousStatus, $validated['notes']);
            } else {
                // Rejected by commercial
                $contract->status = 'legal_review';
                $contract->save();
                
                // Use the new notification method
                $this->notificationService->notifyContractStatusChanged($contract, $previousStatus, $validated['notes']);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Commercial review completed successfully',
                'data' => $contract->load(['contractable', 'creator', 'approvals'])
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
     * Director final approval of contract.
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
            $directorApproval = $contract->approvals()
                ->where('step', 'director_approval')
                ->first();
                
            if (!$directorApproval) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Director approval record not found'
                ], 404);
            }
            
            $previousStatus = $contract->status;
            
            // Update the approval record
            $directorApproval->update([
                'user_id' => Auth::id(),
                'status' => $validated['action'] === 'approve' ? 'completed' : 'rejected',
                'notes' => $validated['notes'],
                'completed_at' => now()
            ]);
            
            if ($validated['action'] === 'approve') {
                // Fully approved
                $contract->status = 'approved';
                $contract->approved_at = now();
                $contract->save();
                
                // Add final approval record
                $contract->approvals()->create([
                    'step' => 'approved',
                    'user_id' => Auth::id(),
                    'status' => 'completed',
                    'notes' => 'Contract fully approved',
                    'completed_at' => now()
                ]);
                
                // Use the new notification method
                $this->notificationService->notifyContractStatusChanged($contract, $previousStatus, $validated['notes']);
            } else {
                // Rejected by director
                $contract->status = 'commercial_review';
                $contract->save();
                
                // Use the new notification method
                $this->notificationService->notifyContractStatusChanged($contract, $previousStatus, $validated['notes']);
            }
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Director review completed successfully',
                'data' => $contract->load(['contractable', 'creator', 'approvals'])
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