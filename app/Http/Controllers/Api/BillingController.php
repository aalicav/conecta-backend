<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillingBatch;
use App\Models\BillingItem;
use App\Models\Appointment;
use App\Models\PaymentGloss;
use App\Models\FiscalDocument;
use App\Notifications\BillingBatchCreated;
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

        return response()->json($query->paginate($request->per_page ?? 15));
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
                ->whereBetween('date', [
                    $request->reference_period_start,
                    $request->reference_period_end
                ])
                ->where('health_plan_id', $request->operator_id)
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
                    'billing_batch_id' => $batch->id,
                    'item_type' => 'appointment',
                    'item_id' => $appointment->id,
                    'description' => "Atendimento {$appointment->professional->specialty} - {$appointment->date}",
                    'unit_price' => $procedurePrice,
                    'total_amount' => $procedurePrice,
                    'tuss_code' => $appointment->procedure_code,
                    'tuss_description' => $appointment->procedure_description,
                    'professional_name' => $appointment->professional->name,
                    'professional_specialty' => $appointment->professional->specialty,
                    'patient_name' => $appointment->patient->name,
                    'patient_document' => $appointment->patient->document,
                    'patient_journey_data' => [
                        'scheduled_at' => $appointment->scheduled_at,
                        'pre_confirmation' => $appointment->pre_confirmation_response,
                        'patient_confirmed' => $appointment->patient_confirmed,
                        'professional_confirmed' => $appointment->professional_confirmed,
                        'guide_status' => $appointment->guide_status,
                        'patient_attended' => $appointment->patient_attended
                    ]
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
                Notification::send($batch->entity, new PaymentOverdue($batch, 'critical'));
                // Notifica supervisores
                Notification::send(User::role('financial_supervisor')->get(), new PaymentOverdue($batch, 'supervisor'));
            } elseif ($daysLate >= 15) {
                Notification::send($batch->entity, new PaymentOverdue($batch, 'urgent'));
            } else {
                Notification::send($batch->entity, new PaymentOverdue($batch, 'warning'));
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
                    return $q->where('health_plan_id', $operatorId);
                })
                ->where('date', '>=', now()->subMonths(6))
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
     * Obtém o preço do procedimento considerando a especialidade
     */
    private function getProcedurePrice($appointment)
    {
        // Se não for consulta (10101012), retorna o preço padrão do procedimento
        if ($appointment->procedure_code !== '10101012') {
            return $appointment->procedure_price;
        }

        // Verifica se o profissional tem especialidade definida
        if (!$appointment->professional->medical_specialty_id) {
            return $appointment->procedure_price;
        }

        $specialty = MedicalSpecialty::find($appointment->professional->medical_specialty_id);
        if (!$specialty) {
            return $appointment->procedure_price;
        }

        // Tenta obter o preço na seguinte ordem:
        // 1. Preço específico do profissional
        $price = $specialty->getPriceForEntity('professional', $appointment->professional_id);
        if ($price) {
            return $price;
        }

        // 2. Preço específico da clínica
        if ($appointment->clinic_id) {
            $price = $specialty->getPriceForEntity('clinic', $appointment->clinic_id);
            if ($price) {
                return $price;
            }
        }

        // 3. Preço específico do plano de saúde
        if ($appointment->health_plan_id) {
            $price = $specialty->getPriceForEntity('health_plan', $appointment->health_plan_id);
            if ($price) {
                return $price;
            }
        }

        // 4. Preço padrão da especialidade
        return $specialty->default_price ?? $appointment->procedure_price;
    }
} 