<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingBatch;
use App\Models\Appointment;
use App\Models\BillingItem;
use App\Models\Contract;
use App\Models\BillingRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\NFeService;

class NFeController extends Controller
{
    protected $nfeService;

    public function __construct(NFeService $nfeService)
    {
        $this->nfeService = $nfeService;
    }

    public function index(Request $request)
    {
        $query = BillingBatch::with(['healthPlan', 'contract'])
            ->whereNotNull('nfe_number')
            ->orderBy('created_at', 'desc');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nfe_number', 'like', "%{$search}%")
                    ->orWhere('nfe_key', 'like', "%{$search}%")
                    ->orWhereHas('healthPlan', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('contract', function ($q) use ($search) {
                        $q->where('number', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->has('status')) {
            $query->where('nfe_status', $request->status);
        }

        if ($request->has('start_date')) {
            $query->whereDate('nfe_authorization_date', '>=', $request->start_date);
        }

        if ($request->has('end_date')) {
            $query->whereDate('nfe_authorization_date', '<=', $request->end_date);
        }

        $nfes = $query->paginate(10);

        return response()->json($nfes);
    }

    public function show($id)
    {
        $nfe = BillingBatch::with(['healthPlan', 'contract', 'items.procedure'])
            ->whereNotNull('nfe_number')
            ->findOrFail($id);

        return response()->json($nfe);
    }

    public function downloadXML($id)
    {
        $nfe = BillingBatch::whereNotNull('nfe_number')
            ->findOrFail($id);

        if (!$nfe->nfe_xml) {
            return response()->json(['message' => 'XML não encontrado'], 404);
        }

        try {
            $xmlContent = Storage::get($nfe->nfe_xml);
            
            return response($xmlContent, 200, [
                'Content-Type' => 'application/xml',
                'Content-Disposition' => "attachment; filename=nfe-{$nfe->nfe_number}.xml",
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao baixar XML da NFe', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao baixar XML'], 500);
        }
    }

    public function cancel($id)
    {
        $nfe = BillingBatch::whereNotNull('nfe_number')
            ->findOrFail($id);

        if ($nfe->nfe_status !== 'authorized') {
            return response()->json([
                'message' => 'Apenas notas fiscais autorizadas podem ser canceladas',
            ], 400);
        }

        try {
            // Aqui você implementaria a lógica de cancelamento usando a biblioteca SPED-NFe
            // Por enquanto, apenas atualizamos o status
            $nfe->update([
                'nfe_status' => 'cancelled',
            ]);

            return response()->json([
                'message' => 'Nota fiscal cancelada com sucesso',
                'nfe' => $nfe,
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao cancelar nota fiscal'], 500);
        }
    }

    public function generateFromAppointment($appointmentId)
    {
        try {
            DB::beginTransaction();

            $appointment = Appointment::with([
                'solicitation.healthPlan',
                'solicitation.patient',
                'solicitation.tuss',
                'provider'
            ])->findOrFail($appointmentId);

            // Verificar se o agendamento está concluído
            if ($appointment->status !== 'completed') {
                return response()->json([
                    'message' => 'Apenas agendamentos concluídos podem gerar nota fiscal'
                ], 400);
            }

            // Verificar se já existe uma NFe para este agendamento
            $existingNFe = BillingBatch::where('entity_type', Appointment::class)
                ->where('entity_id', $appointmentId)
                ->whereNotNull('nfe_number')
                ->first();

            if ($existingNFe) {
                return response()->json([
                    'message' => 'Já existe uma nota fiscal para este agendamento',
                    'nfe_id' => $existingNFe->id
                ], 400);
            }

            // Buscar contrato ativo entre o plano de saúde e o profissional
            $contract = Contract::where('health_plan_id', $appointment->solicitation->health_plan_id)
                ->where('professional_id', $appointment->provider_id)
                ->where('status', 'active')
                ->first();

            if (!$contract) {
                return response()->json([
                    'message' => 'Não foi encontrado um contrato ativo para este agendamento'
                ], 400);
            }

            // Buscar regra de faturamento
            $billingRule = BillingRule::where('health_plan_id', $appointment->solicitation->health_plan_id)
                ->where('is_active', true)
                ->first();

            if (!$billingRule || !$billingRule->generate_nfe) {
                return response()->json([
                    'message' => 'Regra de faturamento não encontrada ou NFe não habilitada'
                ], 400);
            }

            // Calcular valor baseado no contrato ou preço padrão
            $amount = $contract->price ?? 0;

            // Criar lote de faturamento
            $billingBatch = BillingBatch::create([
                'entity_type' => Appointment::class,
                'entity_id' => $appointmentId,
                'reference_period_start' => $appointment->scheduled_date,
                'reference_period_end' => $appointment->scheduled_date,
                'billing_date' => now(),
                'due_date' => now()->addDays(30),
                'total_amount' => $amount,
                'status' => 'pending',
                'items_count' => 1,
                'created_by' => auth()->id(),
                'billing_rule_id' => $billingRule->id,
                'health_plan_id' => $appointment->solicitation->health_plan_id,
                'contract_id' => $contract->id,
            ]);

            // Criar item de faturamento
            BillingItem::create([
                'billing_batch_id' => $billingBatch->id,
                'procedure_id' => $appointment->solicitation->tuss_id,
                'description' => $appointment->solicitation->tuss->description,
                'quantity' => 1,
                'unit_price' => $amount,
                'total_price' => $amount,
                'tuss_code' => $appointment->solicitation->tuss->code,
            ]);

            // Gerar NFe usando o serviço
            $nfeData = [
                'billing_batch_id' => $billingBatch->id,
                'health_plan' => $appointment->solicitation->healthPlan,
                'patient' => $appointment->solicitation->patient,
                'procedure' => $appointment->solicitation->tuss,
                'amount' => $amount,
                'appointment_date' => $appointment->scheduled_date,
            ];

            $nfeResult = $this->nfeService->generateNFe($nfeData);

            if ($nfeResult['success']) {
                // Atualizar lote com informações da NFe
                $billingBatch->update([
                    'nfe_number' => $nfeResult['nfe_number'],
                    'nfe_key' => $nfeResult['nfe_key'],
                    'nfe_xml' => $nfeResult['xml_path'],
                    'nfe_status' => $nfeResult['status'],
                    'nfe_protocol' => $nfeResult['protocol'] ?? null,
                    'nfe_authorization_date' => $nfeResult['authorization_date'] ?? null,
                    'status' => 'completed',
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Nota fiscal gerada com sucesso',
                    'nfe_id' => $billingBatch->id,
                    'nfe_number' => $nfeResult['nfe_number'],
                    'nfe_key' => $nfeResult['nfe_key'],
                ]);
            } else {
                DB::rollBack();
                return response()->json([
                    'message' => 'Erro ao gerar nota fiscal: ' . $nfeResult['error']
                ], 500);
            }

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Erro ao gerar NFe do agendamento', [
                'appointment_id' => $appointmentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erro interno ao gerar nota fiscal'
            ], 500);
        }
    }
} 