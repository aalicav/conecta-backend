<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ProfessionalResource;
use App\Models\Appointment;
use App\Models\Professional;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ProfessionalDashboardController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('role:professional');
    }

    /**
     * Get the dashboard data for the professional.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $user = Auth::user();
            $professional = Professional::findOrFail($user->entity_id);

            // Load professional with relationships
            $professional->load(['clinic', 'phones', 'documents']);

            // Get upcoming appointments
            $upcomingAppointments = Appointment::where('provider_type', Professional::class)
                ->where('provider_id', $professional->id)
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->take(5)
                ->with(['solicitation.patient', 'solicitation.tuss'])
                ->get();

            // Get today's appointments
            $todayAppointments = Appointment::where('provider_type', Professional::class)
                ->where('provider_id', $professional->id)
                ->whereDate('scheduled_at', Carbon::today())
                ->orderBy('scheduled_at')
                ->with(['solicitation.patient', 'solicitation.tuss'])
                ->get();

            // Get appointment statistics
            $stats = [
                'today_count' => $todayAppointments->count(),
                'upcoming_count' => $upcomingAppointments->count(),
                'completed_count' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_COMPLETED)
                    ->count(),
                'missed_count' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_MISSED)
                    ->count(),
                'cancelled_count' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_CANCELLED)
                    ->count(),
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'professional' => new ProfessionalResource($professional),
                    'upcoming_appointments' => AppointmentResource::collection($upcomingAppointments),
                    'today_appointments' => AppointmentResource::collection($todayAppointments),
                    'stats' => $stats
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading professional dashboard: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load professional dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the professional's appointments.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function appointments(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $professional = Professional::findOrFail($user->entity_id);

            $query = Appointment::where('provider_type', Professional::class)
                ->where('provider_id', $professional->id);

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('date_from')) {
                $query->where('scheduled_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->where('scheduled_at', '<=', $request->date_to);
            }

            // Apply sorting
            $sort = $request->sort ?? 'scheduled_at';
            $direction = $request->direction ?? 'asc';
            $query->orderBy($sort, $direction);

            // Load relationships
            $query->with(['solicitation.patient', 'solicitation.tuss']);

            // Pagination
            $perPage = $request->per_page ?? 15;
            $appointments = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => AppointmentResource::collection($appointments)
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading professional appointments: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load professional appointments',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the professional's profile.
     *
     * @return JsonResponse
     */
    public function profile(): JsonResponse
    {
        try {
            $user = Auth::user();
            $professional = Professional::findOrFail($user->entity_id);

            // Load professional with relationships
            $professional->load(['clinic', 'phones', 'documents', 'contract']);

            return response()->json([
                'success' => true,
                'data' => new ProfessionalResource($professional)
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading professional profile: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load professional profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get professional statistics.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function stats(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $professional = Professional::findOrFail($user->entity_id);

            // Get date range
            $startDate = $request->start_date ? Carbon::parse($request->start_date) : Carbon::now()->subDays(30);
            $endDate = $request->end_date ? Carbon::parse($request->end_date) : Carbon::now();

            // Get appointment counts by status
            $appointmentStats = [
                'total' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->whereBetween('scheduled_at', [$startDate, $endDate])
                    ->count(),
                'completed' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_COMPLETED)
                    ->whereBetween('scheduled_at', [$startDate, $endDate])
                    ->count(),
                'scheduled' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_SCHEDULED)
                    ->whereBetween('scheduled_at', [$startDate, $endDate])
                    ->count(),
                'confirmed' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_CONFIRMED)
                    ->whereBetween('scheduled_at', [$startDate, $endDate])
                    ->count(),
                'missed' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_MISSED)
                    ->whereBetween('scheduled_at', [$startDate, $endDate])
                    ->count(),
                'cancelled' => Appointment::where('provider_type', Professional::class)
                    ->where('provider_id', $professional->id)
                    ->where('status', Appointment::STATUS_CANCELLED)
                    ->whereBetween('scheduled_at', [$startDate, $endDate])
                    ->count(),
            ];

            // Get upcoming appointments count
            $upcomingCount = Appointment::where('provider_type', Professional::class)
                ->where('provider_id', $professional->id)
                ->where('status', Appointment::STATUS_SCHEDULED)
                ->where('scheduled_at', '>=', now())
                ->count();

            // Get financial data and statistics
            $financialStats = $this->getFinancialStats($professional, $startDate, $endDate);
            
            // Get procedure statistics
            $procedureStats = $this->getProcedureStats($professional, $startDate, $endDate);
            
            // Get patient demographics
            $patientDemographics = $this->getPatientDemographics($professional, $startDate, $endDate);
            
            // Get performance metrics over time (monthly/weekly breakdown)
            $performanceOverTime = $this->getPerformanceOverTime($professional, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => [
                    'appointment_stats' => $appointmentStats,
                    'upcoming_count' => $upcomingCount,
                    'financial_stats' => $financialStats,
                    'procedure_stats' => $procedureStats,
                    'patient_demographics' => $patientDemographics,
                    'performance_over_time' => $performanceOverTime,
                    'date_range' => [
                        'start' => $startDate->format('Y-m-d'),
                        'end' => $endDate->format('Y-m-d'),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading professional statistics: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to load professional statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get financial statistics for the professional.
     *
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getFinancialStats(Professional $professional, Carbon $startDate, Carbon $endDate): array
    {
        // Get completed appointments with payments
        $completedAppointments = Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->with(['solicitation.payment', 'solicitation.tuss'])
            ->get();

        // Calculate revenue
        $totalRevenue = 0;
        $paidRevenue = 0;
        $pendingRevenue = 0;
        $cancelledRevenue = 0;
        
        foreach ($completedAppointments as $appointment) {
            $payment = $appointment->solicitation->payment ?? null;
            $amount = $appointment->solicitation->tuss->price ?? 0;
            
            if ($payment) {
                switch ($payment->status) {
                    case 'paid':
                        $paidRevenue += $amount;
                        $totalRevenue += $amount;
                        break;
                    case 'pending':
                        $pendingRevenue += $amount;
                        $totalRevenue += $amount;
                        break;
                    case 'cancelled':
                        $cancelledRevenue += $amount;
                        break;
                }
            } else {
                // If no payment record, consider as pending
                $pendingRevenue += $amount;
                $totalRevenue += $amount;
            }
        }
        
        // Calculate average revenue per appointment
        $averageRevenue = $completedAppointments->count() > 0 
            ? $totalRevenue / $completedAppointments->count() 
            : 0;
            
        // Get procedure pricing data
        $pricingContracts = $professional->pricingContracts()
            ->with('procedure')
            ->where('is_active', true)
            ->get();
            
        $procedurePrices = $pricingContracts->map(function($contract) {
            return [
                'id' => $contract->id,
                'tuss_id' => $contract->tuss_procedure_id,
                'name' => $contract->procedure->name ?? 'Unknown Procedure',
                'code' => $contract->procedure->code ?? 'N/A',
                'price' => $contract->price,
            ];
        });
        
        return [
            'total_revenue' => $totalRevenue,
            'paid_revenue' => $paidRevenue,
            'pending_revenue' => $pendingRevenue,
            'cancelled_revenue' => $cancelledRevenue,
            'average_revenue_per_appointment' => $averageRevenue,
            'procedure_prices' => $procedurePrices,
            'pricing_metrics' => [
                'min_price' => $procedurePrices->min('price') ?? 0,
                'max_price' => $procedurePrices->max('price') ?? 0,
                'avg_price' => $procedurePrices->avg('price') ?? 0,
                'total_procedures' => $procedurePrices->count(),
            ]
        ];
    }
    
    /**
     * Get procedure statistics for the professional.
     *
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getProcedureStats(Professional $professional, Carbon $startDate, Carbon $endDate): array
    {
        // Get completed appointments with procedures
        $appointments = Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->with(['solicitation.tuss'])
            ->get();
            
        // Count procedures by type
        $procedureCounts = [];
        $topProcedures = [];
        
        foreach ($appointments as $appointment) {
            $tuss = $appointment->solicitation->tuss ?? null;
            if ($tuss) {
                $tussId = $tuss->id;
                $tussName = $tuss->name;
                
                if (!isset($procedureCounts[$tussId])) {
                    $procedureCounts[$tussId] = [
                        'id' => $tussId,
                        'name' => $tussName,
                        'count' => 0
                    ];
                }
                
                $procedureCounts[$tussId]['count']++;
            }
        }
        
        // Sort by count and get top procedures
        usort($procedureCounts, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        $topProcedures = array_slice($procedureCounts, 0, 5);
        
        return [
            'total_procedures' => count($appointments),
            'unique_procedures' => count($procedureCounts),
            'top_procedures' => $topProcedures,
            'procedure_counts' => array_values($procedureCounts)
        ];
    }
    
    /**
     * Get patient demographics statistics.
     *
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getPatientDemographics(Professional $professional, Carbon $startDate, Carbon $endDate): array
    {
        // Get unique patients from appointments
        $appointments = Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->with(['solicitation.patient'])
            ->get();
            
        $uniquePatients = $appointments->pluck('solicitation.patient')
            ->filter()
            ->unique('id')
            ->values();
            
        // Calculate age groups
        $ageGroups = [
            '0-18' => 0,
            '19-35' => 0,
            '36-50' => 0,
            '51-65' => 0,
            '65+' => 0
        ];
        
        $genderDistribution = [
            'male' => 0,
            'female' => 0,
            'other' => 0,
            'unknown' => 0
        ];
        
        $healthPlanDistribution = [];
        
        foreach ($uniquePatients as $patient) {
            // Calculate age
            if ($patient->birth_date) {
                $age = Carbon::parse($patient->birth_date)->age;
                
                if ($age <= 18) {
                    $ageGroups['0-18']++;
                } elseif ($age <= 35) {
                    $ageGroups['19-35']++;
                } elseif ($age <= 50) {
                    $ageGroups['36-50']++;
                } elseif ($age <= 65) {
                    $ageGroups['51-65']++;
                } else {
                    $ageGroups['65+']++;
                }
            }
            
            // Count gender distribution
            $gender = strtolower($patient->gender ?? '');
            if ($gender == 'male' || $gender == 'masculino') {
                $genderDistribution['male']++;
            } elseif ($gender == 'female' || $gender == 'feminino') {
                $genderDistribution['female']++;
            } elseif (!empty($gender)) {
                $genderDistribution['other']++;
            } else {
                $genderDistribution['unknown']++;
            }
            
            // Count health plan distribution
            if ($patient->health_plan_id) {
                $healthPlanName = $patient->healthPlan->name ?? 'Unknown Plan';
                if (!isset($healthPlanDistribution[$healthPlanName])) {
                    $healthPlanDistribution[$healthPlanName] = 0;
                }
                $healthPlanDistribution[$healthPlanName]++;
            }
        }
        
        // Format health plan data for response
        $healthPlans = [];
        foreach ($healthPlanDistribution as $name => $count) {
            $healthPlans[] = [
                'name' => $name,
                'count' => $count
            ];
        }
        
        // Sort health plans by count
        usort($healthPlans, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return [
            'unique_patients' => $uniquePatients->count(),
            'new_patients' => $uniquePatients->filter(function($patient) use ($startDate) {
                return $patient->created_at >= $startDate;
            })->count(),
            'returning_patients' => $uniquePatients->count() - $uniquePatients->filter(function($patient) use ($startDate) {
                return $patient->created_at >= $startDate;
            })->count(),
            'age_groups' => $ageGroups,
            'gender_distribution' => $genderDistribution,
            'health_plans' => $healthPlans
        ];
    }
    
    /**
     * Get performance metrics over time.
     *
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array
     */
    private function getPerformanceOverTime(Professional $professional, Carbon $startDate, Carbon $endDate): array
    {
        $interval = $startDate->diffInDays($endDate) > 90 ? 'month' : 'week';
        
        $result = [];
        
        if ($interval === 'month') {
            // Monthly breakdown
            $currentDate = clone $startDate;
            
            while ($currentDate <= $endDate) {
                $periodStart = clone $currentDate;
                $periodEnd = clone $currentDate;
                $periodEnd->endOfMonth();
                
                if ($periodEnd > $endDate) {
                    $periodEnd = clone $endDate;
                }
                
                $result[] = [
                    'period' => $currentDate->format('Y-m'),
                    'label' => $currentDate->format('M Y'),
                    'total_appointments' => $this->getAppointmentCountInPeriod($professional, $periodStart, $periodEnd),
                    'completion_rate' => $this->getCompletionRateInPeriod($professional, $periodStart, $periodEnd),
                    'revenue' => $this->getRevenueInPeriod($professional, $periodStart, $periodEnd),
                ];
                
                $currentDate->addMonth();
            }
        } else {
            // Weekly breakdown
            $currentDate = clone $startDate;
            
            while ($currentDate <= $endDate) {
                $periodStart = clone $currentDate;
                $periodEnd = clone $currentDate;
                $periodEnd->addDays(6);
                
                if ($periodEnd > $endDate) {
                    $periodEnd = clone $endDate;
                }
                
                $result[] = [
                    'period' => $currentDate->format('Y-W'),
                    'label' => 'Week ' . $currentDate->format('W') . ' (' . $currentDate->format('M d') . ' - ' . $periodEnd->format('M d') . ')',
                    'total_appointments' => $this->getAppointmentCountInPeriod($professional, $periodStart, $periodEnd),
                    'completion_rate' => $this->getCompletionRateInPeriod($professional, $periodStart, $periodEnd),
                    'revenue' => $this->getRevenueInPeriod($professional, $periodStart, $periodEnd),
                ];
                
                $currentDate->addDays(7);
            }
        }
        
        return [
            'interval' => $interval,
            'periods' => $result
        ];
    }
    
    /**
     * Get appointment count for a specific period.
     * 
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return int
     */
    private function getAppointmentCountInPeriod(Professional $professional, Carbon $startDate, Carbon $endDate): int
    {
        return Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->count();
    }
    
    /**
     * Get completion rate for a specific period.
     * 
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return float
     */
    private function getCompletionRateInPeriod(Professional $professional, Carbon $startDate, Carbon $endDate): float
    {
        $total = Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->count();
            
        if ($total === 0) {
            return 0;
        }
        
        $completed = Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->count();
            
        return round(($completed / $total) * 100, 2);
    }
    
    /**
     * Get revenue for a specific period.
     * 
     * @param Professional $professional
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return float
     */
    private function getRevenueInPeriod(Professional $professional, Carbon $startDate, Carbon $endDate): float
    {
        $appointments = Appointment::where('provider_type', Professional::class)
            ->where('provider_id', $professional->id)
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->with(['solicitation.payment', 'solicitation.tuss'])
            ->get();
            
        $revenue = 0;
        
        foreach ($appointments as $appointment) {
            if ($appointment->status === Appointment::STATUS_COMPLETED) {
                $revenue += $appointment->solicitation->tuss->price ?? 0;
            }
        }
        
        return $revenue;
    }
} 