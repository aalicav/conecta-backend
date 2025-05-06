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

class ReportController extends Controller
{
    protected $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->middleware('auth:api');
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
            
            // Access control - non-admins can only see their own reports and public reports
            if (!Auth::user()->hasRole('admin')) {
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
                'state' => 'nullable|string|max:2',
                'city' => 'nullable|string|max:100'
            ]);
            
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
} 