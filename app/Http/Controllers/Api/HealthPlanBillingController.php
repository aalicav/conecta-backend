<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\PaymentGloss;
use App\Models\FiscalDocument;
use App\Models\PaymentProof;
use App\Models\HealthPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class HealthPlanBillingController extends Controller
{
    /**
     * Get billing data for a health plan
     *
     * @param Request $request
     * @param int $healthPlanId
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, $healthPlanId)
    {
        try {
            // Validate health plan access
            $healthPlan = HealthPlan::findOrFail($healthPlanId);
            
            // Build query for billing batches
            $batchesQuery = BillingBatch::with([
                'items.appointment.patient',
                'items.appointment.professional.specialty',
                'items.appointment.procedure',
                'fiscalDocuments',
                'paymentProofs'
            ])
            ->where('entity_type', 'health_plan')
            ->where('entity_id', $healthPlanId);

            // Apply filters
            if ($request->status) {
                $batchesQuery->where('status', $request->status);
            }

            if ($request->start_date) {
                $batchesQuery->where('billing_date', '>=', $request->start_date);
            }

            if ($request->end_date) {
                $batchesQuery->where('billing_date', '<=', $request->end_date);
            }

            if ($request->search) {
                $search = $request->search;
                $batchesQuery->whereHas('items.appointment.patient', function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('cpf', 'like', "%{$search}%");
                });
            }

            // Get batches with pagination
            $batches = $batchesQuery->orderBy('billing_date', 'desc')
                ->paginate($request->per_page ?? 10);

            // Get glosas for this health plan
            $glosas = PaymentGloss::whereHas('billingItem.billingBatch', function ($query) use ($healthPlanId) {
                $query->where('entity_id', $healthPlanId)
                    ->where('entity_type', 'health_plan');
            })
            ->with(['billingItem.billingBatch'])
            ->orderBy('created_at', 'desc')
            ->get();

            // Format response
            $formattedBatches = $batches->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'reference_period_start' => $batch->reference_period_start,
                    'reference_period_end' => $batch->reference_period_end,
                    'billing_date' => $batch->billing_date,
                    'due_date' => $batch->due_date,
                    'total_amount' => $batch->total_amount,
                    'status' => $batch->status,
                    'items_count' => $batch->items_count,
                    'items' => $batch->items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'appointment' => [
                                'id' => $item->appointment->id,
                                'patient' => [
                                    'name' => $item->appointment->patient->name,
                                    'cpf' => $item->appointment->patient->cpf
                                ],
                                'professional' => [
                                    'name' => $item->appointment->professional->name,
                                    'specialty' => [
                                        'name' => $item->appointment->professional->specialty->name
                                    ]
                                ],
                                'procedure' => [
                                    'tuss_code' => $item->appointment->procedure->tuss_code,
                                    'name' => $item->appointment->procedure->name
                                ],
                                'scheduled_date' => $item->appointment->scheduled_date,
                                'scheduled_time' => $item->appointment->scheduled_time,
                                'journey' => [
                                    'scheduled_at' => $item->appointment->created_at,
                                    'confirmed_at' => $item->appointment->confirmation_date,
                                    'attendance_confirmed_at' => $item->appointment->attendance_confirmation_date,
                                    'attended' => $item->appointment->attended,
                                    'guide_status' => $item->appointment->guide_status
                                ]
                            ],
                            'amount' => $item->amount
                        ];
                    }),
                    'fiscal_documents' => $batch->fiscalDocuments->map(function ($doc) {
                        return [
                            'id' => $doc->id,
                            'number' => $doc->number,
                            'issue_date' => $doc->issue_date,
                            'file_url' => $doc->file_url
                        ];
                    }),
                    'payment_proofs' => $batch->paymentProofs->map(function ($proof) {
                        return [
                            'id' => $proof->id,
                            'date' => $proof->date,
                            'amount' => $proof->amount,
                            'file_url' => $proof->file_url
                        ];
                    })
                ];
            });

            return response()->json([
                'batches' => $formattedBatches,
                'glosas' => $glosas,
                'pagination' => [
                    'current_page' => $batches->currentPage(),
                    'last_page' => $batches->lastPage(),
                    'per_page' => $batches->perPage(),
                    'total' => $batches->total()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao carregar dados de faturamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export billing data to CSV
     *
     * @param Request $request
     * @param int $healthPlanId
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportCsv(Request $request, $healthPlanId)
    {
        try {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="faturamento.csv"',
                'Pragma' => 'no-cache',
                'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
                'Expires' => '0'
            ];

            $callback = function () use ($healthPlanId, $request) {
                $file = fopen('php://output', 'w');
                
                // Add CSV headers
                fputcsv($file, [
                    'ID Faturamento',
                    'Período Início',
                    'Período Fim',
                    'Data Faturamento',
                    'Vencimento',
                    'Valor Total',
                    'Status',
                    'Paciente',
                    'CPF',
                    'Profissional',
                    'Especialidade',
                    'Procedimento',
                    'Código TUSS',
                    'Data Atendimento',
                    'Valor',
                    'Status Guia'
                ]);

                // Query batches
                BillingBatch::with([
                    'items.appointment.patient',
                    'items.appointment.professional.specialty',
                    'items.appointment.procedure'
                ])
                ->where('entity_type', 'health_plan')
                ->where('entity_id', $healthPlanId)
                ->when($request->start_date, function ($query) use ($request) {
                    return $query->where('billing_date', '>=', $request->start_date);
                })
                ->when($request->end_date, function ($query) use ($request) {
                    return $query->where('billing_date', '<=', $request->end_date);
                })
                ->chunk(100, function ($batches) use ($file) {
                    foreach ($batches as $batch) {
                        foreach ($batch->items as $item) {
                            fputcsv($file, [
                                $batch->id,
                                $batch->reference_period_start,
                                $batch->reference_period_end,
                                $batch->billing_date,
                                $batch->due_date,
                                $batch->total_amount,
                                $batch->status,
                                $item->appointment->patient->name,
                                $item->appointment->patient->cpf,
                                $item->appointment->professional->name,
                                $item->appointment->professional->specialty->name,
                                $item->appointment->procedure->name,
                                $item->appointment->procedure->tuss_code,
                                $item->appointment->scheduled_date . ' ' . $item->appointment->scheduled_time,
                                $item->amount,
                                $item->appointment->guide_status
                            ]);
                        }
                    }
                });

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao exportar dados',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get billing statistics and overview
     *
     * @param Request $request
     * @param int $healthPlanId
     * @return \Illuminate\Http\JsonResponse
     */
    public function overview(Request $request, $healthPlanId)
    {
        try {
            // Get total statistics
            $totalStats = [
                'total_amount' => BillingBatch::where('entity_type', 'health_plan')
                    ->where('entity_id', $healthPlanId)
                    ->sum('total_amount'),
                'pending_amount' => BillingBatch::where('entity_type', 'health_plan')
                    ->where('entity_id', $healthPlanId)
                    ->where('status', 'pending')
                    ->sum('total_amount'),
                'paid_amount' => BillingBatch::where('entity_type', 'health_plan')
                    ->where('entity_id', $healthPlanId)
                    ->where('status', 'paid')
                    ->sum('total_amount'),
                'glosa_amount' => PaymentGloss::whereHas('billingItem.billingBatch', function ($query) use ($healthPlanId) {
                    $query->where('entity_id', $healthPlanId)
                        ->where('entity_type', 'health_plan');
                })->sum('amount')
            ];

            // Get monthly billing totals
            $monthlyTotals = BillingBatch::select(
                DB::raw('DATE_FORMAT(billing_date, "%Y-%m") as month'),
                DB::raw('SUM(total_amount) as total_amount'),
                DB::raw('COUNT(*) as batch_count'),
                DB::raw('SUM(CASE WHEN status = "paid" THEN total_amount ELSE 0 END) as paid_amount'),
                DB::raw('SUM(CASE WHEN status = "pending" THEN total_amount ELSE 0 END) as pending_amount')
            )
            ->where('entity_type', 'health_plan')
            ->where('entity_id', $healthPlanId)
            ->where('billing_date', '>=', now()->subMonths(12))
            ->groupBy(DB::raw('DATE_FORMAT(billing_date, "%Y-%m")'))
            ->orderBy('month', 'desc')
            ->get();

            // Get status distribution
            $statusDistribution = BillingBatch::select('status', DB::raw('COUNT(*) as count'))
                ->where('entity_type', 'health_plan')
                ->where('entity_id', $healthPlanId)
                ->groupBy('status')
                ->get();

            return response()->json([
                'total_statistics' => $totalStats,
                'monthly_totals' => $monthlyTotals,
                'status_distribution' => $statusDistribution
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao carregar visão geral',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 