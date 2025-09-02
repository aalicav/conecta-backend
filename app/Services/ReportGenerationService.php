<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use TCPDF;
use League\Csv\Writer;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Common\Entity\Row;

class ReportGenerationService
{
    /**
     * Generate report based on type and format
     */
    public function generateReport(string $type, array $filters, string $format = 'pdf')
    {
        \Log::info('ReportGenerationService: Starting report generation', [
            'type' => $type,
            'format' => $format,
            'filters' => $filters
        ]);

        try {
            $data = $this->getReportData($type, $filters);
            
            \Log::info('ReportGenerationService: Data retrieved successfully', [
                'type' => $type,
                'data_count' => is_array($data) ? count($data) : (is_object($data) ? $data->count() : 0)
            ]);

            $filePath = $this->exportReport($data, $type, $format);
            
            \Log::info('ReportGenerationService: Report exported successfully', [
                'type' => $type,
                'format' => $format,
                'file_path' => $filePath
            ]);

            return $filePath;
        } catch (\Exception $e) {
            \Log::error('ReportGenerationService: Error generating report', [
                'type' => $type,
                'format' => $format,
                'filters' => $filters,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Get report data based on type and filters
     */
    private function getReportData(string $type, array $filters)
    {
        switch ($type) {
            case 'appointment':
                return $this->getAppointmentsReport($filters);
            case 'professionals':
                return $this->getProfessionalsReport($filters);
            case 'clinics':
                return $this->getClinicsReport($filters);
            case 'financial':
                return $this->getFinancialReport($filters);
            case 'billing':
                return $this->getBillingReport($filters);
            default:
                throw new \Exception("Invalid report type: {$type}");
        }
    }

    /**
     * Get appointments report data
     */
    private function getAppointmentsReport(array $filters)
    {
        try {
            $query = DB::table('appointments')
                ->join('solicitations', 'appointments.solicitation_id', '=', 'solicitations.id')
                ->join('patients', 'solicitations.patient_id', '=', 'patients.id')
                ->leftJoin('professionals', function($join) {
                    $join->on('appointments.provider_id', '=', 'professionals.id')
                        ->where('appointments.provider_type', '=', 'App\\Models\\Professional');
                })
                ->leftJoin('clinics', function($join) {
                    $join->on('appointments.provider_id', '=', 'clinics.id')
                        ->where('appointments.provider_type', '=', 'App\\Models\\Clinic');
                })
                ->leftJoin('health_plans', 'solicitations.health_plan_id', '=', 'health_plans.id')
                ->leftJoin('addresses', 'appointments.address_id', '=', 'addresses.id');

            // Apply filters only if they have actual values
            if (isset($filters['start_date']) && $filters['start_date']) {
                $query->whereDate('appointments.scheduled_date', '>=', $filters['start_date']);
            }
            if (isset($filters['end_date']) && $filters['end_date']) {
                $query->whereDate('appointments.scheduled_date', '<=', $filters['end_date']);
            }
            if (isset($filters['status']) && $filters['status'] !== '') {
                $query->where('appointments.status', $filters['status']);
            }
            if (isset($filters['city']) && $filters['city']) {
                $query->where(function($q) use ($filters) {
                    $q->where('professionals.city', $filters['city'])
                      ->orWhere('clinics.city', $filters['city']);
                });
            }
            if (isset($filters['state']) && $filters['state']) {
                $query->where(function($q) use ($filters) {
                    $q->where('professionals.state', $filters['state'])
                      ->orWhere('clinics.state', $filters['state']);
                });
            }
            if (isset($filters['health_plan_id']) && $filters['health_plan_id']) {
                $query->where('solicitations.health_plan_id', $filters['health_plan_id']);
            }
            if (isset($filters['professional_id']) && $filters['professional_id']) {
                $query->where('appointments.provider_id', $filters['professional_id'])
                      ->where('appointments.provider_type', 'App\\Models\\Professional');
            }
            if (isset($filters['clinic_id']) && $filters['clinic_id']) {
                $query->where('appointments.provider_id', $filters['clinic_id'])
                      ->where('appointments.provider_type', 'App\\Models\\Clinic');
            }

            // Get data for graphs
            $appointments = $query->select([
                'appointments.id',
                'appointments.scheduled_date',
                'appointments.status',
                'appointments.patient_attended',
                'patients.name as patient_name',
                'patients.cpf as patient_document',
                DB::raw('COALESCE(professionals.name, clinics.name) as provider_name'),
                'health_plans.name as health_plan_name',
                'addresses.street',
                'addresses.number',
                'addresses.complement',
                'addresses.neighborhood',
                'addresses.city as address_city',
                'addresses.state as address_state',
                'appointments.created_at',
                'appointments.updated_at'
            ])->get();

            // Add statistical data for graphs
            $statistics = [
                'total_appointments' => $appointments->count(),
                'status_distribution' => $appointments->groupBy('status')->map->count()->toArray(),
                'attendance_rate' => [
                    'attended' => $appointments->where('patient_attended', true)->count(),
                    'not_attended' => $appointments->where('patient_attended', false)->count()
                ],
                'daily_distribution' => $appointments->groupBy(function($item) {
                    return Carbon::parse($item->scheduled_date)->format('Y-m-d');
                })->map->count()->toArray()
            ];

            \Log::info('Appointment report data:', [
                'appointments_count' => $appointments->count(),
                'statistics' => $statistics
            ]);

            return [
                'appointments' => $appointments,
                'statistics' => $statistics
            ];
        } catch (\Exception $e) {
            \Log::error('Error generating appointments report:', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            throw $e;
        }
    }

    /**
     * Get professionals report data
     */
    private function getProfessionalsReport(array $filters)
    {
        $query = DB::table('professionals')
            ->leftJoin('clinics', 'professionals.clinic_id', '=', 'clinics.id')
            ->leftJoin('appointments', function($join) {
                $join->on('professionals.id', '=', 'appointments.provider_id')
                    ->where('appointments.provider_type', '=', 'App\\Models\\Professional');
            });

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('professionals.status', $filters['status']);
        }
        if (!empty($filters['city'])) {
            $query->where('professionals.city', $filters['city']);
        }
        if (!empty($filters['state'])) {
            $query->where('professionals.state', $filters['state']);
        }
        if (!empty($filters['specialty'])) {
            $query->where('professionals.specialty', 'like', "%{$filters['specialty']}%");
        }
        if (!empty($filters['clinic_id'])) {
            $query->where('professionals.clinic_id', $filters['clinic_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('professionals.created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('professionals.created_at', '<=', $filters['end_date']);
        }

        // Select fields
        return $query->select([
            'professionals.id',
            'professionals.name',
            'professionals.cpf',
            'professionals.specialty',
            'professionals.council_number',
            'professionals.status',
            'clinics.name as clinic_name',
            DB::raw('COUNT(DISTINCT appointments.id) as total_appointments'),
            DB::raw('COUNT(DISTINCT CASE WHEN appointments.patient_attended = 1 THEN appointments.id END) as completed_appointments'),
            'professionals.created_at'
        ])
        ->groupBy([
            'professionals.id',
            'professionals.name',
            'professionals.cpf',
            'professionals.specialty',
            'professionals.council_number',
            'professionals.status',
            'clinics.name',
            'professionals.created_at'
        ])
        ->get();
    }

    /**
     * Get clinics report data
     */
    private function getClinicsReport(array $filters)
    {
        $query = DB::table('clinics')
            ->leftJoin('appointments', function($join) {
                $join->on('clinics.id', '=', 'appointments.provider_id')
                    ->where('appointments.provider_type', '=', 'App\\Models\\Clinic');
            })
            ->leftJoin('professionals', 'clinics.id', '=', 'professionals.clinic_id');

        // Apply filters
        if (!empty($filters['status'])) {
            $query->where('clinics.status', $filters['status']);
        }
        if (!empty($filters['city'])) {
            $query->where('clinics.city', $filters['city']);
        }
        if (!empty($filters['state'])) {
            $query->where('clinics.state', $filters['state']);
        }
        if (!empty($filters['start_date'])) {
            $query->where('clinics.created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('clinics.created_at', '<=', $filters['end_date']);
        }

        // Select fields
        return $query->select([
            'clinics.id',
            'clinics.name',
            'clinics.cnpj',
            'clinics.city',
            'clinics.state',
            'clinics.status',
            DB::raw('COUNT(DISTINCT professionals.id) as total_professionals'),
            DB::raw('COUNT(DISTINCT appointments.id) as total_appointments'),
            DB::raw('COUNT(DISTINCT CASE WHEN appointments.patient_attended = 1 THEN appointments.id END) as completed_appointments'),
            'clinics.created_at'
        ])
        ->groupBy([
            'clinics.id',
            'clinics.name',
            'clinics.cnpj',
            'clinics.city',
            'clinics.state',
            'clinics.status',
            'clinics.created_at'
        ])
        ->get();
    }

    /**
     * Get financial report data
     */
    private function getFinancialReport(array $filters)
    {
        $query = DB::table('billing_batches')
            ->join('health_plans', 'billing_batches.entity_id', '=', 'health_plans.id')
            ->leftJoin('billing_items', 'billing_batches.id', '=', 'billing_items.billing_batch_id');

        // Apply filters
        if (!empty($filters['start_date'])) {
            $query->where('billing_batches.billing_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('billing_batches.billing_date', '<=', $filters['end_date']);
        }
        if (!empty($filters['status'])) {
            $query->where('billing_batches.status', $filters['status']);
        }
        if (!empty($filters['health_plan_id'])) {
            $query->where('billing_batches.entity_id', $filters['health_plan_id']);
        }

        // Select fields
        return $query->select([
            'billing_batches.id',
            'billing_batches.billing_date',
            'billing_batches.due_date',
            'billing_batches.status',
            'health_plans.name as health_plan_name',
            DB::raw('COUNT(DISTINCT billing_items.id) as total_items'),
            DB::raw('SUM(billing_items.total_amount) as total_amount'),
            'billing_batches.created_at'
        ])
        ->groupBy([
            'billing_batches.id',
            'billing_batches.billing_date',
            'billing_batches.due_date',
            'billing_batches.status',
            'health_plans.name',
            'billing_batches.created_at'
        ])
        ->get();
    }

    /**
     * Get billing report data
     */
    private function getBillingReport(array $filters)
    {
        $query = DB::table('billing_items')
            ->join('billing_batches', 'billing_items.billing_batch_id', '=', 'billing_batches.id')
            ->join('health_plans', 'billing_batches.entity_id', '=', 'health_plans.id');

        // Apply filters
        if (!empty($filters['start_date'])) {
            $query->where('billing_batches.billing_date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->where('billing_batches.billing_date', '<=', $filters['end_date']);
        }
        if (!empty($filters['status'])) {
            $query->where('billing_items.status', $filters['status']);
        }
        if (!empty($filters['health_plan_id'])) {
            $query->where('billing_batches.entity_id', $filters['health_plan_id']);
        }

        // Select fields
        return $query->select([
            'billing_items.id',
            'billing_items.description',
            'billing_items.unit_price',
            'billing_items.quantity',
            'billing_items.total_amount',
            'billing_items.status',
            'health_plans.name as health_plan_name',
            'billing_batches.billing_date',
            'billing_batches.due_date',
            'billing_items.created_at'
        ])->get();
    }

    /**
     * Export report data to file
     */
    private function exportReport($data, string $type, string $format)
    {
        \Log::info('ReportGenerationService: Starting export', [
            'type' => $type,
            'format' => $format,
            'data_type' => gettype($data)
        ]);

        try {
            switch ($format) {
                case 'pdf':
                    $filePath = $this->exportToPdf($data, $type);
                    break;
                case 'csv':
                    $filePath = $this->exportToCsv($data, $type);
                    break;
                case 'xlsx':
                    $filePath = $this->exportToXlsx($data, $type);
                    break;
                default:
                    throw new \Exception("Unsupported format: {$format}");
            }

            \Log::info('ReportGenerationService: Export completed', [
                'type' => $type,
                'format' => $format,
                'file_path' => $filePath
            ]);

            return $filePath;
        } catch (\Exception $e) {
            \Log::error('ReportGenerationService: Export failed', [
                'type' => $type,
                'format' => $format,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Export data to PDF using TCPDF
     */
    private function exportToPdf($data, string $type)
    {
        // Create new TCPDF instance
        $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        
        // Set document information
        $pdf->SetCreator('Medical System');
        $pdf->SetAuthor('Medical System');
        $pdf->SetTitle("Relatório de {$type}");
        $pdf->SetSubject("Relatório de {$type}");
        
        // Set margins
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(10);
        
        // Add a page
        $pdf->AddPage();
        
        // Set font
        $pdf->SetFont('helvetica', 'B', 16);
        
        // Add title
        $pdf->Cell(0, 10, "Relatório de " . ucfirst($type), 0, 1, 'C');
        $pdf->Ln(5);
        
        // Set font for content
        $pdf->SetFont('helvetica', '', 10);
        
        // Generate content based on data type
        $content = $this->generatePdfContent($data, $type);
        $pdf->writeHTML($content, true, false, true, false, '');
        
        $filename = "{$type}_report_" . date('Y-m-d_His') . '.pdf';
        $path = "reports/{$filename}";
        
        // Get PDF content
        $pdfContent = $pdf->Output('', 'S');
        
        // Save to storage
        Storage::put($path, $pdfContent);
        
        return $path;
    }
    
    /**
     * Generate HTML content for PDF based on data type
     */
    private function generatePdfContent($data, string $type): string
    {
        $html = '<table border="1" cellpadding="5" cellspacing="0">';
        
        switch ($type) {
            case 'appointment':
                if (isset($data['appointments']) && is_array($data['appointments'])) {
                    $html .= '<tr style="background-color:#f0f0f0;">
                        <th>ID</th>
                        <th>Paciente</th>
                        <th>Profissional</th>
                        <th>Data</th>
                        <th>Status</th>
                    </tr>';
                    
                    foreach ($data['appointments'] as $appointment) {
                        $html .= '<tr>
                            <td>' . ($appointment->id ?? '') . '</td>
                            <td>' . ($appointment->patient->name ?? '') . '</td>
                            <td>' . ($appointment->provider->name ?? '') . '</td>
                            <td>' . ($appointment->scheduled_date ?? '') . '</td>
                            <td>' . ($appointment->status ?? '') . '</td>
                        </tr>';
                    }
                }
                break;
                
            case 'billing':
                if (is_array($data)) {
                    $html .= '<tr style="background-color:#f0f0f0;">
                        <th>ID</th>
                        <th>Paciente</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Data</th>
                    </tr>';
                    
                    foreach ($data as $item) {
                        $html .= '<tr>
                            <td>' . ($item->id ?? '') . '</td>
                            <td>' . ($item->patient->name ?? '') . '</td>
                            <td>R$ ' . number_format($item->amount ?? 0, 2, ',', '.') . '</td>
                            <td>' . ($item->status ?? '') . '</td>
                            <td>' . ($item->created_at ?? '') . '</td>
                        </tr>';
                    }
                }
                break;
                
            default:
                $html .= '<tr><td colspan="5">Dados não disponíveis</td></tr>';
        }
        
        $html .= '</table>';
        
        // Add generation date
        $html .= '<br><p><small>Gerado em: ' . date('d/m/Y H:i:s') . '</small></p>';
        
        return $html;
    }

    /**
     * Export data to CSV
     */
    private function exportToCsv($data, string $type)
    {
        try {
            // Create CSV writer
            $csv = Writer::createFromString('');
            
            // Add headers
            $headers = $this->getReportHeaders($type);
            $csv->insertOne($headers);
            
            // Add data
            $rowData = $this->formatDataForExport($data, $type);
            foreach ($rowData as $row) {
                $csv->insertOne($row);
            }
            
            $filename = "{$type}_report_" . date('Y-m-d_His') . '.csv';
            $path = "reports/{$filename}";
            
            // Save to storage
            Storage::put($path, $csv->toString());
            
            \Log::info('CSV file generated successfully', [
                'file_path' => $path,
                'size' => Storage::size($path)
            ]);
            
            return $path;
        } catch (\Exception $e) {
            \Log::error('Failed to generate CSV file', [
                'error' => $e->getMessage(),
                'type' => $type
            ]);
            throw $e;
        }
    }

    /**
     * Export data to XLSX
     */
    private function exportToXlsx($data, string $type)
    {
        try {
            $filename = "{$type}_report_" . date('Y-m-d_His') . '.xlsx';
            $path = "reports/{$filename}";
            $fullPath = storage_path("app/{$path}");
            
            // Ensure directory exists
            $directory = dirname($fullPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Create XLSX writer
            $options = new Options();
            $writer = new XlsxWriter($options);
            $writer->openToFile($fullPath);
            
            // Add headers
            $headers = $this->getReportHeaders($type);
            $writer->addRow(Row::fromValues($headers));
            
            // Add data
            $rowData = $this->formatDataForExport($data, $type);
            foreach ($rowData as $row) {
                $writer->addRow(Row::fromValues($row));
            }
            
            $writer->close();
            
            \Log::info('XLSX file generated successfully', [
                'file_path' => $path,
                'size' => Storage::size($path)
            ]);
            
            return $path;
        } catch (\Exception $e) {
            \Log::error('Failed to generate XLSX file', [
                'error' => $e->getMessage(),
                'type' => $type
            ]);
            throw $e;
        }
    }

    /**
     * Get report headers based on type
     */
    private function getReportHeaders(string $type)
    {
        switch ($type) {
            case 'appointment':
                return [
                    'ID',
                    'Data Agendada',
                    'Status',
                    'Compareceu',
                    'Paciente',
                    'Documento',
                    'Prestador',
                    'Plano de Saúde',
                    'Rua',
                    'Número',
                    'Complemento',
                    'Bairro',
                    'Cidade',
                    'Estado',
                    'Data Criação',
                    'Última Atualização'
                ];
            case 'professionals':
                return [
                    'ID',
                    'Nome',
                    'CPF',
                    'Especialidade',
                    'CRM/CRO',
                    'Status',
                    'Clínica',
                    'Total Agendamentos',
                    'Agendamentos Concluídos',
                    'Data Cadastro'
                ];
            case 'clinics':
                return [
                    'ID',
                    'Nome',
                    'CNPJ',
                    'Cidade',
                    'Estado',
                    'Status',
                    'Total Profissionais',
                    'Total Agendamentos',
                    'Agendamentos Concluídos',
                    'Data Cadastro'
                ];
            case 'financial':
                return [
                    'ID',
                    'Data Faturamento',
                    'Data Vencimento',
                    'Status',
                    'Plano de Saúde',
                    'Total Itens',
                    'Valor Total',
                    'Data Criação'
                ];
            case 'billing':
                return [
                    'ID',
                    'Descrição',
                    'Valor Unitário',
                    'Quantidade',
                    'Valor Total',
                    'Status',
                    'Plano de Saúde',
                    'Data Faturamento',
                    'Data Vencimento',
                    'Data Criação'
                ];
            default:
                throw new \Exception("Invalid report type: {$type}");
        }
    }

    /**
     * Format data for export based on type
     */
    private function formatDataForExport($data, string $type)
    {
        // For appointment reports, use the appointments data
        if ($type === 'appointment') {
            $data = $data['appointments'];
        }

        return $data->map(function($item) use ($type) {
            switch ($type) {
                case 'appointment':
                    return [
                        $item->id,
                        $item->scheduled_date,
                        $item->status,
                        $item->patient_attended ? 'Sim' : 'Não',
                        $item->patient_name,
                        $item->patient_document,
                        $item->provider_name,
                        $item->health_plan_name,
                        $item->street,
                        $item->number,
                        $item->complement,
                        $item->neighborhood,
                        $item->address_city,
                        $item->address_state,
                        $item->created_at,
                        $item->updated_at
                    ];
                case 'professionals':
                    return [
                        $item->id,
                        $item->name,
                        $item->cpf,
                        $item->specialty,
                        $item->council_number,
                        $item->status,
                        $item->clinic_name,
                        $item->total_appointments,
                        $item->completed_appointments,
                        $item->created_at
                    ];
                case 'clinics':
                    return [
                        $item->id,
                        $item->name,
                        $item->cnpj,
                        $item->city,
                        $item->state,
                        $item->status,
                        $item->total_professionals,
                        $item->total_appointments,
                        $item->completed_appointments,
                        $item->created_at
                    ];
                case 'financial':
                    return [
                        $item->id,
                        $item->billing_date,
                        $item->due_date,
                        $item->status,
                        $item->health_plan_name,
                        $item->total_items,
                        $item->total_amount,
                        $item->created_at
                    ];
                case 'billing':
                    return [
                        $item->id,
                        $item->description,
                        $item->unit_price,
                        $item->quantity,
                        $item->total_amount,
                        $item->status,
                        $item->health_plan_name,
                        $item->billing_date,
                        $item->due_date,
                        $item->created_at
                    ];
                default:
                    throw new \Exception("Invalid report type: {$type}");
            }
        })->toArray();
    }
} 