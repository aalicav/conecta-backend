<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HealthPlan;
use App\Models\HealthPlanBillingRule;
use App\Notifications\BillingNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class HealthPlanBillingRuleController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:manage financials')->except(['index', 'show']);
    }

    /**
     * Display a listing of billing rules for a health plan.
     */
    public function index(Request $request, HealthPlan $healthPlan): JsonResponse
    {
        try {
            $query = $healthPlan->billingRules()->with(['creator']);

            // Apply filters
            if ($request->has('is_active')) {
                $isActive = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
                $query->where('is_active', $isActive);
            }

            if ($request->has('billing_type')) {
                $query->where('billing_type', $request->billing_type);
            }

            // Apply sorting
            $sortField = $request->input('sort_by', 'priority');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            // Get paginated results
            $perPage = $request->input('per_page', 15);
            $rules = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $rules
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing health plan billing rules: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to list billing rules',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a new billing rule for a health plan.
     */
    public function store(Request $request, HealthPlan $healthPlan): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'billing_type' => 'required|string|in:per_appointment,monthly,weekly,batch,custom',
                'billing_day' => 'required_if:billing_type,monthly,weekly|nullable|integer|min:1|max:31',
                'batch_threshold_amount' => 'required_if:billing_type,batch|nullable|numeric|min:0',
                'batch_threshold_appointments' => 'required_if:billing_type,batch|nullable|integer|min:1',
                'payment_term_days' => 'required|integer|min:0',
                'minimum_billing_amount' => 'nullable|numeric|min:0',
                'late_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'discount_if_paid_until_days' => 'required_with:discount_percentage|nullable|integer|min:1',
                'notify_on_generation' => 'boolean',
                'notify_before_due_date' => 'boolean',
                'notify_days_before' => 'required_if:notify_before_due_date,true|nullable|integer|min:1',
                'notify_on_late_payment' => 'boolean',
                'is_active' => 'boolean',
                'priority' => 'integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create billing rule
            $rule = new HealthPlanBillingRule($request->all());
            $rule->health_plan_id = $healthPlan->id;
            $rule->created_by = Auth::id();
            $rule->save();

            DB::commit();

            // Load relationships
            $rule->load(['creator']);

            return response()->json([
                'success' => true,
                'message' => 'Billing rule created successfully',
                'data' => $rule
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating health plan billing rule: ' . $e->getMessage());
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
    public function show(HealthPlan $healthPlan, HealthPlanBillingRule $rule): JsonResponse
    {
        try {
            // Ensure the rule belongs to the health plan
            if ($rule->health_plan_id !== $healthPlan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing rule does not belong to this health plan'
                ], 403);
            }

            // Load relationships
            $rule->load(['creator']);

            return response()->json([
                'success' => true,
                'data' => $rule
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving health plan billing rule: ' . $e->getMessage());
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
    public function update(Request $request, HealthPlan $healthPlan, HealthPlanBillingRule $rule): JsonResponse
    {
        try {
            // Ensure the rule belongs to the health plan
            if ($rule->health_plan_id !== $healthPlan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing rule does not belong to this health plan'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'description' => 'nullable|string',
                'billing_type' => 'sometimes|string|in:per_appointment,monthly,weekly,batch,custom',
                'billing_day' => 'required_if:billing_type,monthly,weekly|nullable|integer|min:1|max:31',
                'batch_threshold_amount' => 'required_if:billing_type,batch|nullable|numeric|min:0',
                'batch_threshold_appointments' => 'required_if:billing_type,batch|nullable|integer|min:1',
                'payment_term_days' => 'sometimes|integer|min:0',
                'minimum_billing_amount' => 'nullable|numeric|min:0',
                'late_fee_percentage' => 'nullable|numeric|min:0|max:100',
                'discount_percentage' => 'nullable|numeric|min:0|max:100',
                'discount_if_paid_until_days' => 'required_with:discount_percentage|nullable|integer|min:1',
                'notify_on_generation' => 'boolean',
                'notify_before_due_date' => 'boolean',
                'notify_days_before' => 'required_if:notify_before_due_date,true|nullable|integer|min:1',
                'notify_on_late_payment' => 'boolean',
                'is_active' => 'boolean',
                'priority' => 'integer|min:0'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update rule
            $rule->update($request->all());

            DB::commit();

            // Load relationships
            $rule->load(['creator']);

            return response()->json([
                'success' => true,
                'message' => 'Billing rule updated successfully',
                'data' => $rule
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating health plan billing rule: ' . $e->getMessage());
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
    public function destroy(HealthPlan $healthPlan, HealthPlanBillingRule $rule): JsonResponse
    {
        try {
            // Ensure the rule belongs to the health plan
            if ($rule->health_plan_id !== $healthPlan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing rule does not belong to this health plan'
                ], 403);
            }

            DB::beginTransaction();

            // Check if rule is in use
            $hasActiveBillings = DB::table('billing_batches')
                ->where('billing_rule_id', $rule->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($hasActiveBillings) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete rule with active billings'
                ], 422);
            }

            // Soft delete the rule
            $rule->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Billing rule deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting health plan billing rule: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete billing rule',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Simulate billing for a health plan using a specific rule.
     */
    public function simulateBilling(Request $request, HealthPlan $healthPlan, HealthPlanBillingRule $rule): JsonResponse
    {
        try {
            // Ensure the rule belongs to the health plan
            if ($rule->health_plan_id !== $healthPlan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Billing rule does not belong to this health plan'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'start_date' => 'required|date',
                'end_date' => 'required|date|after:start_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $startDate = Carbon::parse($request->start_date);
            $endDate = Carbon::parse($request->end_date);

            // Get appointments in date range
            $appointments = $healthPlan->appointments()
                ->whereBetween('date', [$startDate, $endDate])
                ->get();

            // Calculate totals
            $totalAmount = $appointments->sum('amount');
            $appointmentCount = $appointments->count();

            // Get next billing date
            $nextBillingDate = $rule->getNextBillingDate();
            $dueDate = $rule->calculateDueDate($nextBillingDate);

            // Calculate potential discounts and fees
            $earlyPaymentDiscount = $rule->calculateDiscount($totalAmount, $nextBillingDate, $dueDate);
            $potentialLateFee = $rule->calculateLateFee($totalAmount, $dueDate->addDay(), $dueDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'period' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d')
                    ],
                    'appointments' => [
                        'count' => $appointmentCount,
                        'total_amount' => $totalAmount
                    ],
                    'billing' => [
                        'next_billing_date' => $nextBillingDate->format('Y-m-d'),
                        'due_date' => $dueDate->format('Y-m-d'),
                        'early_payment_discount' => $earlyPaymentDiscount,
                        'potential_late_fee' => $potentialLateFee,
                        'minimum_amount' => $rule->minimum_billing_amount,
                        'should_bill' => $rule->billing_type === 'batch' ? 
                            $rule->shouldBillBatch($totalAmount, $appointmentCount) : 
                            true
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error simulating health plan billing: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to simulate billing',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 