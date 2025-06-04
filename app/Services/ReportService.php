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
        // Generate the file path first
        $format = $parameters['format'] ?? $report->file_format ?? 'pdf';
        $fileName = $this->generateFileName($report, null);
        $filePath = "reports/{$report->type}/{$fileName}.{$format}";

        // Create a new report generation record with the file path
        $generation = ReportGeneration::create([
            'report_id' => $report->id,
            'file_format' => $format,
            'file_path' => $filePath,
            'parameters' => array_merge($report->parameters ?? [], $parameters),
            'generated_by' => $userId,
            'started_at' => now(),
            'status' => 'processing',
            'was_scheduled' => $isScheduled,
        ]);

        try {
            // Generate report based on type
            $reportData = $this->getReportData($report, $generation->parameters);
            
            // Create the file
            $this->createReportFile($report, $generation, $reportData);
            
            // Update the generation with file info
            $fileSize = $this->getFileSize($filePath);
            $generation->markAsCompleted(count($reportData), $fileSize);
            
            // If the report is set to be sent to recipients and has recipients defined
            if (($report->recipients && count($report->recipients)) || 
                ($parameters['recipients'] && count($parameters['recipients']))) {
                // Would trigger a job to send the report via email here
                // $this->sendReportToRecipients($generation);
            }
            
            return $generation;
        } catch (Exception $e) {
            $generation->markAsFailed($e->getMessage());
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
        $format = $generation->file_format;
        
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
        if (empty($data)) {
            // Create an empty CSV with a default header
            Storage::put($filePath, "No data available\n");
            return;
        }
        
        // Open a temporary file
        $handle = fopen('php://temp', 'w+');
        
        // Add headers (keys from the first data item)
        $headers = array_keys($data[0]);
        fputcsv($handle, $headers);
        
        // Add data rows
        foreach ($data as $row) {
            // Ensure all rows have the same keys as the headers
            $rowData = array_map(function($header) use ($row) {
                return $row[$header] ?? '';
            }, $headers);
            fputcsv($handle, $rowData);
        }
        
        // Get the contents of the temporary file
        rewind($handle);
        $csvContent = stream_get_contents($handle);
        fclose($handle);
        
        // Store the file
        Storage::put($filePath, $csvContent);
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
        // Placeholder - would use DOMPDF or similar library
        $htmlContent = '<html><head><title>' . $report->name . '</title></head><body>';
        $htmlContent .= '<h1>' . $report->name . '</h1>';
        $htmlContent .= '<p>Generated on: ' . now()->format('Y-m-d H:i:s') . '</p>';
        
        if (!empty($data)) {
            $htmlContent .= '<table border="1" cellpadding="5">';
            
            // Get headers from the first data item if it exists
            $headers = array_keys($data[0]);
            
            // Headers
            $htmlContent .= '<tr>';
            foreach ($headers as $header) {
                $htmlContent .= '<th>' . htmlspecialchars($header) . '</th>';
            }
            $htmlContent .= '</tr>';
            
            // Data rows
            foreach ($data as $row) {
                $htmlContent .= '<tr>';
                foreach ($row as $cell) {
                    $htmlContent .= '<td>' . htmlspecialchars($cell) . '</td>';
                }
                $htmlContent .= '</tr>';
            }
            
            $htmlContent .= '</table>';
        } else {
            // If data is empty, show a message and create a default table structure
            $htmlContent .= '<p>No data available for this report.</p>';
            
            // Get default headers based on report type
            $headers = $this->getDefaultHeaders($report->type);
            
            if (!empty($headers)) {
                $htmlContent .= '<table border="1" cellpadding="5">';
                $htmlContent .= '<tr>';
                foreach ($headers as $header) {
                    $htmlContent .= '<th>' . htmlspecialchars($header) . '</th>';
                }
                $htmlContent .= '</tr>';
                $htmlContent .= '<tr>';
                foreach ($headers as $header) {
                    $htmlContent .= '<td>-</td>';
                }
                $htmlContent .= '</tr>';
                $htmlContent .= '</table>';
            }
        }
        
        $htmlContent .= '</body></html>';
        
        // In a real implementation, we would convert HTML to PDF using a library
        Storage::put($filePath, $htmlContent);
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
        if (!Storage::exists($filePath)) {
            return 'Unknown';
        }
        
        $size = Storage::size($filePath);
        
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
        
        $query = Payment::with(['payable'])
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
            $healthPlanName = '';
            
            // Try to get health plan name if available
            if ($payment->payable && 
                method_exists($payment->payable, 'healthPlan') && 
                $payment->payable->healthPlan) {
                $healthPlanName = $payment->payable->healthPlan->name;
            }
            
            $reportData[] = [
                'ID' => $payment->id,
                'Reference' => $payment->reference_id,
                'Type' => $payment->payment_type,
                'Date' => $payment->created_at->format('Y-m-d'),
                'Amount' => number_format($payment->amount, 2),
                'Discount' => number_format($payment->discount_amount, 2),
                'Gloss' => number_format($payment->gloss_amount, 2),
                'Total' => number_format($payment->total_amount, 2),
                'Status' => $payment->status,
                'Payment Method' => $payment->payment_method ?? 'N/A',
                'Paid Date' => $payment->paid_at ? $payment->paid_at->format('Y-m-d') : 'N/A',
                'Health Plan' => $healthPlanName,
            ];
        }
        
        // Add summary data if requested
        if (isset($parameters['include_summary']) && $parameters['include_summary']) {
            $totalAmount = $payments->sum('amount');
            $totalDiscount = $payments->sum('discount_amount');
            $totalGloss = $payments->sum('gloss_amount');
            $totalFinal = $payments->sum('total_amount');
            
            $summary = [
                'total_revenue' => $totalAmount,
                'total_received' => $payments->where('status', 'paid')->sum('total_amount'),
                'total_pending' => $payments->where('status', 'pending')->sum('total_amount'),
                'total_gloss' => $totalGloss
            ];
            
            return [
                'summary' => $summary,
                'transactions' => $reportData
            ];
        }
        
        // If no data was found, return an array with the expected structure
        if (empty($reportData)) {
            return [[
                'ID' => '',
                'Reference' => '',
                'Type' => '',
                'Date' => '',
                'Amount' => '',
                'Discount' => '',
                'Gloss' => '',
                'Total' => '',
                'Status' => '',
                'Payment Method' => '',
                'Paid Date' => '',
                'Health Plan' => '',
            ]];
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