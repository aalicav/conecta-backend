<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingRule;
use App\Models\BillingBatch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BillingRuleController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }
    
    /**
     * Display a listing of billing rules.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = BillingRule::with(['healthPlan']);
            
            // Apply filters
            if ($request->has('health_plan_id')) {
                $query->where('health_plan_id', $request->health_plan_id);
            }
            
            if ($request->has('frequency')) {
                $query->where('frequency', $request->frequency);
            }
            
            if ($request->has('is_active')) {
                $isActive = $request->is_active === 'true' ? true : false;
                $query->where('is_active', $isActive);
            }
            
            // Apply sorting
            $sort = $request->sort ?? 'id';
            $direction = $request->direction ?? 'desc';
            $query->orderBy($sort, $direction);
            
            // Pagination
            $perPage = $request->per_page ?? 15;
            $rules = $query->paginate($perPage);
            
            return response()->json([
                'success' => true,
                'data' => $rules
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing billing rules: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to list billing rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store a new billing rule.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'health_plan_id' => 'required|exists:health_plans,id',
                'frequency' => 'required|string|in:monthly,weekly,daily',
                'monthly_day' => 'nullable|integer|min:1|max:31',
                'batch_size' => 'nullable|integer|min:1',
                'payment_days' => 'required|integer|min:1',
                'notification_recipients' => 'nullable|array',
                'notification_recipients.*' => 'email',
                'notification_frequency' => 'required|string|in:daily,weekly,monthly',
                'document_format' => 'required|string|in:pdf,xml,json',
                'is_active' => 'boolean',
                'generate_nfe' => 'boolean',
                'nfe_series' => 'nullable|integer',
                'nfe_environment' => 'nullable|integer|in:1,2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();
            
            $rule = new BillingRule($request->all());
            $rule->save();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Billing rule created successfully',
                'data' => $rule->load(['healthPlan'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating billing rule: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create billing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified billing rule.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $billingRule = BillingRule::with(['healthPlan'])->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $billingRule
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving billing rule: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve billing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update the specified billing rule.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $billingRule = BillingRule::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'health_plan_id' => 'sometimes|exists:health_plans,id',
                'frequency' => 'sometimes|string|in:monthly,weekly,daily',
                'monthly_day' => 'nullable|integer|min:1|max:31',
                'batch_size' => 'nullable|integer|min:1',
                'payment_days' => 'sometimes|integer|min:1',
                'notification_recipients' => 'nullable|array',
                'notification_recipients.*' => 'email',
                'notification_frequency' => 'sometimes|string|in:daily,weekly,monthly',
                'document_format' => 'sometimes|string|in:pdf,xml,json',
                'is_active' => 'boolean',
                'generate_nfe' => 'boolean',
                'nfe_series' => 'nullable|integer',
                'nfe_environment' => 'nullable|integer|in:1,2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();
            
            $billingRule->update($request->all());
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Billing rule updated successfully',
                'data' => $billingRule->load(['healthPlan'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating billing rule: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update billing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Remove the specified billing rule.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $billingRule = BillingRule::findOrFail($id);
            
            DB::beginTransaction();
            
            // Check if rule is in use
            $inUse = BillingBatch::where('billing_rule_id', $id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();
                
            if ($inUse) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete this rule as it is being used in active billing batches'
                ], 422);
            }
            
            $billingRule->delete();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Billing rule deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting billing rule: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete billing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Toggle the active status of a billing rule.
     */
    public function toggleActive(int $id): JsonResponse
    {
        try {
            $billingRule = BillingRule::findOrFail($id);
            
            DB::beginTransaction();
            
            $billingRule->is_active = !$billingRule->is_active;
            $billingRule->save();
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Billing rule status updated successfully',
                'data' => [
                    'id' => $billingRule->id,
                    'is_active' => $billingRule->is_active
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating billing rule status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update billing rule status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get applicable billing rules for a specific entity.
     */
    public function getApplicableRules(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'entity_type' => 'required|string|in:App\\Models\\HealthPlan,App\\Models\\Clinic,App\\Models\\Professional',
                'entity_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Get specific entity rules + global rules for entity type
            $rules = BillingRule::where(function($query) use ($request) {
                    $query->where([
                        'entity_type' => $request->entity_type,
                        'entity_id' => $request->entity_id,
                    ])
                    ->orWhere([
                        'entity_type' => $request->entity_type,
                        'entity_id' => null, // Global rules
                    ]);
                })
                ->where('is_active', true)
                ->orderBy('priority', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $rules
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving applicable billing rules: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve applicable billing rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Simulate billing for a specific entity with a rule
     */
    public function simulateBilling(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'billing_rule_id' => 'required|exists:billing_rules,id',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid data',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $billingRule = BillingRule::findOrFail($request->billing_rule_id);
            
            // Logic to simulate billing (example data for demonstration)
            $simulationResults = [
                'rule' => [
                    'id' => $billingRule->id,
                    'name' => $billingRule->name
                ],
                'period' => [
                    'start_date' => $request->start_date,
                    'end_date' => $request->end_date
                ],
                'estimated_items' => rand(5, 30),
                'estimated_total' => round(rand(1000, 5000) / 100, 2) * 100,
                'estimated_fees' => round(rand(100, 500) / 100, 2) * 10,
                'estimated_taxes' => round(rand(100, 300) / 100, 2) * 10,
                'estimated_net' => 0,
                'billing_date' => date('Y-m-d', strtotime('+10 days')),
                'due_date' => date('Y-m-d', strtotime('+30 days')),
            ];
            
            // Calculate net amount
            $simulationResults['estimated_net'] = 
                $simulationResults['estimated_total'] - 
                $simulationResults['estimated_fees'] - 
                $simulationResults['estimated_taxes'];
            
            return response()->json([
                'success' => true,
                'data' => $simulationResults
            ]);
        } catch (\Exception $e) {
            Log::error('Error simulating billing: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to simulate billing',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 