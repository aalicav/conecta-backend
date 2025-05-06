<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HealthPlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // Contagem de planos por status
            $totalPlans = HealthPlan::count();
            $approvedPlans = HealthPlan::where('status', 'approved')->count();
            $pendingPlans = HealthPlan::where('status', 'pending')->count();
            $rejectedPlans = HealthPlan::where('status', 'rejected')->count();
            
            // Contagem de planos com e sem contrato
            $plansWithContract = HealthPlan::where('has_signed_contract', true)->count();
            $plansWithoutContract = HealthPlan::where('has_signed_contract', false)->orWhereNull('has_signed_contract')->count();
            
            // Contagem de procedimentos
            $totalProcedures = DB::table('health_plan_procedures')
                ->where('is_active', true)
                ->count();
            
            // Estatísticas de solicitações e consultas
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
            
            // Estatísticas financeiras
            $totalRevenue = DB::table('payments')
                ->where('status', 'paid')
                ->whereIn('entity_type', ['App\\Models\\HealthPlan'])
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
            // Obter as estatísticas de procedimentos mais usados e suas faixas de preço
            $procedures = DB::table('health_plan_procedures as hpp')
                ->join('tuss_procedures as tp', 'hpp.tuss_procedure_id', '=', 'tp.id')
                ->where('hpp.is_active', true)
                ->select(
                    'tp.id as procedure_id',
                    'tp.name as procedure_name',
                    'tp.code as procedure_code',
                    DB::raw('AVG(hpp.price) as avg_price'),
                    DB::raw('MIN(hpp.price) as min_price'),
                    DB::raw('MAX(hpp.price) as max_price'),
                    DB::raw('COUNT(DISTINCT hpp.health_plan_id) as plans_count')
                )
                ->groupBy('tp.id', 'tp.name', 'tp.code')
                ->orderByDesc('plans_count')
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

            // Agrupar por intervalo usando SQL específico do banco
            $dateFormat = '%Y-%m-%d'; // Formato padrão para diário
            
            if ($interval === 'week') {
                $dateFormat = '%Y-%u'; // Ano-Semana
            } elseif ($interval === 'month') {
                $dateFormat = '%Y-%m'; // Ano-Mês
            }
            
            // Obter dados agrupados pelo intervalo definido
            $financialData = DB::table('payments')
                ->where('status', 'paid')
                ->whereIn('entity_type', ['App\\Models\\HealthPlan'])
                ->where('created_at', '>=', $startDate)
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '$dateFormat') as period"),
                    DB::raw("SUM(amount) as revenue"),
                    DB::raw("COUNT(*) as payments")
                )
                ->groupBy('period')
                ->orderBy('period')
                ->get();
            
            // Formatar para ficar mais amigável para o frontend
            $formattedData = $financialData->map(function ($item) use ($interval) {
                $periodLabel = $item->period;
                
                if ($interval === 'day') {
                    // Já está no formato correto YYYY-MM-DD
                } elseif ($interval === 'week') {
                    // Converter YYYY-WW para uma descrição da semana
                    $parts = explode('-', $item->period);
                    $year = $parts[0];
                    $week = $parts[1];
                    $periodLabel = "Semana $week, $year";
                } elseif ($interval === 'month') {
                    // Converter YYYY-MM para nome do mês
                    $parts = explode('-', $item->period);
                    $year = $parts[0];
                    $month = $parts[1];
                    $monthNames = [
                        '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março',
                        '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
                        '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro',
                        '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
                    ];
                    $periodLabel = $monthNames[$month] . " $year";
                }
                
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
            // Buscar os planos mais recentes com contagem de procedimentos
            $recentPlans = HealthPlan::select(
                    'health_plans.id',
                    'health_plans.name',
                    'health_plans.status',
                    'health_plans.created_at',
                    DB::raw('(SELECT COUNT(*) FROM health_plan_procedures WHERE health_plan_procedures.health_plan_id = health_plans.id AND health_plan_procedures.is_active = 1) as procedures_count')
                )
                ->orderBy('health_plans.created_at', 'desc')
                ->limit(10)
                ->get();
            
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
            // Buscar solicitações recentes relacionadas a planos de saúde
            $recentSolicitations = DB::table('solicitations as s')
                ->join('health_plans as hp', 's.health_plan_id', '=', 'hp.id')
                ->join('patients as p', 's.patient_id', '=', 'p.id')
                ->join('tuss_procedures as tp', 's.procedure_id', '=', 'tp.id')
                ->select(
                    's.id',
                    'hp.name as health_plan_name',
                    'p.name as patient_name',
                    'tp.name as procedure_name',
                    's.status',
                    's.created_at'
                )
                ->whereNotNull('s.health_plan_id')
                ->orderBy('s.created_at', 'desc')
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