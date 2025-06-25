<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv;

class ReportGenerationService
{
    /**
     * Generate report based on type and format
     */
    public function generateReport(string $type, array $filters, string $format = 'pdf')
    {
        $data = $this->getReportData($type, $filters);
        return $this->exportReport($data, $type, $format);
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
                'addresses.reference',
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
        ->groupBy('professionals.id')
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
        ->groupBy('clinics.id')
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
        ->groupBy('billing_batches.id')
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
     * Export report data to specified format
     */
    private function exportReport($data, string $type, string $format)
    {
        switch ($format) {
            case 'pdf':
                return $this->exportToPdf($data, $type);
            case 'csv':
                return $this->exportToCsv($data, $type);
            case 'xlsx':
                return $this->exportToXlsx($data, $type);
            default:
                throw new \Exception("Invalid export format: {$format}");
        }
    }

    /**
     * Export data to PDF
     */
    private function exportToPdf($data, string $type)
    {
        $view = "reports.{$type}";
        
        // For appointment report, ensure we have both appointments and statistics
        if ($type === 'appointment') {
            if (!isset($data['appointments']) || !isset($data['statistics'])) {
                throw new \Exception("Invalid data structure for appointment report");
            }
            $viewData = [
                'data' => $data['appointments'],
                'statistics' => $data['statistics']
            ];
        } else {
            $viewData = ['data' => $data];
        }
            
        $pdf = PDF::loadView($view, $viewData);
        
        // Set paper size and orientation for better layout
        $pdf->setPaper('A4', 'landscape');
        
        $filename = "{$type}_report_" . date('Y-m-d_His') . '.pdf';
        $path = "reports/{$filename}";
        
        Storage::put($path, $pdf->output());
        
        return $path;
    }

    /**
     * Export data to CSV
     */
    private function exportToCsv($data, string $type)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Add headers
        $headers = $this->getReportHeaders($type);
        $sheet->fromArray($headers, null, 'A1');
        
        // Add data
        $rowData = $this->formatDataForExport($data, $type);
        $sheet->fromArray($rowData, null, 'A2');
        
        $writer = new Csv($spreadsheet);
        
        $filename = "{$type}_report_" . date('Y-m-d_His') . '.csv';
        $path = "reports/{$filename}";
        
        $writer->save(storage_path("app/{$path}"));
        
        return $path;
    }

    /**
     * Export data to XLSX
     */
    private function exportToXlsx($data, string $type)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Add headers
        $headers = $this->getReportHeaders($type);
        $sheet->fromArray($headers, null, 'A1');
        
        // Add data
        $rowData = $this->formatDataForExport($data, $type);
        $sheet->fromArray($rowData, null, 'A2');
        
        $writer = new Xlsx($spreadsheet);
        
        $filename = "{$type}_report_" . date('Y-m-d_His') . '.xlsx';
        $path = "reports/{$filename}";
        
        $writer->save(storage_path("app/{$path}"));
        
        return $path;
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
                    'Referência',
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
                        $item->reference,
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