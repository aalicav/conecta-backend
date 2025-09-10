<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\Appointment;
use App\Models\PaymentGloss;
use App\Models\FiscalDocument;
use App\Notifications\BillingBatchCreated;
use App\Notifications\BillingEmitted;
use App\Notifications\PaymentReceived;
use App\Notifications\PaymentOverdue;
use App\Notifications\GlosaReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;
use App\Models\User;
use App\Models\SpecialtyPrice;
use App\Models\MedicalSpecialty;
use App\Models\PricingContract;
use App\Models\BillingRule;
use App\Models\ValueVerification;
use App\Models\HealthPlan;

class BillingController extends Controller
{
    /**
     * Lista os lotes de faturamento com filtros
     */
    public function index(Request $request)
    {
        $query = BillingBatch::with(['billingItems', 'fiscalDocuments'])
            ->when($request->status, function ($q, $status) {
                return $q->where('status', $status);
            })
            ->when($request->date_from, function ($q, $date) {
                return $q->where('billing_date', '>=', $date);
            })
            ->when($request->date_to, function ($q, $date) {
                return $q->where('billing_date', '<=', $date);
            })
            ->when($request->start_date, function ($q, $date) {
                return $q->where('billing_date', '>=', $date);
            })
            ->when($request->end_date, function ($q, $date) {
                return $q->where('billing_date', '<=', $date);
            })
            ->when($request->operator_id, function ($q, $operatorId) {
                return $q->where('entity_id', $operatorId)
                    ->where('entity_type', 'health_plan');
            });

        $batches = $query->orderBy('created_at', 'desc')->get();

        // Transform data to match frontend expectations
        $transformedBatches = $batches->map(function ($batch) {
            return [
                'id' => $batch->id,
                'reference_period_start' => $batch->reference_period_start,
                'reference_period_end' => $batch->reference_period_end,
                'total_amount' => $batch->total_amount,
                'status' => $batch->status,
                'created_at' => $batch->created_at,
                'items' => $batch->billingItems->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'patient' => [
                            'name' => $item->patient_name,
                            'cpf' => $item->patient_document,
                        ],
                        'provider' => [
                            'name' => $item->professional_name,
                            'type' => 'professional',
                            'specialty' => $item->professional_specialty,
                        ],
                        'procedure' => [
                            'code' => $item->tuss_code,
                            'description' => $item->tuss_description,
                        ],
                        'appointment' => [
                            'scheduled_date' => $item->patient_journey_data['scheduled_at'] ?? null,
                            'booking_date' => $item->patient_journey_data['scheduled_at'] ?? null,
                            'confirmation_date' => $item->patient_journey_data['scheduled_at'] ?? null,
                            'attendance_confirmation' => $item->patient_journey_data['patient_attended'] ? 'Sim' : 'Não',
                            'guide_status' => $item->patient_journey_data['guide_status'] ?? 'missing',
                        ],
                        'amount' => $item->total_amount,
                        'status' => $item->status,
                        'gloss_reason' => $item->gloss_reason ?? null,
                    ];
                }),
                'payment_proof' => $batch->payment_proof_path,
                'invoice' => $batch->fiscalDocuments->first()?->document_url ?? null,
            ];
        });

        return response()->json([
            'data' => $transformedBatches,
            'meta' => [
                'total' => $batches->count(),
                'per_page' => $batches->count(),
                'current_page' => 1,
                'last_page' => 1,
            ]
        ]);
    }

    /**
     * Gera um novo lote de faturamento
     */
    public function generateBatch(Request $request)
    {
        $request->validate([
            'operator_id' => 'required|exists:health_plans,id',
            'reference_period_start' => 'required|date',
            'reference_period_end' => 'required|date|after:reference_period_start',
        ]);

        try {
            DB::beginTransaction();

            // Busca atendimentos elegíveis para faturamento
            $appointments = Appointment::where('eligible_for_billing', true)
                ->whereBetween('scheduled_date', [
                    $request->reference_period_start,
                    $request->reference_period_end
                ])
                ->whereHas('solicitation', function($query) use ($request) {
                    $query->where('health_plan_id', $request->operator_id);
                })
                ->whereNull('billing_batch_id')
                ->where('patient_confirmed', true)
                ->where('professional_confirmed', true)
                ->where('patient_attended', true)
                ->where('guide_status', 'approved')
                ->get();

            if ($appointments->isEmpty()) {
                return response()->json([
                    'message' => 'Nenhum atendimento elegível encontrado para o período'
                ], 404);
            }

            // Cria o lote de faturamento
            $batch = BillingBatch::create([
                'billing_rule_id' => $request->billing_rule_id,
                'entity_type' => 'health_plan',
                'entity_id' => $request->operator_id,
                'reference_period_start' => $request->reference_period_start,
                'reference_period_end' => $request->reference_period_end,
                'billing_date' => now(),
                'due_date' => now()->addDays(30),
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);

            $totalAmount = 0;

            // Cria os itens do lote
            foreach ($appointments as $appointment) {
                $procedurePrice = $this->getProcedurePrice($appointment);
                
                BillingItem::create([
                    'billing_batch_id'        => $batch->id,
                    'item_type'               => 'appointment',
                    'item_id'                 => $appointment->id,
                    'description'             => "Atendimento {$appointment->provider->specialty} - {$appointment->scheduled_date}",
                    'quantity'                => 1,
                    'unit_price'              => $procedurePrice,
                    'discount_amount'         => 0,
                    'tax_amount'              => 0,
                    'total_amount'            => $procedurePrice,
                    'status'                  => 'pending',
                    'notes'                   => null,
                    'reference_type'          => null,
                    'reference_id'            => null,
                    'verified_by_operator'    => false,
                    'verified_at'             => null,
                    'verification_user'       => null,
                    'verification_notes'      => null,
                    'patient_journey_data'    => [
                        'scheduled_at'         => $appointment->scheduled_date,
                        'pre_confirmation'     => $appointment->pre_confirmation_response,
                        'patient_confirmed'    => $appointment->patient_confirmed,
                        'professional_confirmed'=> $appointment->professional_confirmed,
                        'guide_status'         => $appointment->guide_status,
                        'patient_attended'     => $appointment->patient_attended
                    ],
                    'tuss_code'               => $appointment->solicitation->tuss->code,
                    'tuss_description'        => $appointment->solicitation->tuss->description,
                    'professional_name'       => $appointment->provider->name,
                    'professional_specialty'  => $appointment->provider->specialty,
                    'patient_name'            => $appointment->solicitation->patient->name,
                    'patient_document'        => $appointment->solicitation->patient->cpf,
                ]);

                $appointment->update(['billing_batch_id' => $batch->id]);
                $totalAmount += $procedurePrice;
            }

            // Atualiza totais do lote
            $batch->update([
                'items_count' => $appointments->count(),
                'total_amount' => $totalAmount
            ]);

            // Notifica sobre a criação do lote
            Notification::send($batch->entity, new BillingBatchCreated($batch));

            DB::commit();

            return response()->json($batch->load('billingItems'), 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao gerar lote de faturamento', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registra o pagamento de um lote
     */
    public function registerPayment(Request $request, BillingBatch $batch)
    {
        $request->validate([
            'payment_method' => 'required|string',
            'payment_reference' => 'required|string',
            'payment_amount' => 'required|numeric',
            'payment_date' => 'required|date',
            'payment_proof' => 'required|file'
        ]);

        try {
            DB::beginTransaction();

            // Armazena o comprovante
            $proofPath = $request->file('payment_proof')->store('payment_proofs');

            // Atualiza o lote
            $batch->update([
                'payment_status' => 'paid',
                'payment_received_at' => $request->payment_date,
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'payment_proof_path' => $proofPath
            ]);

            // Notifica sobre o pagamento
            Notification::send($batch->entity, new PaymentReceived($batch));

            DB::commit();

            return response()->json($batch);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao registrar pagamento', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registra uma glosa
     */
    public function registerGlosa(Request $request, BillingItem $item)
    {
        $request->validate([
            'glosa_type' => 'required|string',
            'glosa_code' => 'required|string',
            'amount' => 'required|numeric',
            'description' => 'required|string',
            'supporting_documents' => 'array'
        ]);

        try {
            DB::beginTransaction();

            $glosa = PaymentGloss::create([
                'billing_item_id' => $item->id,
                'glosa_type' => $request->glosa_type,
                'glosa_code' => $request->glosa_code,
                'amount' => $request->amount,
                'original_amount' => $item->total_amount,
                'description' => $request->description,
                'operator_response_status' => 'pending',
                'resolution_status' => 'pending',
                'can_appeal' => true,
                'appeal_deadline_days' => 30, // Configurável
                'appeal_deadline_at' => now()->addDays(30)
            ]);

            // Armazena documentos de suporte
            if ($request->hasFile('supporting_documents')) {
                $documents = [];
                foreach ($request->file('supporting_documents') as $document) {
                    $documents[] = $document->store('glosa_documents');
                }
                $glosa->update(['supporting_documents' => $documents]);
            }

            // Notifica sobre a glosa
            Notification::send($item->billingBatch->entity, new GlosaReceived($glosa));

            DB::commit();

            return response()->json($glosa, 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao registrar glosa', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Verifica pagamentos atrasados e envia notificações
     */
    public function checkOverduePayments()
    {
        $overdueBatches = BillingBatch::where('payment_status', 'pending')
            ->where('due_date', '<', now())
            ->where('is_late', false)
            ->with(['entity', 'billingItems'])
            ->get();

        foreach ($overdueBatches as $batch) {
            $daysLate = now()->diffInDays($batch->due_date);
            
            $batch->update([
                'is_late' => true,
                'days_late' => $daysLate,
                'last_notification_sent' => now()
            ]);

            // Notificação para diferentes níveis de atraso
            if ($daysLate >= 30) {
                Notification::send($batch->entity, new PaymentOverdue($batch));
                // Notifica supervisores
                Notification::send(User::role('financial_supervisor')->get(), new PaymentOverdue($batch));
            } elseif ($daysLate >= 15) {
                Notification::send($batch->entity, new PaymentOverdue($batch));
            } else {
                Notification::send($batch->entity, new PaymentOverdue($batch));
            }
        }

        return response()->json([
            'message' => 'Verificação de atrasos concluída',
            'batches_processed' => $overdueBatches->count()
        ]);
    }

    /**
     * Provides a comprehensive overview of billing information for health plans
     */
    public function overview(Request $request)
    {
        try {
            // Get recent billing batches with detailed information
            $recentBatches = BillingBatch::with([
                'billingItems.appointment.patient',
                'billingItems.appointment.professional',
                'fiscalDocuments',
                'entity'
            ])
            ->when($request->operator_id, function ($q, $operatorId) {
                return $q->where('entity_id', $operatorId)
                    ->where('entity_type', 'health_plan');
            })
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($batch) {
                return [
                    'id' => $batch->id,
                    'reference_period' => [
                        'start' => $batch->reference_period_start,
                        'end' => $batch->reference_period_end
                    ],
                    'billing_date' => $batch->billing_date,
                    'due_date' => $batch->due_date,
                    'total_amount' => $batch->total_amount,
                    'items_count' => $batch->items_count,
                    'status' => $batch->status,
                    'payment_status' => $batch->payment_status,
                    'operator' => $batch->entity->name ?? null,
                    'items' => $batch->billingItems->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'description' => $item->description,
                            'total_amount' => $item->total_amount,
                            'tuss_code' => $item->tuss_code,
                            'tuss_description' => $item->tuss_description,
                            'professional' => [
                                'name' => $item->professional_name,
                                'specialty' => $item->professional_specialty
                            ],
                            'patient' => [
                                'name' => $item->patient_name,
                                'document' => $item->patient_document
                            ],
                            'patient_journey' => $item->patient_journey_data
                        ];
                    })
                ];
            });

            // Calculate financial statistics
            $totalStats = [
                'pending_amount' => BillingBatch::where('payment_status', 'pending')
                    ->when($request->operator_id, function ($q, $operatorId) {
                        return $q->where('entity_id', $operatorId)
                            ->where('entity_type', 'health_plan');
                    })
                    ->sum('total_amount'),
                'paid_amount' => BillingBatch::where('payment_status', 'paid')
                    ->when($request->operator_id, function ($q, $operatorId) {
                        return $q->where('entity_id', $operatorId)
                            ->where('entity_type', 'health_plan');
                    })
                    ->sum('total_amount'),
                'total_batches' => BillingBatch::when($request->operator_id, function ($q, $operatorId) {
                        return $q->where('entity_id', $operatorId)
                            ->where('entity_type', 'health_plan');
                    })->count(),
                'pending_batches' => BillingBatch::where('payment_status', 'pending')
                    ->when($request->operator_id, function ($q, $operatorId) {
                        return $q->where('entity_id', $operatorId)
                            ->where('entity_type', 'health_plan');
                    })->count(),
                'overdue_batches' => BillingBatch::where('payment_status', 'pending')
                    ->where('due_date', '<', now())
                    ->when($request->operator_id, function ($q, $operatorId) {
                        return $q->where('entity_id', $operatorId)
                            ->where('entity_type', 'health_plan');
                    })->count()
            ];

            // Get payment status distribution with amounts
            $paymentStatusDistribution = BillingBatch::select(
                    'payment_status',
                    DB::raw('count(*) as total_batches'),
                    DB::raw('sum(total_amount) as total_amount')
                )
                ->when($request->operator_id, function ($q, $operatorId) {
                    return $q->where('entity_id', $operatorId)
                        ->where('entity_type', 'health_plan');
                })
                ->groupBy('payment_status')
                ->get();

            // Get monthly billing totals with additional metrics
            $monthlyTotals = BillingBatch::select(
                    DB::raw('DATE_FORMAT(billing_date, "%Y-%m") as month'),
                    DB::raw('SUM(total_amount) as total_amount'),
                    DB::raw('COUNT(*) as batch_count'),
                    DB::raw('SUM(CASE WHEN payment_status = "paid" THEN total_amount ELSE 0 END) as paid_amount'),
                    DB::raw('SUM(CASE WHEN payment_status = "pending" THEN total_amount ELSE 0 END) as pending_amount'),
                    DB::raw('COUNT(CASE WHEN payment_status = "pending" AND due_date < NOW() THEN 1 END) as overdue_count')
                )
                ->when($request->operator_id, function ($q, $operatorId) {
                    return $q->where('entity_id', $operatorId)
                        ->where('entity_type', 'health_plan');
                })
                ->where('billing_date', '>=', now()->subMonths(6))
                ->groupBy(DB::raw('DATE_FORMAT(billing_date, "%Y-%m")'))
                ->orderBy('month', 'desc')
                ->get();

            // Get detailed glosa statistics
            $glosaStats = PaymentGloss::select(
                    DB::raw('SUM(amount) as total_glosa_amount'),
                    DB::raw('COUNT(*) as total_glosas'),
                    DB::raw('COUNT(CASE WHEN resolution_status = "pending" THEN 1 END) as pending_glosas'),
                    DB::raw('COUNT(CASE WHEN can_appeal = true THEN 1 END) as appealable_glosas'),
                    DB::raw('AVG(amount) as average_glosa_amount')
                )
                ->when($request->operator_id, function ($q, $operatorId) {
                    return $q->whereHas('billingItem.billingBatch', function ($query) use ($operatorId) {
                        $query->where('entity_id', $operatorId)
                            ->where('entity_type', 'health_plan');
                    });
                })
                ->first();

            // Get attendance statistics
            $attendanceStats = Appointment::select(
                    DB::raw('COUNT(*) as total_appointments'),
                    DB::raw('COUNT(CASE WHEN patient_attended = true THEN 1 END) as attended_appointments'),
                    DB::raw('COUNT(CASE WHEN patient_attended = false THEN 1 END) as missed_appointments'),
                    DB::raw('COUNT(CASE WHEN eligible_for_billing = true THEN 1 END) as billable_appointments')
                )
                ->when($request->operator_id, function ($q, $operatorId) {
                    return $q->whereHas('solicitation', function($query) use ($operatorId) {
                        $query->where('health_plan_id', $operatorId);
                    });
                })
                ->where('scheduled_date', '>=', now()->subMonths(6))
                ->first();

            return response()->json([
                'total_statistics' => $totalStats,
                'recent_batches' => $recentBatches,
                'payment_status_distribution' => $paymentStatusDistribution,
                'monthly_totals' => $monthlyTotals,
                'glosa_statistics' => $glosaStats,
                'attendance_statistics' => $attendanceStats,
                'filters' => [
                    'start_date' => now()->subMonths(6)->format('Y-m-d'),
                    'end_date' => now()->format('Y-m-d'),
                    'operator_id' => $request->operator_id
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao obter visão geral do faturamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verifies if an appointment is eligible for billing based on attendance criteria
     */
    private function verifyBillingEligibility($appointment)
    {
        return $appointment->patient_confirmed &&
               $appointment->professional_confirmed &&
               $appointment->patient_attended === true &&
               $appointment->guide_status === 'approved';
    }

    /**
     * Get the price for a procedure based on the appointment context.
     *
     * @param Appointment $appointment
     * @return float
     */
    private function getProcedurePrice(Appointment $appointment): float
    {
        // Se não for consulta (10101012), retorna o preço padrão do procedimento
        if ($appointment->solicitation->tuss->code !== '10101012') {
            // Busca o preço na nova tabela health_plan_procedures
            if ($appointment->solicitation->health_plan_id) {
                $healthPlan = HealthPlan::find($appointment->solicitation->health_plan_id);
                if ($healthPlan) {
                    $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }
            
            // Fallback para contratos de preços antigos
            $pricingContract = PricingContract::where('tuss_procedure_id', $appointment->solicitation->tuss_id)
                ->where('contractable_type', get_class($appointment->provider))
                ->where('contractable_id', $appointment->provider_id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', now());
                })
                ->first();
            
            return $pricingContract ? $pricingContract->price : 0;
        }

        // Verifica se o profissional tem especialidade definida
        if (!$appointment->provider->specialty) {
            // Busca o preço na nova tabela health_plan_procedures
            if ($appointment->solicitation->health_plan_id) {
                $healthPlan = HealthPlan::find($appointment->solicitation->health_plan_id);
                if ($healthPlan) {
                    $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }
            
            // Fallback para contratos de preços antigos
            $pricingContract = PricingContract::where('tuss_procedure_id', $appointment->solicitation->tuss_id)
                ->where('contractable_type', get_class($appointment->provider))
                ->where('contractable_id', $appointment->provider_id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', now());
                })
                ->first();
            
            return $pricingContract ? $pricingContract->price : 0;
        }

        // Busca a especialidade pelo nome
        $specialty = MedicalSpecialty::where('name', $appointment->provider->specialty)->first();
        if (!$specialty) {
            // Busca o preço na nova tabela health_plan_procedures
            if ($appointment->solicitation->health_plan_id) {
                $healthPlan = HealthPlan::find($appointment->solicitation->health_plan_id);
                if ($healthPlan) {
                    $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }
            
            // Fallback para contratos de preços antigos
            $pricingContract = PricingContract::where('tuss_procedure_id', $appointment->solicitation->tuss_id)
                ->where('contractable_type', get_class($appointment->provider))
                ->where('contractable_id', $appointment->provider_id)
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('end_date')
                        ->orWhere('end_date', '>=', now());
                })
                ->first();
            
            return $pricingContract ? $pricingContract->price : 0;
        }

        // Tenta obter o preço na seguinte ordem:
        // 1. Preço específico do profissional
        $price = $specialty->getPriceForEntity('professional', $appointment->provider_id);
        if ($price) {
            return $price;
        }

        // 2. Preço específico da clínica
        if ($appointment->provider->clinic_id) {
            $price = $specialty->getPriceForEntity('clinic', $appointment->provider->clinic_id);
            if ($price) {
                return $price;
            }
        }

        // 3. Preço específico do plano de saúde (nova tabela)
        if ($appointment->solicitation->health_plan_id) {
            $healthPlan = HealthPlan::find($appointment->solicitation->health_plan_id);
            if ($healthPlan) {
                $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                if ($price !== null) {
                    return $price;
                }
            }
            
            // Fallback para especialidade
            $price = $specialty->getPriceForEntity('health_plan', $appointment->solicitation->health_plan_id);
            if ($price) {
                return $price;
            }
        }

        // 4. Preço padrão da especialidade
        if ($specialty->default_price) {
            return $specialty->default_price;
        }

        // 5. Busca o preço através dos contratos de preços antigos
        $pricingContract = PricingContract::where('tuss_procedure_id', $appointment->solicitation->tuss_id)
            ->where('contractable_type', get_class($appointment->provider))
            ->where('contractable_id', $appointment->provider_id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->first();
        
        return $pricingContract ? $pricingContract->price : 0;
    }

    /**
     * Exporta relatório de faturamento em CSV ou PDF
     */
    public function exportReport(Request $request)
    {
        $request->validate([
            'format' => 'required|in:csv,pdf',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'status' => 'nullable|string',
        ]);

        $query = BillingBatch::with(['billingItems', 'fiscalDocuments'])
            ->when($request->status, function ($q, $status) {
                return $q->where('status', $status);
            })
            ->when($request->date_from, function ($q, $date) {
                return $q->where('billing_date', '>=', $date);
            })
            ->when($request->date_to, function ($q, $date) {
                return $q->where('billing_date', '<=', $date);
            })
            ->orderBy('created_at', 'desc');

        $batches = $query->get();

        // @phpstan-ignore-next-line
        if ($request->format === 'csv') {
            /** @var \Carbon\Carbon $now */
            $now = now();
            $filename = 'relatorio_faturamento_' . $now->format('Y-m-d_H-i-s') . '.csv';
            
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];

            $callback = function() use ($batches) {
                $file = fopen('php://output', 'w');
                
                // CSV Headers
                fputcsv($file, [
                    'ID do Lote',
                    'Período Início',
                    'Período Fim',
                    'Valor Total',
                    'Status',
                    'Data de Criação',
                    'Quantidade de Itens'
                ]);

                foreach ($batches as $batch) {
                    fputcsv($file, [
                        $batch->id,
                        $batch->reference_period_start,
                        $batch->reference_period_end,
                        number_format((float) $batch->total_amount, 2, ',', '.'),
                        $batch->status,
                        $batch->created_at->format('d/m/Y H:i:s'),
                        $batch->billingItems->count()
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        }
        
        $filename = 'relatorio_faturamento_' . now()->format('Y-m-d_H-i-s') . '.pdf';
        
        // For now, return a simple text response
        // In a real implementation, you would use a PDF library like DomPDF or TCPDF
        $content = "Relatório de Faturamento\n\n";
        $content .= "Período: " . ($batches->first()?->reference_period_start ?? 'N/A') . " a " . ($batches->last()?->reference_period_end ?? 'N/A') . "\n";
        $content .= "Total de lotes: " . $batches->count() . "\n";
        $content .= "Valor total: R$ " . number_format((float) $batches->sum('total_amount'), 2, ',', '.') . "\n\n";

        foreach ($batches as $batch) {
            $content .= "Lote #{$batch->id}\n";
            $content .= "Período: {$batch->reference_period_start} a {$batch->reference_period_end}\n";
            $content .= "Valor: R$ " . number_format((float) $batch->total_amount, 2, ',', '.') . "\n";
            $content .= "Status: {$batch->status}\n";
            $content .= "Itens: {$batch->billingItems->count()}\n\n";
        }

        return response($content)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }



    /**
     * Lista as verificações de valores pendentes
     */
    public function pendingValueVerifications(Request $request)
    {
        $query = ValueVerification::with(['billingBatch', 'billingItem', 'appointment', 'requester'])
            ->when($request->status, function ($q, $status) {
                return $q->where('status', $status);
            })
            ->when($request->priority, function ($q, $priority) {
                return $q->where('priority', $priority);
            })
            ->when($request->value_type, function ($q, $type) {
                return $q->where('value_type', $type);
            })
            ->when($request->billing_batch_id, function ($q, $batchId) {
                return $q->where('billing_batch_id', $batchId);
            })
            ->when($request->overdue, function ($q) {
                return $q->overdue();
            })
            ->when($request->high_priority, function ($q) {
                return $q->highPriority();
            });

        $verifications = $query->orderBy('priority', 'desc')
            ->orderBy('due_date', 'asc')
            ->paginate($request->per_page ?? 15);

        return response()->json([
            'data' => $verifications->items(),
            'meta' => [
                'total' => $verifications->total(),
                'per_page' => $verifications->perPage(),
                'current_page' => $verifications->currentPage(),
                'last_page' => $verifications->lastPage(),
                'statistics' => ValueVerification::getStatistics()
            ]
        ]);
    }

    /**
     * Verifica um valor específico
     */
    public function verifyValue(Request $request, ValueVerification $verification)
    {
        $request->validate([
            'verified_value' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
            'auto_approve' => 'boolean'
        ]);

        try {
            DB::beginTransaction();

            // Check if verification can be auto-approved
            if ($request->auto_approve && $verification->canBeAutoApproved()) {
                $verification->autoApprove();
            } else {
                // Manual verification
                $verification->verify(
                    auth()->id(),
                    $request->verified_value,
                    $request->notes
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Valor verificado com sucesso',
                'data' => $verification->load(['billingBatch', 'billingItem', 'appointment'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao verificar valor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Rejeita um valor específico
     */
    public function rejectValue(Request $request, ValueVerification $verification)
    {
        $request->validate([
            'notes' => 'required|string|min:10'
        ]);

        try {
            DB::beginTransaction();

            $verification->reject(auth()->id(), $request->notes);

            DB::commit();

            return response()->json([
                'message' => 'Valor rejeitado com sucesso',
                'data' => $verification->load(['billingBatch', 'billingItem', 'appointment'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao rejeitar valor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cria verificação de valor para um item de cobrança
     */
    public function createValueVerification(Request $request, BillingItem $billingItem)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
            'priority' => 'nullable|in:low,medium,high,critical',
            'due_date' => 'nullable|date|after:today',
            'auto_approve_threshold' => 'nullable|numeric|min:0|max:100'
        ]);

        try {
            DB::beginTransaction();

            $verification = ValueVerification::createFromBillingItem(
                $billingItem,
                $request->reason
            );

            // Override default values if provided
            if ($request->priority) {
                $verification->priority = $request->priority;
            }
            if ($request->due_date) {
                $verification->due_date = $request->due_date;
            }
            if ($request->auto_approve_threshold) {
                $verification->auto_approve_threshold = $request->auto_approve_threshold;
            }

            $verification->save();

            DB::commit();

            return response()->json([
                'message' => 'Verificação de valor criada com sucesso',
                'data' => $verification->load(['billingBatch', 'billingItem', 'appointment'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao criar verificação de valor',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Processa verificações automáticas para um lote de cobrança
     */
    public function processAutoVerifications(BillingBatch $batch)
    {
        try {
            DB::beginTransaction();

            $processed = 0;
            $autoApproved = 0;
            $pending = 0;

            // Get all pending verifications for this batch
            $verifications = $batch->pendingValueVerifications;

            foreach ($verifications as $verification) {
                if ($verification->canBeAutoApproved()) {
                    $verification->autoApprove();
                    $autoApproved++;
                } else {
                    $pending++;
                }
                $processed++;
            }

            DB::commit();

            return response()->json([
                'message' => 'Processamento automático concluído',
                'data' => [
                    'processed' => $processed,
                    'auto_approved' => $autoApproved,
                    'pending' => $pending,
                    'batch_id' => $batch->id
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao processar verificações automáticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtém estatísticas de verificação de valores
     */
    public function valueVerificationStatistics(Request $request)
    {
        try {
            $query = ValueVerification::query();

            // Apply filters
            if ($request->billing_batch_id) {
                $query->where('billing_batch_id', $request->billing_batch_id);
            }
            if ($request->date_from) {
                $query->where('created_at', '>=', $request->date_from);
            }
            if ($request->date_to) {
                $query->where('created_at', '<=', $request->date_to);
            }

            $statistics = [
                'total' => $query->count(),
                'pending' => (clone $query)->where('status', ValueVerification::STATUS_PENDING)->count(),
                'verified' => (clone $query)->where('status', ValueVerification::STATUS_VERIFIED)->count(),
                'rejected' => (clone $query)->where('status', ValueVerification::STATUS_REJECTED)->count(),
                'auto_approved' => (clone $query)->where('status', ValueVerification::STATUS_AUTO_APPROVED)->count(),
                'overdue' => (clone $query)->overdue()->count(),
                'high_priority' => (clone $query)->highPriority()->count(),
            ];

            // Get average processing time
            $avgProcessingTime = (clone $query)
                ->whereNotNull('verified_at')
                ->whereNotNull('created_at')
                ->get()
                ->avg(function ($verification) {
                    return $verification->created_at->diffInHours($verification->verified_at);
                });

            $statistics['avg_processing_time_hours'] = round($avgProcessingTime ?? 0, 2);

            // Get value difference statistics
            $valueStats = (clone $query)
                ->whereNotNull('verified_value')
                ->get()
                ->map(function ($verification) {
                    return $verification->getDifferencePercentage();
                });

            $statistics['avg_difference_percentage'] = round($valueStats->avg() ?? 0, 2);
            $statistics['max_difference_percentage'] = round($valueStats->max() ?? 0, 2);

            return response()->json([
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao obter estatísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Envia notificação de cobrança emitida
     */
    public function sendBillingEmittedNotification(Request $request)
    {
        $request->validate([
            'billing_batch_id' => 'required|exists:billing_batches,id',
            'recipient_ids' => 'required|array',
            'recipient_ids.*' => 'exists:users,id'
        ]);

        try {
            $billingBatch = BillingBatch::with(['items.appointment.solicitation.patient'])->findOrFail($request->billing_batch_id);
            $recipients = User::whereIn('id', $request->recipient_ids)->get();
            
            // Get the first appointment from the batch for notification
            $firstItem = $billingBatch->items->first();
            $appointment = $firstItem ? $firstItem->appointment : null;
            
            // Send notification to each recipient
            foreach ($recipients as $recipient) {
                $recipient->notify(new BillingEmitted($billingBatch, $appointment));
            }

            return response()->json([
                'message' => 'Notificação de cobrança emitida enviada com sucesso',
                'data' => [
                    'recipients_count' => $recipients->count(),
                    'billing_batch_id' => $billingBatch->id,
                    'appointment_id' => $appointment?->id
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao enviar notificação de cobrança emitida',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Envia notificação relacionada a cobrança
     */
    public function sendNotification(Request $request)
    {
        $request->validate([
            'billing_batch_id' => 'required|exists:billing_batches,id',
            'message' => 'required|string|min:10',
            'type' => 'required|string|in:billing_notification,payment_reminder,gloss_notification,status_update,custom',
            'recipients' => 'required|string|in:all,patients,providers,financial,custom'
        ]);

        try {
            $billingBatch = BillingBatch::with(['items.appointment.solicitation.patient'])->findOrFail($request->billing_batch_id);
            
            // Get recipients based on selection
            $recipients = $this->getRecipients($request->recipients, $billingBatch);
            
            // Get the first billing item for notification
            $firstItem = $billingBatch->items->first();
            
            if (!$firstItem) {
                return response()->json([
                    'message' => 'Nenhum item de cobrança encontrado no lote',
                ], 422);
            }
            
            // Send notification to each recipient
            foreach ($recipients as $recipient) {
                $recipient->notify(new \App\Notifications\BillingCustomNotification(
                    $firstItem,
                    $request->message,
                    $request->type
                ));
            }

            return response()->json([
                'message' => 'Notificação enviada com sucesso',
                'data' => [
                    'recipients_count' => $recipients->count(),
                    'billing_batch_id' => $billingBatch->id,
                    'message' => $request->message,
                    'type' => $request->type
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao enviar notificação',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recipients based on selection
     */
    private function getRecipients(string $recipientsType, BillingBatch $billingBatch)
    {
        switch ($recipientsType) {
            case 'all':
                return User::role(['director', 'super_admin', 'financial', 'plan_admin'])->get();
                
            case 'patients':
                // Get patients from billing batch
                $patientIds = $billingBatch->items()
                    ->with('appointment.solicitation.patient')
                    ->get()
                    ->pluck('appointment.solicitation.patient.user_id')
                    ->filter();
                return User::whereIn('id', $patientIds)->get();
                
            case 'providers':
                // Get providers from billing batch
                $providerIds = $billingBatch->items()
                    ->with('appointment.provider')
                    ->get()
                    ->pluck('appointment.provider.user_id')
                    ->filter();
                return User::whereIn('id', $providerIds)->get();
                
            case 'financial':
                return User::role(['financial', 'financial_supervisor', 'director', 'super_admin'])->get();
                
            case 'custom':
                // For custom recipients, you might want to add a recipients_ids field to the request
                return User::role(['director', 'super_admin'])->get();
                
            default:
                return User::role(['director', 'super_admin'])->get();
        }
    }

    /**
     * Exclui um item de cobrança
     */
    public function deleteBillingItem(BillingItem $item)
    {
        try {
            // Check if user has permission to delete billing items
            if (!auth()->user()->hasRole(['network_manager', 'super_admin'])) {
                return response()->json([
                    'message' => 'Você não tem permissão para excluir itens de cobrança'
                ], 403);
            }

            // Check if item can be deleted (not paid, not processed)
            if ($item->status === 'paid' || $item->status === 'processed') {
                return response()->json([
                    'message' => 'Não é possível excluir itens já pagos ou processados'
                ], 422);
            }

            DB::beginTransaction();

            // Store item data for logging
            $itemData = [
                'id' => $item->id,
                'billing_batch_id' => $item->billing_batch_id,
                'description' => $item->description,
                'total_amount' => $item->total_amount,
                'deleted_by' => auth()->id(),
                'deleted_at' => now()
            ];

            // Delete the item
            $item->delete();

            // Update batch total amount
            $batch = $item->billingBatch;
            if ($batch) {
                $batch->total_amount = $batch->billingItems->sum('total_amount');
                $batch->save();
            }

            DB::commit();

            return response()->json([
                'message' => 'Item de cobrança excluído com sucesso',
                'data' => $itemData
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erro ao excluir item de cobrança',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 