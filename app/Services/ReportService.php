<?php

namespace App\Services;

use App\Models\Report;
use App\Models\ReportGeneration;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Professional;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Exception;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportService
{
    /**
     * Generate a report based on the report configuration.
     *
     * @param Report $report
     * @param array $parameters
     * @param int $userId
     * @param bool $isScheduled
     * @return ReportGeneration
     */
    public function generateReport(Report $report, array $parameters = [], int $userId, bool $isScheduled = false): ReportGeneration
    {
        try {
            // Generate the file path first
            $format = $parameters['format'] ?? $report->file_format ?? 'pdf';
            $fileName = $this->generateFileName($report, null);
            
            // Define storage paths
            $relativePath = "reports/{$report->type}/{$fileName}.{$format}";
            $storagePath = "public/{$relativePath}";
            $fullPath = storage_path("app/{$storagePath}");
            
            \Log::info('Generating report', [
                'report_id' => $report->id,
                'format' => $format,
                'fileName' => $fileName,
                'storagePath' => $storagePath,
                'fullPath' => $fullPath
            ]);

            // Create a new report generation record
            $generation = ReportGeneration::create([
                'report_id' => $report->id,
                'file_format' => $format,
                'file_path' => $storagePath,
                'parameters' => array_merge($report->parameters ?? [], $parameters),
                'generated_by' => $userId,
                'started_at' => now(),
                'status' => 'processing',
                'was_scheduled' => $isScheduled,
            ]);

            // Generate report data
            $reportData = $this->getReportData($report, $generation->parameters);
            
            \Log::info('Report data generated', [
                'report_id' => $report->id,
                'generation_id' => $generation->id,
                'data_count' => is_array($reportData) ? count($reportData) : 0
            ]);
            
            // Create the file
            $this->createReportFile($report, $generation, $reportData);
            
            // Verify file exists
            if (!Storage::exists($generation->file_path)) {
                \Log::error('Report file not found after generation', [
                    'report_id' => $report->id,
                    'generation_id' => $generation->id,
                    'file_path' => $generation->file_path,
                    'full_path' => $fullPath
                ]);
                throw new Exception("Report file not found after generation");
            }
            
            // Update the generation with file info
            $fileSize = Storage::size($generation->file_path);
            $generation->markAsCompleted(is_array($reportData) ? count($reportData) : 0, $fileSize);
            
            \Log::info('Report generated successfully', [
                'report_id' => $report->id,
                'generation_id' => $generation->id,
                'file_path' => $generation->file_path,
                'file_size' => $fileSize
            ]);
            
            return $generation;
        } catch (Exception $e) {
            \Log::error('Failed to generate report', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            if (isset($generation)) {
                $generation->markAsFailed($e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Get the report data based on report type and parameters.
     *
     * @param Report $report
     * @param array $parameters
     * @return array
     */
    public function getReportData(Report $report, array $parameters): array
    {
        switch ($report->type) {
            case 'financial':
                return $this->getFinancialReportData($parameters);
            case 'appointment':
                return $this->getAppointmentReportData($parameters);
            case 'performance':
                return $this->getPerformanceReportData($parameters);
            case 'custom':
                // For custom reports, the parameters should include a query or specific logic
                return $this->getCustomReportData($parameters);
            default:
                throw new Exception("Unsupported report type: {$report->type}");
        }
    }

    /**
     * Create the report file.
     *
     * @param Report $report
     * @param ReportGeneration $generation
     * @param array $data
     * @return void
     */
    public function createReportFile(Report $report, ReportGeneration $generation, array $data): void
    {
        \Log::info('Creating report file', [
            'report_id' => $report->id,
            'generation_id' => $generation->id,
            'format' => $generation->file_format,
            'file_path' => $generation->file_path
        ]);

        $format = $generation->file_format;
        
        try {
            // Based on format, generate the appropriate file
            switch ($format) {
                case 'csv':
                    $this->generateCsvFile($generation->file_path, $data);
                    break;
                case 'xlsx':
                    $this->generateExcelFile($generation->file_path, $data);
                    break;
                case 'pdf':
                default:
                    $this->generatePdfFile($generation->file_path, $data, $report);
                    break;
            }
            
            if (!Storage::exists($generation->file_path)) {
                throw new Exception("File not created successfully");
            }
        } catch (Exception $e) {
            \Log::error('Failed to create report file', [
                'report_id' => $report->id,
                'generation_id' => $generation->id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Generate a CSV file from the data.
     *
     * @param string $filePath
     * @param array $data
     * @return void
     */
    public function generateCsvFile(string $filePath, array $data): void
    {
        \Log::info('Starting CSV generation', [
            'file_path' => $filePath
        ]);

        if (empty($data)) {
            $data[] = [
                'Data' => date('d/m/Y H:i:s'),
                'Mensagem' => 'Nenhum dado encontrado para o período selecionado'
            ];
        }

        try {
            // Ensure the directory exists
            $directory = dirname(storage_path("app/{$filePath}"));
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
                \Log::info('Created directory', ['directory' => $directory]);
            }

            // Generate CSV content
            $output = fopen('php://temp', 'r+');
            
            // Write headers
            fputcsv($output, array_keys($data[0]), ';');
            
            // Write data
            foreach ($data as $row) {
                fputcsv($output, $row, ';');
            }
            
            // Get content
            rewind($output);
            $content = stream_get_contents($output);
            fclose($output);
            
            // Store the file
            $stored = Storage::put($filePath, $content);
            
            if (!$stored) {
                throw new Exception("Failed to store CSV file");
            }

            \Log::info('CSV file generated successfully', [
                'file_path' => $filePath,
                'size' => Storage::size($filePath)
            ]);
            
            // Set permissions if the file exists
            $fullPath = storage_path("app/{$filePath}");
            if (file_exists($fullPath)) {
                chmod($fullPath, 0644);
            }
        } catch (Exception $e) {
            \Log::error('Failed to generate CSV file', [
                'file_path' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Generate an Excel file from the data.
     * 
     * This is a placeholder - actual implementation would use a library like PhpSpreadsheet.
     *
     * @param string $filePath
     * @param array $data
     * @return void
     */
    public function generateExcelFile(string $filePath, array $data): void
    {
        // Placeholder - would use PhpSpreadsheet or similar library
        // For now, just create a CSV as a fallback
        $this->generateCsvFile($filePath, $data);
    }

    /**
     * Generate a PDF file from the data.
     * 
     * This is a placeholder - actual implementation would use a library like DOMPDF.
     *
     * @param string $filePath
     * @param array $data
     * @param Report $report
     * @return void
     */
    public function generatePdfFile(string $filePath, array $data, Report $report): void
    {
        $hasSummary = isset($data['summary']) && isset($data['transactions']);
        $reportData = $hasSummary ? $data['transactions'] : $data;

        $view = view('reports.pdf', [
            'report' => $report,
            'data' => $reportData,
            'summary' => $hasSummary ? $data['summary'] : null,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ])->render();

        $pdf = PDF::loadHTML($view);
        $pdf->setPaper('A4', 'portrait');
        
        // Save the PDF to storage
        Storage::put($filePath, $pdf->output());
    }

    /**
     * Get default headers based on report type.
     *
     * @param string $reportType
     * @return array
     */
    private function getDefaultHeaders(string $reportType): array
    {
        switch ($reportType) {
            case 'financial':
                return [
                    'ID',
                    'Reference',
                    'Type',
                    'Date',
                    'Amount',
                    'Discount',
                    'Gloss',
                    'Total',
                    'Status',
                    'Payment Method',
                    'Paid Date',
                    'Health Plan'
                ];
            case 'appointment':
                return [
                    'id',
                    'scheduled_date',
                    'patient_name',
                    'professional_name',
                    'clinic_name',
                    'procedure_name',
                    'status',
                    'health_plan_name'
                ];
            case 'performance':
                return [
                    'id',
                    'name',
                    'specialty',
                    'total_appointments',
                    'attendance_rate',
                    'patient_satisfaction',
                    'efficiency',
                    'overall_score'
                ];
            default:
                return [];
        }
    }

    /**
     * Generate a filename for the report file.
     *
     * @param Report $report
     * @param ReportGeneration|null $generation
     * @return string
     */
    public function generateFileName(Report $report, ?ReportGeneration $generation = null): string
    {
        $datePart = now()->format('Y-m-d_His');
        $reportName = str_replace(' ', '_', strtolower($report->name));
        $generationId = $generation ? $generation->id : uniqid();
        
        return "{$reportName}_{$generationId}_{$datePart}";
    }

    /**
     * Get the file size in human-readable format.
     *
     * @param string $filePath
     * @return string
     */
    public function getFileSize(string $filePath): string
    {
        $fullPath = storage_path('app/' . $filePath);
        
        if (!file_exists($fullPath)) {
            return 'Unknown';
        }
        
        $size = filesize($fullPath);
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;
        
        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }
        
        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    /**
     * Get financial report data.
     *
     * @param array $parameters
     * @return array
     */
    public function getFinancialReportData(array $parameters): array
    {
        // Parse date filters
        $startDate = isset($parameters['start_date']) 
            ? Carbon::parse($parameters['start_date']) 
            : Carbon::now()->subMonth();
            
        $endDate = isset($parameters['end_date']) 
            ? Carbon::parse($parameters['end_date']) 
            : Carbon::now();
        
        $query = Payment::with([
                'payable',
                'payable.healthPlan',
                'payable.patient',
                'payable.professional',
                'payable.clinic',
                'payable.procedure'
            ])
            ->whereBetween('created_at', [$startDate, $endDate]);
        
        // Apply type filter if provided
        if (isset($parameters['payment_type'])) {
            $query->where('payment_type', $parameters['payment_type']);
        }
        
        // Apply status filter if provided
        if (isset($parameters['status'])) {
            $query->where('status', $parameters['status']);
        }
        
        // Apply health plan filter if provided
        if (isset($parameters['health_plan_id'])) {
            $query->whereHas('payable', function ($q) use ($parameters) {
                $q->where('health_plan_id', $parameters['health_plan_id']);
            });
        }
        
        $payments = $query->get();
        
        // Transform into report data
        $reportData = [];
        foreach ($payments as $payment) {
            $payable = $payment->payable;
            
            // Get related data safely
            $healthPlan = $payable && method_exists($payable, 'healthPlan') && $payable->healthPlan 
                ? $payable->healthPlan : null;
            $patient = $payable && method_exists($payable, 'patient') && $payable->patient 
                ? $payable->patient : null;
            $professional = $payable && method_exists($payable, 'professional') && $payable->professional 
                ? $payable->professional : null;
            $clinic = $payable && method_exists($payable, 'clinic') && $payable->clinic 
                ? $payable->clinic : null;
            $procedure = $payable && method_exists($payable, 'procedure') && $payable->procedure 
                ? $payable->procedure : null;
            
            // Get appointment date if available
            $appointmentDate = $payable && method_exists($payable, 'scheduled_at') && $payable->scheduled_at 
                ? Carbon::parse($payable->scheduled_at)->format('d/m/Y H:i') 
                : null;
            
            $reportData[] = [
                'ID Pagamento' => $payment->id,
                'Data Pagamento' => $payment->created_at->format('d/m/Y'),
                'Hora Pagamento' => $payment->created_at->format('H:i'),
                'ID Paciente' => $patient ? $patient->id : 'N/A',
                'Nome Paciente' => $patient ? $patient->name : 'N/A',
                'CPF Paciente' => $patient ? $patient->cpf : 'N/A',
                'ID Profissional' => $professional ? $professional->id : 'N/A',
                'Nome Profissional' => $professional ? $professional->name : 'N/A',
                'Especialidade' => $professional ? $professional->specialty : 'N/A',
                'ID Clinica' => $clinic ? $clinic->id : 'N/A',
                'Nome Clinica' => $clinic ? $clinic->name : 'N/A',
                'ID Convenio' => $healthPlan ? $healthPlan->id : 'N/A',
                'Nome Convenio' => $healthPlan ? $healthPlan->name : 'Particular',
                'Codigo Procedimento' => $procedure ? $procedure->code : 'N/A',
                'Nome Procedimento' => $procedure ? $procedure->name : ($payable->procedure_name ?? 'N/A'),
                'Data Agendamento' => $appointmentDate ?: 'N/A',
                'Tipo Pagamento' => ucfirst($payment->payment_type),
                'Forma Pagamento' => $payment->payment_method ? ucfirst($payment->payment_method) : 'N/A',
                'Valor Base' => number_format($payment->amount, 2, ',', '.'),
                'Desconto' => number_format($payment->discount_amount, 2, ',', '.'),
                'Glosa' => number_format($payment->gloss_amount, 2, ',', '.'),
                'Valor Total' => number_format($payment->total_amount, 2, ',', '.'),
                'Status' => ucfirst($payment->status),
                'Data Compensacao' => $payment->paid_at ? $payment->paid_at->format('d/m/Y') : 'N/A',
                'Referencia' => $payment->reference_id ?: 'N/A',
                'Observacoes' => $payment->notes ?: ''
            ];
        }

        return $reportData;
    }

    /**
     * Get appointment report data.
     *
     * @param array $parameters
     * @return array
     */
    public function getAppointmentReportData(array $parameters): array
    {
        // Parse date filters
        $startDate = isset($parameters['start_date']) 
            ? Carbon::parse($parameters['start_date']) 
            : Carbon::now()->subMonth();
            
        $endDate = isset($parameters['end_date']) 
            ? Carbon::parse($parameters['end_date']) 
            : Carbon::now();
        
        $query = Appointment::with(['professional', 'patient', 'clinic', 'healthPlan'])
            ->whereBetween('scheduled_at', [$startDate, $endDate]);
        
        // Apply status filter if provided
        if (isset($parameters['status']) && $parameters['status']) {
            $query->where('status', $parameters['status']);
        }
        
        // Apply clinic filter if provided
        if (isset($parameters['clinic_id']) && $parameters['clinic_id']) {
            $query->where('clinic_id', $parameters['clinic_id']);
        }
        
        // Apply professional filter if provided
        if (isset($parameters['professional_id']) && $parameters['professional_id']) {
            $query->where('professional_id', $parameters['professional_id']);
        }
        
        // Apply health plan filter if provided
        if (isset($parameters['health_plan_id']) && $parameters['health_plan_id']) {
            $query->where('health_plan_id', $parameters['health_plan_id']);
        }
        
        // Apply location filters if provided
        if (isset($parameters['state']) && $parameters['state']) {
            $query->whereHas('clinic', function ($q) use ($parameters) {
                $q->where('state', $parameters['state']);
            });
        }
        
        if (isset($parameters['city']) && $parameters['city']) {
            $query->whereHas('clinic', function ($q) use ($parameters) {
                $q->where('city', $parameters['city']);
            });
        }
        
        $appointments = $query->get();
        
        // Transform into report data
        $appointmentData = [];
        foreach ($appointments as $appointment) {
            $appointmentData[] = [
                'id' => $appointment->id,
                'scheduled_date' => $appointment->scheduled_at->format('Y-m-d H:i:s'),
                'patient_name' => $appointment->patient->name ?? 'N/A',
                'professional_name' => $appointment->professional->name ?? 'N/A',
                'clinic_name' => $appointment->clinic->name ?? 'N/A',
                'procedure_name' => $appointment->procedure_name ?? 'N/A',
                'status' => $appointment->status,
                'health_plan_name' => $appointment->healthPlan->name ?? 'Particular',
            ];
        }
        
        // Add summary data if requested
        if (isset($parameters['include_summary']) && $parameters['include_summary']) {
            $statusCounts = $appointments->groupBy('status')
                ->map(function ($group) {
                    return $group->count();
                })->toArray();
            
            $summary = [
                'total_appointments' => $appointments->count(),
                'confirmed_appointments' => $statusCounts['confirmed'] ?? 0,
                'completed_appointments' => $statusCounts['completed'] ?? 0,
                'cancelled_appointments' => $statusCounts['cancelled'] ?? 0,
                'no_show_appointments' => $statusCounts['no_show'] ?? 0,
                'attendance_rate' => $appointments->count() > 0 
                    ? ($statusCounts['completed'] ?? 0) / $appointments->count()
                    : 0
            ];
            
            return [
                'summary' => $summary,
                'appointments' => $appointmentData
            ];
        }
        
        return $appointmentData;
    }

    /**
     * Get performance report data.
     *
     * @param array $parameters
     * @return array
     */
    public function getPerformanceReportData(array $parameters): array
    {
        // Parse date filters
        $startDate = isset($parameters['start_date']) 
            ? Carbon::parse($parameters['start_date']) 
            : Carbon::now()->subMonth();
            
        $endDate = isset($parameters['end_date']) 
            ? Carbon::parse($parameters['end_date']) 
            : Carbon::now();
        
        // Base query for professionals
        $professionalQuery = Professional::with(['appointments' => function ($query) use ($startDate, $endDate, $parameters) {
            $query->whereBetween('scheduled_at', [$startDate, $endDate]);
            
            // Filter appointments by health plan if provided
            if (isset($parameters['health_plan_id']) && $parameters['health_plan_id']) {
                $query->where('health_plan_id', $parameters['health_plan_id']);
            }
        }, 'clinics']);
        
        // Apply professional filter if provided
        if (isset($parameters['professional_id']) && $parameters['professional_id']) {
            $professionalQuery->where('id', $parameters['professional_id']);
        }
        
        // Apply clinic filter if provided
        if (isset($parameters['clinic_id']) && $parameters['clinic_id']) {
            $professionalQuery->whereHas('clinics', function ($q) use ($parameters) {
                $q->where('clinics.id', $parameters['clinic_id']);
            });
        }
        
        // Apply location filters if provided
        if (isset($parameters['state']) && $parameters['state']) {
            $professionalQuery->whereHas('clinics', function ($q) use ($parameters) {
                $q->where('state', $parameters['state']);
            });
        }
        
        if (isset($parameters['city']) && $parameters['city']) {
            $professionalQuery->whereHas('clinics', function ($q) use ($parameters) {
                $q->where('city', $parameters['city']);
            });
        }
        
        // Only include professionals that have appointments with the specified health plan
        if (isset($parameters['health_plan_id']) && $parameters['health_plan_id']) {
            $professionalQuery->whereHas('appointments', function ($q) use ($parameters) {
                $q->where('health_plan_id', $parameters['health_plan_id']);
            });
        }
        
        $professionals = $professionalQuery->get();
        
        // Initialize overall performance metrics
        $totalAppointments = 0;
        $totalCompletedAppointments = 0;
        $totalMissedAppointments = 0;
        $totalSatisfactionScore = 0;
        $totalSatisfactionCount = 0;
        
        // Transform into report data
        $professionalsData = [];
        
        foreach ($professionals as $professional) {
            $appointments = $professional->appointments;
            
            // Skip professionals with no appointments in the period if we're generating detailed report
            if ($appointments->isEmpty() && isset($parameters['professional_id'])) {
                continue;
            }
            
            $totalProfessionalAppointments = $appointments->count();
            $completedAppointments = $appointments->where('status', 'completed')->count();
            $missedAppointments = $appointments->where('status', 'no_show')->count();
            $cancelledAppointments = $appointments->where('status', 'cancelled')->count();
            
            // Calculate satisfaction score (assuming there's a reviews or feedback relation)
            $satisfactionScore = 0;
            $satisfactionCount = 0;
            
            if (method_exists($professional, 'reviews')) {
                $reviews = $professional->reviews()
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->get();
                
                $satisfactionCount = $reviews->count();
                if ($satisfactionCount > 0) {
                    $satisfactionScore = $reviews->avg('rating');
                    $totalSatisfactionScore += $reviews->sum('rating');
                    $totalSatisfactionCount += $satisfactionCount;
                }
            }
            
            // Calculate attendance rate
            $attendanceRate = $totalProfessionalAppointments > 0 
                ? ($completedAppointments / $totalProfessionalAppointments) 
                : 0;
                
            // Calculate efficiency (completed appointments per scheduled hour)
            // This is a simplified metric - in a real system you'd use actual duration data
            $efficiencyPercentage = $totalProfessionalAppointments > 0 
                ? (($completedAppointments / $totalProfessionalAppointments) * 100) 
                : 0;
            
            // Calculate overall score (weighted composite of metrics)
            $overallScore = (
                ($attendanceRate * 4) + 
                ($satisfactionScore / 5 * 4) + 
                (min($efficiencyPercentage / 100, 1) * 2)
            ) / 10 * 10; // Scale to 0-10
            
            // Accumulate totals for overall performance
            $totalAppointments += $totalProfessionalAppointments;
            $totalCompletedAppointments += $completedAppointments;
            $totalMissedAppointments += $missedAppointments;
                
            $professionalsData[] = [
                'id' => $professional->id,
                'name' => $professional->name,
                'specialty' => $professional->specialty ?? 'N/A',
                'total_appointments' => $totalProfessionalAppointments,
                'attendance_rate' => $attendanceRate,
                'patient_satisfaction' => $satisfactionScore > 0 ? $satisfactionScore : 3.5, // Default if no reviews
                'efficiency' => $efficiencyPercentage,
                'overall_score' => $overallScore
            ];
        }
        
        // Calculate overall performance metrics
        $overallAttendanceRate = $totalAppointments > 0 
            ? ($totalCompletedAppointments / $totalAppointments) 
            : 0;
            
        $overallSatisfaction = $totalSatisfactionCount > 0 
            ? ($totalSatisfactionScore / $totalSatisfactionCount) 
            : 3.5; // Default if no reviews
            
        $overallEfficiency = $totalAppointments > 0 
            ? (($totalCompletedAppointments / $totalAppointments) * 100) 
            : 0;
            
        $overallScore = (
            ($overallAttendanceRate * 4) + 
            ($overallSatisfaction / 5 * 4) + 
            (min($overallEfficiency / 100, 1) * 2)
        ) / 10 * 10; // Scale to 0-10
        
        // Return the performance report data
        return [
            'overall_performance' => [
                'score' => $overallScore,
                'attendance_rate' => $overallAttendanceRate,
                'patient_satisfaction' => $overallSatisfaction,
                'efficiency' => $overallEfficiency
            ],
            'professionals' => $professionalsData
        ];
    }

    /**
     * Get custom report data.
     *
     * @param array $parameters
     * @return array
     */
    public function getCustomReportData(array $parameters): array
    {
        // For custom reports, the parameters should specify the query or specific logic
        if (!isset($parameters['query_type'])) {
            throw new Exception("Custom reports require a query_type parameter");
        }
        
        // Execute different queries based on the query_type
        switch ($parameters['query_type']) {
            case 'patient_appointments_count':
                return $this->getPatientAppointmentsCountData($parameters);
            case 'revenue_by_clinic':
                return $this->getRevenueByClinicData($parameters);
            case 'professional_availability':
                return $this->getProfessionalAvailabilityData($parameters);
            default:
                throw new Exception("Unsupported custom query type: {$parameters['query_type']}");
        }
    }

    /**
     * Get patient appointments count data.
     *
     * @param array $parameters
     * @return array
     */
    public function getPatientAppointmentsCountData(array $parameters): array
    {
        // Parse date filters
        $startDate = isset($parameters['start_date']) 
            ? Carbon::parse($parameters['start_date']) 
            : Carbon::now()->subMonth();
            
        $endDate = isset($parameters['end_date']) 
            ? Carbon::parse($parameters['end_date']) 
            : Carbon::now();
        
        $query = DB::table('appointments')
            ->join('patients', 'appointments.patient_id', '=', 'patients.id')
            ->select('patients.id', 'patients.name', DB::raw('COUNT(appointments.id) as appointment_count'))
            ->whereBetween('appointments.scheduled_at', [$startDate, $endDate]);
            
        // Filter by health plan if provided
        if (isset($parameters['health_plan_id']) && $parameters['health_plan_id']) {
            $query->where('appointments.health_plan_id', $parameters['health_plan_id']);
        }
        
        $results = $query->groupBy('patients.id', 'patients.name')
            ->orderByDesc('appointment_count')
            ->limit(isset($parameters['limit']) ? $parameters['limit'] : 100)
            ->get();
        
        $reportData = [];
        foreach ($results as $row) {
            $reportData[] = [
                'Patient ID' => $row->id,
                'Patient Name' => $row->name,
                'Appointment Count' => $row->appointment_count,
            ];
        }
        
        return $reportData;
    }

    /**
     * Example custom query: Get revenue by clinic.
     *
     * @param array $parameters
     * @return array
     */
    public function getRevenueByClinicData(array $parameters): array
    {
        // Parse date filters
        $startDate = isset($parameters['start_date']) 
            ? Carbon::parse($parameters['start_date']) 
            : Carbon::now()->subMonth();
            
        $endDate = isset($parameters['end_date']) 
            ? Carbon::parse($parameters['end_date']) 
            : Carbon::now();
        
        $query = DB::table('payments')
            ->join('appointments', function ($join) {
                $join->on('payments.payable_id', '=', 'appointments.id')
                    ->where('payments.payable_type', '=', 'App\\Models\\Appointment');
            })
            ->join('clinics', 'appointments.clinic_id', '=', 'clinics.id')
            ->select('clinics.id', 'clinics.name', DB::raw('SUM(payments.total_amount) as total_revenue'))
            ->whereBetween('payments.created_at', [$startDate, $endDate])
            ->where('payments.status', 'completed');
            
        // Filter by health plan if provided
        if (isset($parameters['health_plan_id']) && $parameters['health_plan_id']) {
            $query->where('appointments.health_plan_id', $parameters['health_plan_id']);
        }
        
        $results = $query->groupBy('clinics.id', 'clinics.name')
            ->orderByDesc('total_revenue')
            ->get();
        
        $reportData = [];
        foreach ($results as $row) {
            $reportData[] = [
                'Clinic ID' => $row->id,
                'Clinic Name' => $row->name,
                'Total Revenue' => number_format($row->total_revenue, 2),
            ];
        }
        
        return $reportData;
    }

    /**
     * Example custom query: Get professional availability.
     *
     * @param array $parameters
     * @return array
     */
    public function getProfessionalAvailabilityData(array $parameters): array
    {
        $professionalQuery = Professional::query();
        
        // Only include professionals that have appointments with the specified health plan
        if (isset($parameters['health_plan_id']) && $parameters['health_plan_id']) {
            $professionalQuery->whereHas('appointments', function ($q) use ($parameters) {
                $q->where('health_plan_id', $parameters['health_plan_id']);
            });
        }
        
        $professionals = $professionalQuery->get();
        
        $reportData = [];
        foreach ($professionals as $professional) {
            // In a real implementation, we would calculate availability based on schedule
            $reportData[] = [
                'Professional ID' => $professional->id,
                'Name' => $professional->name,
                'Availability Status' => rand(0, 1) ? 'Available' : 'Busy',
                'Next Available Slot' => now()->addHours(rand(1, 48))->format('Y-m-d H:i'),
                'Weekly Availability Hours' => rand(20, 40),
            ];
        }
        
        return $reportData;
    }
} 