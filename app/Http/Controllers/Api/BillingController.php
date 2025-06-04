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
                'due_date' => now()->addDays(30), // Configurável pela regra de faturamento
                'status' => 'pending',
                'created_by' => auth()->id()
            ]);

            // Cria os itens do lote
            foreach ($appointments as $appointment) {
                BillingItem::create([
                    'billing_batch_id' => $batch->id,
                    'item_type' => 'appointment',
                    'item_id' => $appointment->id,
                    'description' => "Atendimento {$appointment->professional->specialty} - {$appointment->date}",
                    'unit_price' => $appointment->procedure_price,
                    'total_amount' => $appointment->procedure_price,
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
            }

            // Atualiza totais do lote
            $batch->update([
                'items_count' => $appointments->count(),
                'total_amount' => $appointments->sum('procedure_price')
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
            ->get();

        foreach ($overdueBatches as $batch) {
            $batch->update([
                'is_late' => true,
                'days_late' => now()->diffInDays($batch->due_date)
            ]);

            // Notifica sobre o atraso
            Notification::send($batch->entity, new PaymentOverdue($batch));
        }

        return response()->json(['message' => 'Verificação de atrasos concluída']);
    }

    /**
     * Provides an overview of billing information including totals and recent activity
     */
    public function overview(Request $request)
    {
        try {
            // Get recent billing batches
            $recentBatches = BillingBatch::with(['billingItems', 'fiscalDocuments'])
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get();

            // Calculate overall statistics
            $totalStats = [
                'pending_amount' => BillingBatch::where('payment_status', 'pending')->sum('total_amount'),
                'paid_amount' => BillingBatch::where('payment_status', 'paid')->sum('total_amount'),
                'total_batches' => BillingBatch::count(),
                'pending_batches' => BillingBatch::where('payment_status', 'pending')->count(),
                'overdue_batches' => BillingBatch::where('payment_status', 'pending')
                    ->where('due_date', '<', now())
                    ->count()
            ];

            // Get payment status distribution
            $paymentStatusDistribution = BillingBatch::select('payment_status', DB::raw('count(*) as total'))
                ->groupBy('payment_status')
                ->get();

            // Get monthly billing totals for the last 6 months
            $monthlyTotals = BillingBatch::select(
                    DB::raw('DATE_FORMAT(billing_date, "%Y-%m") as month'),
                    DB::raw('SUM(total_amount) as total_amount'),
                    DB::raw('COUNT(*) as batch_count')
                )
                ->where('billing_date', '>=', now()->subMonths(6))
                ->groupBy(DB::raw('DATE_FORMAT(billing_date, "%Y-%m")'))
                ->orderBy('month', 'desc')
                ->get();

            // Get glosa statistics
            $glosaStats = PaymentGloss::select(
                    DB::raw('SUM(amount) as total_glosa_amount'),
                    DB::raw('COUNT(*) as total_glosas'),
                    DB::raw('COUNT(CASE WHEN resolution_status = "pending" THEN 1 END) as pending_glosas')
                )
                ->first();

            return response()->json([
                'total_statistics' => $totalStats,
                'recent_batches' => $recentBatches,
                'payment_status_distribution' => $paymentStatusDistribution,
                'monthly_totals' => $monthlyTotals,
                'glosa_statistics' => $glosaStats
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao obter visão geral do faturamento',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 