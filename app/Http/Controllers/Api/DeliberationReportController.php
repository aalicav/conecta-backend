<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Deliberation;
use App\Models\HealthPlan;
use App\Models\Clinic;
use App\Models\MedicalSpecialty;
use Carbon\Carbon;

class DeliberationReportController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
    }

    /**
     * Generate deliberation summary report.
     */
    public function summary(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'health_plan_id' => 'nullable|exists:health_plans,id',
                'clinic_id' => 'nullable|exists:clinics,id',
                'medical_specialty_id' => 'nullable|exists:medical_specialties,id',
                'status' => 'nullable|in:pending_approval,approved,rejected,billed,cancelled',
                'reason' => 'nullable|in:no_table_value,specific_doctor_value,special_agreement,emergency_case,other',
                'requires_operator_approval' => 'nullable|boolean',
                'operator_approved' => 'nullable|boolean'
            ]);

            $query = Deliberation::with([
                'healthPlan',
                'clinic',
                'professional',
                'medicalSpecialty',
                'tussProcedure',
                'createdBy',
                'approvedBy',
                'rejectedBy',
                'cancelledBy',
                'operatorApprovedBy'
            ]);

            // Apply filters
            if (!empty($validated['from_date'])) {
                $query->whereDate('created_at', '>=', $validated['from_date']);
            }
            if (!empty($validated['to_date'])) {
                $query->whereDate('created_at', '<=', $validated['to_date']);
            }
            if (!empty($validated['health_plan_id'])) {
                $query->where('health_plan_id', $validated['health_plan_id']);
            }
            if (!empty($validated['clinic_id'])) {
                $query->where('clinic_id', $validated['clinic_id']);
            }
            if (!empty($validated['medical_specialty_id'])) {
                $query->where('medical_specialty_id', $validated['medical_specialty_id']);
            }
            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            if (!empty($validated['reason'])) {
                $query->where('reason', $validated['reason']);
            }
            if (isset($validated['requires_operator_approval'])) {
                $query->where('requires_operator_approval', $validated['requires_operator_approval']);
            }
            if (isset($validated['operator_approved'])) {
                $query->where('operator_approved', $validated['operator_approved']);
            }

            $deliberations = $query->get();

            // Generate summary data
            $summary = $this->generateSummaryData($deliberations);
            $byStatus = $this->groupByStatus($deliberations);
            $byReason = $this->groupByReason($deliberations);
            $byHealthPlan = $this->groupByHealthPlan($deliberations);
            $byClinic = $this->groupByClinic($deliberations);
            $byMedicalSpecialty = $this->groupByMedicalSpecialty($deliberations);
            $byMonth = $this->groupByMonth($deliberations);
            $operatorApprovalStats = $this->getOperatorApprovalStats($deliberations);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'summary' => $summary,
                    'by_status' => $byStatus,
                    'by_reason' => $byReason,
                    'by_health_plan' => $byHealthPlan,
                    'by_clinic' => $byClinic,
                    'by_medical_specialty' => $byMedicalSpecialty,
                    'by_month' => $byMonth,
                    'operator_approval_stats' => $operatorApprovalStats,
                    'total_records' => $deliberations->count(),
                    'filters_applied' => $validated
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate deliberation summary report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate detailed deliberation report.
     */
    public function detailed(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'health_plan_id' => 'nullable|exists:health_plans,id',
                'clinic_id' => 'nullable|exists:clinics,id',
                'medical_specialty_id' => 'nullable|exists:medical_specialties,id',
                'status' => 'nullable|in:pending_approval,approved,rejected,billed,cancelled',
                'reason' => 'nullable|in:no_table_value,specific_doctor_value,special_agreement,emergency_case,other',
                'requires_operator_approval' => 'nullable|boolean',
                'operator_approved' => 'nullable|boolean',
                'per_page' => 'nullable|integer|min:1|max:1000',
                'page' => 'nullable|integer|min:1'
            ]);

            $query = Deliberation::with([
                'healthPlan',
                'clinic',
                'professional',
                'medicalSpecialty',
                'tussProcedure',
                'createdBy',
                'approvedBy',
                'rejectedBy',
                'cancelledBy',
                'operatorApprovedBy'
            ]);

            // Apply filters
            if (!empty($validated['from_date'])) {
                $query->whereDate('created_at', '>=', $validated['from_date']);
            }
            if (!empty($validated['to_date'])) {
                $query->whereDate('created_at', '<=', $validated['to_date']);
            }
            if (!empty($validated['health_plan_id'])) {
                $query->where('health_plan_id', $validated['health_plan_id']);
            }
            if (!empty($validated['clinic_id'])) {
                $query->where('clinic_id', $validated['clinic_id']);
            }
            if (!empty($validated['medical_specialty_id'])) {
                $query->where('medical_specialty_id', $validated['medical_specialty_id']);
            }
            if (!empty($validated['status'])) {
                $query->where('status', $validated['status']);
            }
            if (!empty($validated['reason'])) {
                $query->where('reason', $validated['reason']);
            }
            if (isset($validated['requires_operator_approval'])) {
                $query->where('requires_operator_approval', $validated['requires_operator_approval']);
            }
            if (isset($validated['operator_approved'])) {
                $query->where('operator_approved', $validated['operator_approved']);
            }

            $perPage = $validated['per_page'] ?? 50;
            $deliberations = $query->orderBy('created_at', 'desc')->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $deliberations
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate detailed deliberation report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate detailed report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate financial report for deliberations.
     */
    public function financial(Request $request)
    {
        try {
            $validated = $request->validate([
                'from_date' => 'nullable|date',
                'to_date' => 'nullable|date|after_or_equal:from_date',
                'health_plan_id' => 'nullable|exists:health_plans,id',
                'clinic_id' => 'nullable|exists:clinics,id',
                'group_by' => 'nullable|in:health_plan,clinic,medical_specialty,month,status,reason'
            ]);

            $query = Deliberation::query();

            // Apply filters
            if (!empty($validated['from_date'])) {
                $query->whereDate('created_at', '>=', $validated['from_date']);
            }
            if (!empty($validated['to_date'])) {
                $query->whereDate('created_at', '<=', $validated['to_date']);
            }
            if (!empty($validated['health_plan_id'])) {
                $query->where('health_plan_id', $validated['health_plan_id']);
            }
            if (!empty($validated['clinic_id'])) {
                $query->where('clinic_id', $validated['clinic_id']);
            }

            $groupBy = $validated['group_by'] ?? 'month';
            $financialData = $this->generateFinancialData($query, $groupBy);

            return response()->json([
                'status' => 'success',
                'data' => $financialData
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate financial deliberation report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate financial report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate summary data.
     */
    protected function generateSummaryData($deliberations)
    {
        return [
            'total_deliberations' => $deliberations->count(),
            'total_negotiated_value' => $deliberations->sum('negotiated_value'),
            'total_medlar_amount' => $deliberations->sum('medlar_amount'),
            'total_value' => $deliberations->sum('total_value'),
            'average_negotiated_value' => $deliberations->avg('negotiated_value'),
            'average_medlar_percentage' => $deliberations->avg('medlar_percentage'),
            'average_total_value' => $deliberations->avg('total_value'),
            'pending_approval' => $deliberations->where('status', 'pending_approval')->count(),
            'approved' => $deliberations->where('status', 'approved')->count(),
            'rejected' => $deliberations->where('status', 'rejected')->count(),
            'billed' => $deliberations->where('status', 'billed')->count(),
            'cancelled' => $deliberations->where('status', 'cancelled')->count(),
            'requiring_operator_approval' => $deliberations->where('requires_operator_approval', true)->count(),
            'operator_approved' => $deliberations->where('operator_approved', true)->count(),
            'operator_rejected' => $deliberations->where('operator_approved', false)->count(),
        ];
    }

    /**
     * Group deliberations by status.
     */
    protected function groupByStatus($deliberations)
    {
        return $deliberations->groupBy('status')->map(function ($group, $status) {
            return [
                'status' => $status,
                'count' => $group->count(),
                'total_value' => $group->sum('total_value'),
                'average_value' => $group->avg('total_value')
            ];
        })->values();
    }

    /**
     * Group deliberations by reason.
     */
    protected function groupByReason($deliberations)
    {
        return $deliberations->groupBy('reason')->map(function ($group, $reason) {
            return [
                'reason' => $reason,
                'reason_label' => $group->first()->reason_label,
                'count' => $group->count(),
                'total_value' => $group->sum('total_value'),
                'average_value' => $group->avg('total_value')
            ];
        })->values();
    }

    /**
     * Group deliberations by health plan.
     */
    protected function groupByHealthPlan($deliberations)
    {
        return $deliberations->groupBy('health_plan_id')->map(function ($group) {
            $healthPlan = $group->first()->healthPlan;
            return [
                'health_plan_id' => $healthPlan->id,
                'health_plan_name' => $healthPlan->name,
                'count' => $group->count(),
                'total_value' => $group->sum('total_value'),
                'average_value' => $group->avg('total_value')
            ];
        })->values();
    }

    /**
     * Group deliberations by clinic.
     */
    protected function groupByClinic($deliberations)
    {
        return $deliberations->groupBy('clinic_id')->map(function ($group) {
            $clinic = $group->first()->clinic;
            return [
                'clinic_id' => $clinic->id,
                'clinic_name' => $clinic->name,
                'count' => $group->count(),
                'total_value' => $group->sum('total_value'),
                'average_value' => $group->avg('total_value')
            ];
        })->values();
    }

    /**
     * Group deliberations by medical specialty.
     */
    protected function groupByMedicalSpecialty($deliberations)
    {
        return $deliberations->groupBy('medical_specialty_id')->map(function ($group) {
            $specialty = $group->first()->medicalSpecialty;
            return [
                'medical_specialty_id' => $specialty->id,
                'medical_specialty_name' => $specialty->name,
                'count' => $group->count(),
                'total_value' => $group->sum('total_value'),
                'average_value' => $group->avg('total_value')
            ];
        })->values();
    }

    /**
     * Group deliberations by month.
     */
    protected function groupByMonth($deliberations)
    {
        return $deliberations->groupBy(function ($deliberation) {
            return Carbon::parse($deliberation->created_at)->format('Y-m');
        })->map(function ($group, $month) {
            return [
                'month' => $month,
                'count' => $group->count(),
                'total_value' => $group->sum('total_value'),
                'average_value' => $group->avg('total_value')
            ];
        })->values();
    }

    /**
     * Get operator approval statistics.
     */
    protected function getOperatorApprovalStats($deliberations)
    {
        $requiringApproval = $deliberations->where('requires_operator_approval', true);
        
        return [
            'total_requiring_approval' => $requiringApproval->count(),
            'approved_by_operator' => $requiringApproval->where('operator_approved', true)->count(),
            'rejected_by_operator' => $requiringApproval->where('operator_approved', false)->count(),
            'pending_operator_approval' => $requiringApproval->whereNull('operator_approved')->count(),
            'approval_rate' => $requiringApproval->count() > 0 
                ? ($requiringApproval->where('operator_approved', true)->count() / $requiringApproval->count()) * 100 
                : 0
        ];
    }

    /**
     * Generate financial data grouped by specified field.
     */
    protected function generateFinancialData($query, $groupBy)
    {
        switch ($groupBy) {
            case 'health_plan':
                return $query->join('health_plans', 'deliberations.health_plan_id', '=', 'health_plans.id')
                    ->select('health_plans.name as group_name', 'health_plans.id as group_id')
                    ->selectRaw('COUNT(*) as count')
                    ->selectRaw('SUM(negotiated_value) as total_negotiated_value')
                    ->selectRaw('SUM(medlar_amount) as total_medlar_amount')
                    ->selectRaw('SUM(total_value) as total_value')
                    ->selectRaw('AVG(negotiated_value) as avg_negotiated_value')
                    ->selectRaw('AVG(medlar_percentage) as avg_medlar_percentage')
                    ->selectRaw('AVG(total_value) as avg_total_value')
                    ->groupBy('health_plans.id', 'health_plans.name')
                    ->orderBy('total_value', 'desc')
                    ->get();

            case 'clinic':
                return $query->join('clinics', 'deliberations.clinic_id', '=', 'clinics.id')
                    ->select('clinics.name as group_name', 'clinics.id as group_id')
                    ->selectRaw('COUNT(*) as count')
                    ->selectRaw('SUM(negotiated_value) as total_negotiated_value')
                    ->selectRaw('SUM(medlar_amount) as total_medlar_amount')
                    ->selectRaw('SUM(total_value) as total_value')
                    ->selectRaw('AVG(negotiated_value) as avg_negotiated_value')
                    ->selectRaw('AVG(medlar_percentage) as avg_medlar_percentage')
                    ->selectRaw('AVG(total_value) as avg_total_value')
                    ->groupBy('clinics.id', 'clinics.name')
                    ->orderBy('total_value', 'desc')
                    ->get();

            case 'medical_specialty':
                return $query->join('medical_specialties', 'deliberations.medical_specialty_id', '=', 'medical_specialties.id')
                    ->select('medical_specialties.name as group_name', 'medical_specialties.id as group_id')
                    ->selectRaw('COUNT(*) as count')
                    ->selectRaw('SUM(negotiated_value) as total_negotiated_value')
                    ->selectRaw('SUM(medlar_amount) as total_medlar_amount')
                    ->selectRaw('SUM(total_value) as total_value')
                    ->selectRaw('AVG(negotiated_value) as avg_negotiated_value')
                    ->selectRaw('AVG(medlar_percentage) as avg_medlar_percentage')
                    ->selectRaw('AVG(total_value) as avg_total_value')
                    ->groupBy('medical_specialties.id', 'medical_specialties.name')
                    ->orderBy('total_value', 'desc')
                    ->get();

            case 'month':
                return $query->selectRaw('DATE_FORMAT(created_at, "%Y-%m") as group_name')
                    ->selectRaw('COUNT(*) as count')
                    ->selectRaw('SUM(negotiated_value) as total_negotiated_value')
                    ->selectRaw('SUM(medlar_amount) as total_medlar_amount')
                    ->selectRaw('SUM(total_value) as total_value')
                    ->selectRaw('AVG(negotiated_value) as avg_negotiated_value')
                    ->selectRaw('AVG(medlar_percentage) as avg_medlar_percentage')
                    ->selectRaw('AVG(total_value) as avg_total_value')
                    ->groupBy('group_name')
                    ->orderBy('group_name')
                    ->get();

            case 'status':
                return $query->select('status as group_name')
                    ->selectRaw('COUNT(*) as count')
                    ->selectRaw('SUM(negotiated_value) as total_negotiated_value')
                    ->selectRaw('SUM(medlar_amount) as total_medlar_amount')
                    ->selectRaw('SUM(total_value) as total_value')
                    ->selectRaw('AVG(negotiated_value) as avg_negotiated_value')
                    ->selectRaw('AVG(medlar_percentage) as avg_medlar_percentage')
                    ->selectRaw('AVG(total_value) as avg_total_value')
                    ->groupBy('status')
                    ->orderBy('total_value', 'desc')
                    ->get();

            case 'reason':
                return $query->select('reason as group_name')
                    ->selectRaw('COUNT(*) as count')
                    ->selectRaw('SUM(negotiated_value) as total_negotiated_value')
                    ->selectRaw('SUM(medlar_amount) as total_medlar_amount')
                    ->selectRaw('SUM(total_value) as total_value')
                    ->selectRaw('AVG(negotiated_value) as avg_negotiated_value')
                    ->selectRaw('AVG(medlar_percentage) as avg_medlar_percentage')
                    ->selectRaw('AVG(total_value) as avg_total_value')
                    ->groupBy('reason')
                    ->orderBy('total_value', 'desc')
                    ->get();

            default:
                return collect();
        }
    }
}
