<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\NpsResponse;
use App\Models\ProfessionalEvaluation;
use App\Models\MedlarEvaluation;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EvaluationsController extends Controller
{
    /**
     * Display the evaluations dashboard
     */
    public function index()
    {
        // Get statistics for the last 30 days
        $last30Days = now()->subDays(30);
        
        // NPS Statistics
        $npsStats = [
            'total' => NpsResponse::where('responded_at', '>=', $last30Days)->count(),
            'promoters' => NpsResponse::where('responded_at', '>=', $last30Days)->promoters()->count(),
            'neutrals' => NpsResponse::where('responded_at', '>=', $last30Days)->neutrals()->count(),
            'detractors' => NpsResponse::where('responded_at', '>=', $last30Days)->detractors()->count(),
        ];
        
        // Calculate NPS Score
        $npsScore = $this->calculateNpsScore($npsStats);
        
        // Professional Evaluation Statistics
        $professionalStats = [
            'total' => ProfessionalEvaluation::where('responded_at', '>=', $last30Days)->count(),
            'promoters' => ProfessionalEvaluation::where('responded_at', '>=', $last30Days)->promoters()->count(),
            'neutrals' => ProfessionalEvaluation::where('responded_at', '>=', $last30Days)->neutrals()->count(),
            'detractors' => ProfessionalEvaluation::where('responded_at', '>=', $last30Days)->detractors()->count(),
        ];
        
        $professionalNpsScore = $this->calculateNpsScore($professionalStats);
        
        // Medlar Evaluation Statistics
        $medlarStats = [
            'total' => MedlarEvaluation::where('responded_at', '>=', $last30Days)->count(),
            'promoters' => MedlarEvaluation::where('responded_at', '>=', $last30Days)->promoters()->count(),
            'neutrals' => MedlarEvaluation::where('responded_at', '>=', $last30Days)->neutrals()->count(),
            'detractors' => MedlarEvaluation::where('responded_at', '>=', $last30Days)->detractors()->count(),
        ];
        
        $medlarNpsScore = $this->calculateNpsScore($medlarStats);
        
        // Recent evaluations
        $recentNps = NpsResponse::with(['patient', 'appointment'])
            ->orderBy('responded_at', 'desc')
            ->limit(10)
            ->get();
            
        $recentProfessional = ProfessionalEvaluation::with(['patient', 'appointment', 'professional'])
            ->orderBy('responded_at', 'desc')
            ->limit(10)
            ->get();
            
        $recentMedlar = MedlarEvaluation::with(['patient', 'appointment'])
            ->orderBy('responded_at', 'desc')
            ->limit(10)
            ->get();
        
        return view('evaluations.index', compact(
            'npsStats', 'npsScore',
            'professionalStats', 'professionalNpsScore',
            'medlarStats', 'medlarNpsScore',
            'recentNps', 'recentProfessional', 'recentMedlar'
        ));
    }
    
    /**
     * Display NPS responses
     */
    public function nps(Request $request)
    {
        $query = NpsResponse::with(['patient', 'appointment']);
        
        // Apply filters
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        
        if ($request->filled('date_from')) {
            $query->where('responded_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->where('responded_at', '<=', $request->date_to);
        }
        
        $responses = $query->orderBy('responded_at', 'desc')->paginate(20);
        
        return view('evaluations.nps', compact('responses'));
    }
    
    /**
     * Display professional evaluations
     */
    public function professional(Request $request)
    {
        $query = ProfessionalEvaluation::with(['patient', 'appointment', 'professional']);
        
        // Apply filters
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        
        if ($request->filled('professional_id')) {
            $query->where('professional_id', $request->professional_id);
        }
        
        if ($request->filled('date_from')) {
            $query->where('responded_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->where('responded_at', '<=', $request->date_to);
        }
        
        $evaluations = $query->orderBy('responded_at', 'desc')->paginate(20);
        
        // Get professionals for filter dropdown
        $professionals = User::whereHas('professionalEvaluations')->get();
        
        return view('evaluations.professional', compact('evaluations', 'professionals'));
    }
    
    /**
     * Display Medlar evaluations
     */
    public function medlar(Request $request)
    {
        $query = MedlarEvaluation::with(['patient', 'appointment']);
        
        // Apply filters
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        
        if ($request->filled('date_from')) {
            $query->where('responded_at', '>=', $request->date_from);
        }
        
        if ($request->filled('date_to')) {
            $query->where('responded_at', '<=', $request->date_to);
        }
        
        $evaluations = $query->orderBy('responded_at', 'desc')->paginate(20);
        
        return view('evaluations.medlar', compact('evaluations'));
    }
    
    /**
     * Get evaluation statistics for charts
     */
    public function statistics(Request $request)
    {
        $period = $request->get('period', '30'); // days
        $startDate = now()->subDays($period);
        
        // NPS daily data
        $npsDaily = NpsResponse::select(
                DB::raw('DATE(responded_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN category = "promoter" THEN 1 ELSE 0 END) as promoters'),
                DB::raw('SUM(CASE WHEN category = "neutral" THEN 1 ELSE 0 END) as neutrals'),
                DB::raw('SUM(CASE WHEN category = "detractor" THEN 1 ELSE 0 END) as detractors')
            )
            ->where('responded_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        // Professional evaluation daily data
        $professionalDaily = ProfessionalEvaluation::select(
                DB::raw('DATE(responded_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN category = "promoter" THEN 1 ELSE 0 END) as promoters'),
                DB::raw('SUM(CASE WHEN category = "neutral" THEN 1 ELSE 0 END) as neutrals'),
                DB::raw('SUM(CASE WHEN category = "detractor" THEN 1 ELSE 0 END) as detractors')
            )
            ->where('responded_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        // Medlar evaluation daily data
        $medlarDaily = MedlarEvaluation::select(
                DB::raw('DATE(responded_at) as date'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN category = "promoter" THEN 1 ELSE 0 END) as promoters'),
                DB::raw('SUM(CASE WHEN category = "neutral" THEN 1 ELSE 0 END) as neutrals'),
                DB::raw('SUM(CASE WHEN category = "detractor" THEN 1 ELSE 0 END) as detractors')
            )
            ->where('responded_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();
        
        return response()->json([
            'nps' => $npsDaily,
            'professional' => $professionalDaily,
            'medlar' => $medlarDaily
        ]);
    }
    
    /**
     * Calculate NPS Score
     */
    private function calculateNpsScore($stats): float
    {
        if ($stats['total'] == 0) {
            return 0;
        }
        
        $promoterPercentage = ($stats['promoters'] / $stats['total']) * 100;
        $detractorPercentage = ($stats['detractors'] / $stats['total']) * 100;
        
        return round($promoterPercentage - $detractorPercentage, 1);
    }
}
