<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportGeneration;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Models\Solicitation;
use App\Models\Appointment;
use App\Models\HealthPlan;
use App\Models\Professional;
use App\Models\Clinic;
use Carbon\Carbon;

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->middleware('auth:sanctum');
        $this->reportService = $reportService;
    }

    /**
     * Display a listing of reports.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Report::with(['creator', 'generations' => function ($query) {
                $query->latest('completed_at')->limit(1);
            }]);
            
            // Apply filters
            if ($request->has('type')) {
                $query->where('type', $request->input('type'));
            }
            
            if ($request->has('is_template')) {
                $query->where('is_template', $request->boolean('is_template'));
            }
            
            if ($request->has('is_scheduled')) {
                $query->where('is_scheduled', $request->boolean('is_scheduled'));
            }
            
            if ($request->has('created_by') && $request->input('created_by') !== 'all') {
                $query->where('created_by', $request->input('created_by'));
            }
            
            // Access control based on user role
            if (Auth::user()->hasRole(['health_plan', 'plan_admin'])) {
                // Health plan users can only see reports they created or ones that are marked public
                // and those that contain data related to their health plan
                $healthPlanId = Auth::user()->entity_id;
                $query->where(function ($q) use ($healthPlanId) {
                    $q->where('created_by', Auth::id())
                      ->orWhere('is_public', true)
                      ->orWhereJsonContains('parameters->health_plan_id', $healthPlanId);
                });
            }
            // Non-admins can only see their own reports and public reports
            elseif (!Auth::user()->hasRole('admin')) {
                $query->where(function ($q) {
                    $q->where('created_by', Auth::id())
                      ->orWhere('is_public', true);
                });
            }
            
            // Sort options
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            $reports = $query->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $reports,
                'meta' => [
                    'total' => $reports->total(),
                    'per_page' => $reports->perPage(),
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch reports: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created report.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'type' => 'required|string|in:financial,appointment,performance,custom',
                'description' => 'nullable|string',
                'parameters' => 'nullable|array',
                'file_format' => 'nullable|string|in:pdf,csv,xlsx',
                'is_scheduled' => 'boolean',
                'schedule_frequency' => 'nullable|required_if:is_scheduled,true|string|in:daily,weekly,monthly,quarterly',
                'recipients' => 'nullable|array',
                'recipients.*' => 'email',
                'is_public' => 'boolean',
                'is_template' => 'boolean',
            ]);
            
            $validated['created_by'] = Auth::id();
            
            DB::beginTransaction();
            
            $report = Report::create($validated);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Report created successfully',
                'data' => $report
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified report.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $report = Report::with(['creator', 'generations' => function ($query) {
                $query->latest('completed_at')->limit(5);
            }])->findOrFail($id);
            
            // Access control - non-admins can only see their own reports and public reports
            if (!Auth::user()->hasRole('admin') && 
                $report->created_by !== Auth::id() && 
                !$report->is_public) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to view this report'
                ], 403);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $report
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'report_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch report',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Update the specified report.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Access control - only admins and the creator can update reports
            if (!Auth::user()->hasRole('admin') && $report->created_by !== Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to update this report'
                ], 403);
            }
            
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'parameters' => 'nullable|array',
                'file_format' => 'nullable|string|in:pdf,csv,xlsx',
                'is_scheduled' => 'boolean',
                'schedule_frequency' => 'nullable|required_if:is_scheduled,true|string|in:daily,weekly,monthly,quarterly',
                'recipients' => 'nullable|array',
                'recipients.*' => 'email',
                'is_public' => 'boolean',
                'is_template' => 'boolean',
            ]);
            
            DB::beginTransaction();
            
            $report->update($validated);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Report updated successfully',
                'data' => $report
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'report_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update report',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Remove the specified report.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Access control - only admins and the creator can delete reports
            if (!Auth::user()->hasRole('admin') && $report->created_by !== Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to delete this report'
                ], 403);
            }
            
            DB::beginTransaction();
            
            // Delete associated generations first
            foreach ($report->generations as $generation) {
                if ($generation->file_path && Storage::exists($generation->file_path)) {
                    Storage::delete($generation->file_path);
                }
                $generation->delete();
            }
            
            $report->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Report deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'report_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete report',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Generate a report.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request, $id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Access control - only admins and the creator can generate reports
            if (!Auth::user()->hasRole('admin') && $report->created_by !== Auth::id()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to generate this report'
                ], 403);
            }
            
            $validated = $request->validate([
                'parameters' => 'nullable|array',
                'format' => 'nullable|string|in:pdf,csv,xlsx',
            ]);
            
            // Generate the report
            $generation = $this->reportService->generateReport(
                $report, 
                $validated, 
                Auth::id(), 
                false
            );
            
            return response()->json([
                'status' => 'success',
                'message' => 'Report generated successfully',
                'data' => [
                    'generation' => $generation,
                    'download_url' => url("/api/reports/generations/{$generation->id}/download")
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to generate report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'report_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate report',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Download a generated report.
     *
     * @param  int  $id
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function download($id)
    {
        try {
            $generation = ReportGeneration::with('report')->findOrFail($id);
            
            // Access control - only admins, the creator, and users with access to public reports can download
            if (!Auth::user()->hasRole('admin') && 
                $generation->report->created_by !== Auth::id() && 
                !$generation->report->is_public) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to download this report'
                ], 403);
            }
            
            // Check if file exists
            if (!$generation->file_path || !Storage::exists($generation->file_path)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Report file not found'
                ], 404);
            }
            
            // Determine content type based on file format
            $contentType = 'application/octet-stream';
            switch ($generation->file_format) {
                case 'pdf':
                    $contentType = 'application/pdf';
                    break;
                case 'csv':
                    $contentType = 'text/csv';
                    break;
                case 'xlsx':
                    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
            }
            
            // Generate a filename
            $filename = str_replace(' ', '_', strtolower($generation->report->name)) . 
                        '_' . $generation->completed_at->format('Y-m-d') . 
                        '.' . $generation->file_format;
            
            return Storage::download(
                $generation->file_path, 
                $filename, 
                ['Content-Type' => $contentType]
            );
        } catch (\Exception $e) {
            Log::error('Failed to download report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'generation_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download report',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Get report generations for a specific report.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function generations(Request $request, $id)
    {
        try {
            $report = Report::findOrFail($id);
            
            // Access control - only admins and the creator can view generations
            if (!Auth::user()->hasRole('admin') && 
                $report->created_by !== Auth::id() && 
                !$report->is_public) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to view this report\'s generations'
                ], 403);
            }
            
            $query = $report->generations()->with('generator');
            
            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->input('status'));
            }
            
            // Sort options
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            $generations = $query->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $generations,
                'meta' => [
                    'total' => $generations->total(),
                    'per_page' => $generations->perPage(),
                    'current_page' => $generations->currentPage(),
                    'last_page' => $generations->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch report generations: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'report_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch report generations',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Get financial reports data.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function financials(Request $request)
    {
        try {
            // This endpoint requires specific permission
            if (!Auth::user()->hasPermissionTo('view financial reports')) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to view financial reports'
                ], 403);
            }
            
            $parameters = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'payment_type' => 'nullable|string',
                'health_plan_id' => 'nullable|integer|exists:health_plans,id',
                'status' => 'nullable|string',
                'include_summary' => 'nullable|boolean'
            ]);
            
            // Restrict health plan users to only their own data
            if (Auth::user()->hasRole(['health_plan', 'plan_admin'])) {
                $healthPlanId = Auth::user()->entity_id;
                $parameters['health_plan_id'] = $healthPlanId;
            }
            
            // Use the report service to get financial data
            $reportData = $this->reportService->getFinancialReportData($parameters);
            
            return response()->json([
                'status' => 'success',
                'data' => $reportData
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to fetch financial reports: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch financial reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get appointments reports data.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function appointments(Request $request)
    {
        try {
            $parameters = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'status' => 'nullable|string',
                'clinic_id' => 'nullable|integer|exists:clinics,id',
                'professional_id' => 'nullable|integer|exists:professionals,id',
                'health_plan_id' => 'nullable|integer|exists:health_plans,id',
                'state' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:100',
                'include_summary' => 'nullable|boolean'
            ]);
            
            // Restrict health plan users to only their own data
            if (Auth::user()->hasRole(['health_plan', 'plan_admin'])) {
                $healthPlanId = Auth::user()->entity_id;
                $parameters['health_plan_id'] = $healthPlanId;
            }
            
            // Use the report service to get appointments data
            $reportData = $this->reportService->getAppointmentReportData($parameters);
            
            return response()->json([
                'status' => 'success',
                'data' => $reportData
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to fetch appointments reports: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch appointments reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get performance reports data.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function performance(Request $request)
    {
        try {
            $parameters = $request->validate([
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after_or_equal:start_date',
                'professional_id' => 'nullable|integer|exists:professionals,id',
                'clinic_id' => 'nullable|integer|exists:clinics,id',
                'health_plan_id' => 'nullable|integer|exists:health_plans,id',
                'state' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:100'
            ]);
            
            // Restrict health plan users to only their own data
            if (Auth::user()->hasRole(['health_plan', 'plan_admin'])) {
                $healthPlanId = Auth::user()->entity_id;
                $parameters['health_plan_id'] = $healthPlanId;
            }
            
            // Use the report service to get performance data
            $reportData = $this->reportService->getPerformanceReportData($parameters);
            
            return response()->json([
                'status' => 'success',
                'data' => $reportData
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to fetch performance reports: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch performance reports',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export report data to a file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function export(Request $request)
    {
        try {
            $validated = $request->validate([
                'report_type' => 'required|string|in:financial,appointment,performance,custom',
                'parameters' => 'nullable|array',
                'format' => 'required|string|in:pdf,csv,xlsx',
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'save_as_report' => 'boolean'
            ]);
            
            // Restrict health plan users to only their own data in parameters
            if (Auth::user()->hasRole(['health_plan', 'plan_admin'])) {
                $healthPlanId = Auth::user()->entity_id;
                
                if (!isset($validated['parameters'])) {
                    $validated['parameters'] = [];
                }
                
                $validated['parameters']['health_plan_id'] = $healthPlanId;
            }
            
            // Create a temporary report for export
            $report = new Report([
                'name' => $validated['name'],
                'type' => $validated['report_type'],
                'description' => $validated['description'] ?? null,
                'parameters' => $validated['parameters'] ?? [],
                'file_format' => $validated['format'],
                'created_by' => Auth::id(),
                'is_template' => false,
                'is_public' => false,
                'is_scheduled' => false
            ]);
            
            // Decide whether to save the report permanently
            if ($validated['save_as_report'] ?? false) {
                $report->save();
            }
            
            // Generate the report
            $generation = $this->reportService->generateReport(
                $report, 
                $validated['parameters'] ?? [], 
                Auth::id(),
                false
            );
            
            return response()->json([
                'status' => 'success',
                'message' => 'Report exported successfully',
                'data' => [
                    'generation' => $generation,
                    'report' => $validated['save_as_report'] ?? false ? $report : null,
                    'download_url' => url("/api/reports/generations/{$generation->id}/download")
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to export report: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate solicitations report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function solicitations(Request $request): JsonResponse
    {
        try {
            $query = Solicitation::with(['healthPlan', 'patient', 'tuss', 'appointments']);

            // Filtrar por plano de saúde se o usuário for admin do plano
            if (Auth::user()->hasRole('plan_admin')) {
                $query->where('health_plan_id', Auth::user()->entity_id);
            }
            // Filtrar por plano de saúde específico se fornecido
            elseif ($request->has('health_plan_id')) {
                $query->where('health_plan_id', $request->health_plan_id);
            }

            // Filtrar por período
            if ($request->has('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            // Filtrar por status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Agrupar por status
            $byStatus = $query->clone()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get();

            // Agrupar por mês
            $byMonth = $query->clone()
                ->select(
                    DB::raw('DATE_FORMAT(created_at, "%Y-%m") as month'),
                    DB::raw('count(*) as total')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            // Total de solicitações
            $total = $query->clone()->count();

            // Tempo médio de agendamento (entre criação e primeiro agendamento)
            $avgSchedulingTime = $query->clone()
                ->whereHas('appointments')
                ->join('appointments', 'solicitations.id', '=', 'appointments.solicitation_id')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(HOUR, solicitations.created_at, appointments.created_at)) as avg_time'))
                ->first()
                ->avg_time;

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'by_status' => $byStatus,
                    'by_month' => $byMonth,
                    'avg_scheduling_time' => round($avgSchedulingTime, 2),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar relatório de solicitações',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate appointments report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function appointments(Request $request): JsonResponse
    {
        try {
            $query = Appointment::with(['solicitation.healthPlan', 'provider']);

            // Filtrar por plano de saúde se o usuário for admin do plano
            if (Auth::user()->hasRole('plan_admin')) {
                $query->whereHas('solicitation', function ($q) {
                    $q->where('health_plan_id', Auth::user()->entity_id);
                });
            }
            // Filtrar por plano de saúde específico se fornecido
            elseif ($request->has('health_plan_id')) {
                $query->whereHas('solicitation', function ($q) use ($request) {
                    $q->where('health_plan_id', $request->health_plan_id);
                });
            }

            // Filtrar por período
            if ($request->has('start_date')) {
                $query->whereDate('scheduled_date', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->whereDate('scheduled_date', '<=', $request->end_date);
            }

            // Filtrar por status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Agrupar por status
            $byStatus = $query->clone()
                ->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get();

            // Agrupar por mês
            $byMonth = $query->clone()
                ->select(
                    DB::raw('DATE_FORMAT(scheduled_date, "%Y-%m") as month'),
                    DB::raw('count(*) as total')
                )
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            // Agrupar por tipo de provedor
            $byProviderType = $query->clone()
                ->select('provider_type', DB::raw('count(*) as total'))
                ->groupBy('provider_type')
                ->get();

            // Total de agendamentos
            $total = $query->clone()->count();

            return response()->json([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'by_status' => $byStatus,
                    'by_month' => $byMonth,
                    'by_provider_type' => $byProviderType
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar relatório de agendamentos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate providers report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function providers(Request $request): JsonResponse
    {
        try {
            // Profissionais mais agendados
            $topProfessionals = Appointment::where('provider_type', Professional::class)
                ->select(
                    'provider_id',
                    DB::raw('count(*) as total_appointments')
                )
                ->with('provider:id,name')
                ->groupBy('provider_id')
                ->orderByDesc('total_appointments')
                ->limit(10)
                ->get();

            // Clínicas mais agendadas
            $topClinics = Appointment::where('provider_type', Clinic::class)
                ->select(
                    'provider_id',
                    DB::raw('count(*) as total_appointments')
                )
                ->with('provider:id,name')
                ->groupBy('provider_id')
                ->orderByDesc('total_appointments')
                ->limit(10)
                ->get();

            // Taxa de conclusão por provedor
            $completionRates = Appointment::select(
                'provider_type',
                'provider_id',
                DB::raw('count(*) as total_appointments'),
                DB::raw('sum(case when status = "completed" then 1 else 0 end) as completed_appointments')
            )
                ->with('provider:id,name')
                ->groupBy('provider_type', 'provider_id')
                ->having('total_appointments', '>=', 5)
                ->get()
                ->map(function ($item) {
                    $item->completion_rate = round(($item->completed_appointments / $item->total_appointments) * 100, 2);
                    return $item;
                })
                ->sortByDesc('completion_rate')
                ->values()
                ->take(10);

            return response()->json([
                'success' => true,
                'data' => [
                    'top_professionals' => $topProfessionals,
                    'top_clinics' => $topClinics,
                    'top_completion_rates' => $completionRates
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar relatório de prestadores',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate health plans report
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function healthPlans(Request $request): JsonResponse
    {
        try {
            // Se for admin de plano de saúde, retorna apenas dados do seu plano
            if (Auth::user()->hasRole('plan_admin')) {
                $healthPlanId = Auth::user()->entity_id;
                
                $data = [
                    'health_plan' => HealthPlan::find($healthPlanId),
                    'total_solicitations' => Solicitation::where('health_plan_id', $healthPlanId)->count(),
                    'total_appointments' => Appointment::whereHas('solicitation', function ($q) use ($healthPlanId) {
                        $q->where('health_plan_id', $healthPlanId);
                    })->count(),
                    'solicitations_by_status' => Solicitation::where('health_plan_id', $healthPlanId)
                        ->select('status', DB::raw('count(*) as total'))
                        ->groupBy('status')
                        ->get(),
                    'appointments_by_status' => Appointment::whereHas('solicitation', function ($q) use ($healthPlanId) {
                        $q->where('health_plan_id', $healthPlanId);
                    })
                        ->select('status', DB::raw('count(*) as total'))
                        ->groupBy('status')
                        ->get(),
                ];
            } else {
                // Para super admin, retorna dados de todos os planos
                $data = [
                    'total_health_plans' => HealthPlan::count(),
                    'active_health_plans' => HealthPlan::where('status', 'approved')->count(),
                    'top_health_plans' => Solicitation::select(
                        'health_plan_id',
                        DB::raw('count(*) as total_solicitations')
                    )
                        ->with('healthPlan:id,name')
                        ->groupBy('health_plan_id')
                        ->orderByDesc('total_solicitations')
                        ->limit(10)
                        ->get(),
                    'health_plans_by_status' => HealthPlan::select('status', DB::raw('count(*) as total'))
                        ->groupBy('status')
                        ->get(),
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar relatório de planos de saúde',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 