<?php

namespace App\Http\Controllers;

use App\Models\BillingRule;
use App\Models\HealthPlan;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BillingRuleController extends Controller
{
    /**
     * Display a listing of the billing rules.
     */
    public function index(Request $request)
    {
        $query = BillingRule::with(['healthPlan', 'contract', 'guideTemplate']);

        // Filter by health plan
        if ($request->has('health_plan_id')) {
            $query->where('health_plan_id', $request->health_plan_id);
        }

        // Filter by contract
        if ($request->has('contract_id')) {
            $query->where('contract_id', $request->contract_id);
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $rules = $query->paginate(10);

        return response()->json($rules);
    }

    /**
     * Store a newly created billing rule.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'health_plan_id' => ['required', 'exists:health_plans,id'],
            'contract_id' => ['required', 'exists:contracts,id'],
            'frequency' => ['required', 'in:monthly,weekly,daily'],
            'monthly_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'batch_size' => ['nullable', 'integer', 'min:1'],
            'payment_days' => ['required', 'integer', 'min:1'],
            'notification_recipients' => ['nullable', 'array'],
            'notification_recipients.*' => ['email'],
            'notification_frequency' => ['required', 'in:daily,weekly,monthly'],
            'document_format' => ['required', 'in:pdf,xml,json'],
            'guide_template_id' => ['nullable', 'exists:document_templates,id'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if a rule already exists for this health plan and contract
        $existingRule = BillingRule::where('health_plan_id', $request->health_plan_id)
            ->where('contract_id', $request->contract_id)
            ->first();

        if ($existingRule) {
            return response()->json([
                'message' => 'A billing rule already exists for this health plan and contract'
            ], 409);
        }

        $rule = BillingRule::create($request->all());

        return response()->json($rule, 201);
    }

    /**
     * Display the specified billing rule.
     */
    public function show(BillingRule $billingRule)
    {
        return response()->json($billingRule->load(['healthPlan', 'contract', 'guideTemplate']));
    }

    /**
     * Update the specified billing rule.
     */
    public function update(Request $request, BillingRule $billingRule)
    {
        $validator = Validator::make($request->all(), [
            'frequency' => ['sometimes', 'in:monthly,weekly,daily'],
            'monthly_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'batch_size' => ['nullable', 'integer', 'min:1'],
            'payment_days' => ['sometimes', 'integer', 'min:1'],
            'notification_recipients' => ['nullable', 'array'],
            'notification_recipients.*' => ['email'],
            'notification_frequency' => ['sometimes', 'in:daily,weekly,monthly'],
            'document_format' => ['sometimes', 'in:pdf,xml,json'],
            'guide_template_id' => ['nullable', 'exists:document_templates,id'],
            'is_active' => ['boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $billingRule->update($request->all());

        return response()->json($billingRule->fresh(['healthPlan', 'contract', 'guideTemplate']));
    }

    /**
     * Remove the specified billing rule.
     */
    public function destroy(BillingRule $billingRule)
    {
        $billingRule->delete();

        return response()->json(null, 204);
    }

    /**
     * Get available health plans for billing rules.
     */
    public function getHealthPlans()
    {
        $healthPlans = HealthPlan::select('id', 'name')->get();
        return response()->json($healthPlans);
    }

    /**
     * Get available contracts for a health plan.
     */
    public function getContracts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'health_plan_id' => ['required', 'exists:health_plans,id']
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $contracts = Contract::where('health_plan_id', $request->health_plan_id)
            ->select('id', 'name')
            ->get();

        return response()->json($contracts);
    }
} 