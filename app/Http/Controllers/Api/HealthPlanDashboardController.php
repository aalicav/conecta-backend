<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HealthPlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class HealthPlanDashboardController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(middleware: 'auth:sanctum');
        $this->middleware('permission:view health plans');
    }

    /**
     * Get dashboard statistics for health plans
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            // Determine time range
            $range = $request->input('range', 'month');
            $startDate = null;
            
            switch ($range) {
                case 'week':
                    $startDate = now()->subWeek();
                    break;
                case 'month':
                    $startDate = now()->subMonth();
                    break;
                case 'quarter':
                    $startDate = now()->subMonths(3);
                    break;
                case 'year':
                    $startDate = now()->subYear();
                    break;
                default:
                    $startDate = now()->subMonth();
            }

            // Check if user is a health plan user
            $isHealthPlanUser = Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin');
            $healthPlanId = $isHealthPlanUser ? Auth::user()->entity_id : null;

            // For health plan users, only show their own data
            if ($isHealthPlanUser) {
                // Get info for a single health plan
                $plan = HealthPlan::find($healthPlanId);
                
                if (!$plan) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Health plan not found'
                    ], 404);
                }
                
                // Count unique procedures from appointments
                $totalProcedures = DB::table('appointments as a')
                    ->join('solicitations as s', 'a.solicitation_id', '=', 's.id')
                    ->where('s.health_plan_id', $healthPlanId)
                    ->distinct('s.tuss_id')
                    ->count('s.tuss_id');
                
                $totalSolicitations = DB::table('solicitations')
                    ->where('health_plan_id', $healthPlanId)
                    ->when($startDate, function ($query) use ($startDate) {
                        return $query->where('created_at', '>=', $startDate);
                    })
                    ->count();
                    
                $totalAppointments = DB::table('appointments')
                    ->whereIn('solicitation_id', function ($query) use ($healthPlanId) {
                        $query->select('id')->from('solicitations')->where('health_plan_id', $healthPlanId);
                    })
                    ->when($startDate, function ($query) use ($startDate) {
                        return $query->where('created_at', '>=', $startDate);
                    })
                    ->count();
                
                $totalRevenue = DB::table('payments')
                    ->where('status', 'paid')
                    ->where('payable_type', 'App\\Models\\HealthPlan')
                    ->where('payable_id', $healthPlanId)
                    ->when($startDate, function ($query) use ($startDate) {
                        return $query->where('created_at', '>=', $startDate);
                    })
                    ->sum('amount');
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_plans' => 1,
                        'approved_plans' => $plan->status === 'approved' ? 1 : 0,
                        'pending_plans' => $plan->status === 'pending' ? 1 : 0,
                        'rejected_plans' => $plan->status === 'rejected' ? 1 : 0,
                        'has_contract' => $plan->has_signed_contract ? 1 : 0,
                        'missing_contract' => $plan->has_signed_contract ? 0 : 1,
                        'total_procedures' => $totalProcedures,
                        'total_solicitations' => $totalSolicitations,
                        'total_appointments' => $totalAppointments,
                        'total_revenue' => $totalRevenue,
                        'time_range' => $range
                    ]
                ]);
            } else {
                // Original code for admin/staff users - show all health plans data
                $totalPlans = HealthPlan::count();
                $approvedPlans = HealthPlan::where('status', 'approved')->count();
                $pendingPlans = HealthPlan::where('status', 'pending')->count();
                $rejectedPlans = HealthPlan::where('status', 'rejected')->count();
                
                $plansWithContract = HealthPlan::where('has_signed_contract', true)->count();
                $plansWithoutContract = HealthPlan::where('has_signed_contract', false)->orWhereNull('has_signed_contract')->count();
                
                // Count unique procedures from appointments
                $totalProcedures = DB::table('appointments as a')
                    ->join('solicitations as s', 'a.solicitation_id', '=', 's.id')
                    ->whereNotNull('s.health_plan_id')
                    ->distinct('s.tuss_id')
                    ->count('s.tuss_id');
                
                $totalSolicitations = DB::table('solicitations')
                    ->whereNotNull('health_plan_id')
                    ->when($startDate, function ($query) use ($startDate) {
                        return $query->where('created_at', '>=', $startDate);
                    })
                    ->count();
                    
                $totalAppointments = DB::table('appointments')
                    ->whereIn('solicitation_id', function ($query) {
                        $query->select('id')->from('solicitations')->whereNotNull('health_plan_id');
                    })
                    ->when($startDate, function ($query) use ($startDate) {
                        return $query->where('created_at', '>=', $startDate);
                    })
                    ->count();
                
                $totalRevenue = DB::table('payments')
                    ->where('status', 'paid')
                    ->whereIn('payable_type', ['App\\Models\\HealthPlan'])
                    ->when($startDate, function ($query) use ($startDate) {
                        return $query->where('created_at', '>=', $startDate);
                    })
                    ->sum('amount');
                
                return response()->json([
                    'success' => true,
                    'data' => [
                        'total_plans' => $totalPlans,
                        'approved_plans' => $approvedPlans,
                        'pending_plans' => $pendingPlans,
                        'rejected_plans' => $rejectedPlans,
                        'has_contract' => $plansWithContract,
                        'missing_contract' => $plansWithoutContract,
                        'total_procedures' => $totalProcedures,
                        'total_solicitations' => $totalSolicitations,
                        'total_appointments' => $totalAppointments,
                        'total_revenue' => $totalRevenue,
                        'time_range' => $range
                    ]
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error getting dashboard stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get procedure statistics for dashboard
     *
     * @return JsonResponse
     */
    public function getProcedures(): JsonResponse
    {
        try {
            // Check if user is a health plan user
            $isHealthPlanUser = Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin');
            $healthPlanId = $isHealthPlanUser ? Auth::user()->entity_id : null;
            
            // Build query to get procedure statistics from appointments
            $query = DB::table('appointments as a')
                ->join('solicitations as s', 'a.solicitation_id', '=', 's.id')
                ->join('tuss_procedures as tp', 's.tuss_id', '=', 'tp.id')
                ->whereNotNull('s.health_plan_id');
                
            // Filter by health plan ID for health plan users
            if ($isHealthPlanUser) {
                $query->where('s.health_plan_id', $healthPlanId);
            }
            
            // Get procedures statistics
            $procedures = $query->select(
                    'tp.id as procedure_id',
                    'tp.name as procedure_name',
                    'tp.code as procedure_code',
                    DB::raw('COUNT(DISTINCT s.health_plan_id) as plans_count'),
                    DB::raw('COUNT(*) as usage_count')
                )
                ->groupBy('tp.id', 'tp.name', 'tp.code')
                ->orderByDesc('usage_count')
                ->limit(10)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $procedures
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting procedure statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get procedure statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get financial data for dashboard
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getFinancial(Request $request): JsonResponse
    {
        try {
            // Check if user is a health plan user
            $isHealthPlanUser = Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin');
            $healthPlanId = $isHealthPlanUser ? Auth::user()->entity_id : null;
            
            // Determine time range
            $range = $request->input('range', 'month');
            $startDate = null;
            $interval = 'month'; // Default grouping interval
            
            switch ($range) {
                case 'week':
                    $startDate = now()->subWeek();
                    $interval = 'day';
                    break;
                case 'month':
                    $startDate = now()->subMonth();
                    $interval = 'day';
                    break;
                case 'quarter':
                    $startDate = now()->subMonths(3);
                    $interval = 'week';
                    break;
                case 'year':
                    $startDate = now()->subYear();
                    $interval = 'month';
                    break;
                default:
                    $startDate = now()->subMonth();
                    $interval = 'day';
            }

            // Build query
            $query = DB::table('payments')
                ->where('status', 'paid')
                ->where('payable_type', 'App\\Models\\HealthPlan')
                ->where('created_at', '>=', $startDate);
                
            // Filter by health plan ID for health plan users
            if ($isHealthPlanUser) {
                $query->where('payable_id', $healthPlanId);
            }
            
            // Group by interval
            $dateFormat = match($interval) {
                'day' => '%Y-%m-%d',
                'week' => '%Y-%u',
                'month' => '%Y-%m',
                default => '%Y-%m-%d'
            };
            
            $data = $query->select(
                    DB::raw("DATE_FORMAT(created_at, '{$dateFormat}') as period"),
                    DB::raw('SUM(amount) as revenue'),
                    DB::raw('COUNT(*) as payments')
                )
                ->groupBy('period')
                ->orderBy('period')
                ->get();
            
            // Format data for response
            $formattedData = $data->map(function ($item) use ($interval) {
                $periodLabel = match($interval) {
                    'day' => Carbon::createFromFormat('Y-m-d', $item->period)->format('d/m/Y'),
                    'week' => "Semana {$item->period}",
                    'month' => Carbon::createFromFormat('Y-m', $item->period)->format('M/Y'),
                    default => $item->period
                };
                
                return [
                    'period' => $item->period,
                    'label' => $periodLabel,
                    'revenue' => $item->revenue,
                    'payments' => $item->payments
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'meta' => [
                    'range' => $range,
                    'interval' => $interval,
                    'start_date' => $startDate->format('Y-m-d')
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting financial data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get financial data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent health plans for dashboard
     *
     * @return JsonResponse
     */
    public function getRecentPlans(): JsonResponse
    {
        try {
            // Check if user is a health plan user
            $isHealthPlanUser = Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin');
            $healthPlanId = $isHealthPlanUser ? Auth::user()->entity_id : null;
            
            // Build query
            $query = HealthPlan::select(
                    'health_plans.id',
                    'health_plans.name',
                    'health_plans.status',
                    'health_plans.created_at',
                    DB::raw('(SELECT COUNT(DISTINCT s.tuss_id) FROM solicitations s WHERE s.health_plan_id = health_plans.id) as procedures_count')
                );
                
            // Filter by health plan ID for health plan users
            if ($isHealthPlanUser) {
                $query->where('id', $healthPlanId);
            } else {
                $query->orderBy('health_plans.created_at', 'desc')
                    ->limit(10);
            }
            
            $recentPlans = $query->get();
            
            return response()->json([
                'success' => true,
                'data' => $recentPlans
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting recent plans: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get recent plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent solicitations for dashboard
     *
     * @return JsonResponse
     */
    public function getRecentSolicitations(): JsonResponse
    {
        try {
            // Check if user is a health plan user
            $isHealthPlanUser = Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin');
            $healthPlanId = $isHealthPlanUser ? Auth::user()->entity_id : null;
            
            // Build query
            $query = DB::table('solicitations as s')
                ->join('health_plans as hp', 's.health_plan_id', '=', 'hp.id')
                ->join('patients as p', 's.patient_id', '=', 'p.id')
                ->join('tuss_procedures as tp', 's.tuss_id', '=', 'tp.id')
                ->select(
                    's.id',
                    'hp.name as health_plan_name',
                    'p.name as patient_name',
                    'tp.name as procedure_name',
                    's.status',
                    's.created_at'
                )
                ->whereNotNull('s.health_plan_id');
                
            // Filter by health plan ID for health plan users
            if ($isHealthPlanUser) {
                $query->where('s.health_plan_id', $healthPlanId);
            }
            
            $recentSolicitations = $query->orderBy('s.created_at', 'desc')
                ->limit(10)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $recentSolicitations
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting recent solicitations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get recent solicitations',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 