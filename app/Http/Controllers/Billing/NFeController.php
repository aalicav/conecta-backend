<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingBatch;
use App\Models\Appointment;
use App\Models\BillingItem;
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
        $query = BillingBatch::with(['healthPlan'])
            ->whereNotNull('nfe_number')
            ->orderBy('created_at', 'desc');

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nfe_number', 'like', "%{$search}%")
                    ->orWhere('nfe_key', 'like', "%{$search}%")
                    ->orWhereHas('healthPlan', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
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

        $perPage = $request->get('per_page', 15);
        $nfes = $query->paginate($perPage);

        return response()->json($nfes);
    }

    public function show($id)
    {
        $nfe = BillingBatch::with(['healthPlan', 'items.procedure'])
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
            // Se o XML está armazenado como string no banco
            if (is_string($nfe->nfe_xml)) {
                $xmlContent = $nfe->nfe_xml;
            } else {
                // Se está armazenado como arquivo
                $xmlContent = Storage::get($nfe->nfe_xml);
            }
            
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

    public function cancel(Request $request, $id)
    {
        $nfe = BillingBatch::whereNotNull('nfe_number')
            ->findOrFail($id);

        if ($nfe->nfe_status !== 'issued' && $nfe->nfe_status !== 'authorized') {
            return response()->json([
                'message' => 'Apenas notas fiscais emitidas ou autorizadas podem ser canceladas',
            ], 400);
        }

        try {
            $reason = $request->input('reason', 'Cancelamento solicitado pelo contribuinte');
            
            // Usar o serviço para cancelar a NFe
            $result = $this->nfeService->cancelNFe($nfe, $reason);

            if ($result['success']) {
                return response()->json([
                    'message' => 'Nota fiscal cancelada com sucesso',
                    'protocol' => $result['protocol'],
                    'nfe' => $nfe->fresh(),
                ]);
            } else {
                return response()->json([
                    'message' => 'Erro ao cancelar nota fiscal: ' . $result['error']
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao cancelar nota fiscal'], 500);
        }
    }

    /**
     * Cancel NFe by substitution (for duplicate NFes)
     */
    public function cancelBySubstitution(Request $request, $id)
    {
        $nfe = BillingBatch::whereNotNull('nfe_number')
            ->findOrFail($id);

        if ($nfe->nfe_status !== 'issued' && $nfe->nfe_status !== 'authorized') {
            return response()->json([
                'message' => 'Apenas notas fiscais emitidas ou autorizadas podem ser canceladas por substituição',
            ], 400);
        }

        $request->validate([
            'substitute_nfe_key' => 'required|string|size:44',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            $substituteNFeKey = $request->input('substitute_nfe_key');
            $reason = $request->input('reason', 'Cancelamento por substituição - NFe duplicada');
            
            // Usar o serviço para cancelar a NFe por substituição
            $result = $this->nfeService->cancelNFeBySubstitution($nfe, $substituteNFeKey, $reason);

            if ($result['success']) {
                return response()->json([
                    'message' => 'Nota fiscal cancelada por substituição com sucesso',
                    'protocol' => $result['protocol'],
                    'substitute_nfe_key' => $result['substitute_nfe_key'],
                    'nfe' => $nfe->fresh(),
                ]);
            } else {
                return response()->json([
                    'message' => 'Erro ao cancelar nota fiscal por substituição: ' . $result['error']
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao cancelar NFe por substituição', [
                'nfe_id' => $id,
                'substitute_nfe_key' => $request->input('substitute_nfe_key'),
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao cancelar nota fiscal por substituição'], 500);
        }
    }

    /**
     * Find potential substitute NFes for cancellation by substitution
     */
    public function findSubstituteNFes($id)
    {
        $nfe = BillingBatch::with(['healthPlan'])
            ->whereNotNull('nfe_number')
            ->findOrFail($id);

        if ($nfe->nfe_status !== 'issued' && $nfe->nfe_status !== 'authorized') {
            return response()->json([
                'message' => 'Apenas notas fiscais emitidas ou autorizadas podem ter substitutas',
            ], 400);
        }

        try {
            // Buscar NFes que podem ser substitutas
            // Mesmo destinatário, mesmo valor (com tolerância), autorizadas, emitidas nas últimas 168 horas
            $emissionDate = $nfe->nfe_authorization_date ?? $nfe->created_at;
            $minDate = $emissionDate->subHours(168);
            $maxDate = $emissionDate->addHours(168);
            
            $tolerance = $nfe->total_amount * 0.01; // 1% de tolerância
            $minAmount = $nfe->total_amount - $tolerance;
            $maxAmount = $nfe->total_amount + $tolerance;

            $substituteNFes = BillingBatch::with(['healthPlan'])
                ->where('id', '!=', $nfe->id)
                ->where('health_plan_id', $nfe->health_plan_id)
                ->where('nfe_status', 'authorized')
                ->where('total_amount', '>=', $minAmount)
                ->where('total_amount', '<=', $maxAmount)
                ->whereNotNull('nfe_authorization_date')
                ->where('nfe_authorization_date', '>=', $minDate)
                ->where('nfe_authorization_date', '<=', $maxDate)
                ->orderBy('nfe_authorization_date', 'desc')
                ->limit(10)
                ->get();

            return response()->json([
                'nfe' => $nfe,
                'substitute_nfes' => $substituteNFes,
                'criteria' => [
                    'health_plan_id' => $nfe->health_plan_id,
                    'total_amount' => $nfe->total_amount,
                    'amount_tolerance' => $tolerance,
                    'date_range_hours' => 168,
                    'min_date' => $minDate,
                    'max_date' => $maxDate,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar NFes substitutas', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Erro ao buscar NFes substitutas'], 500);
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

            // Calcular valor do agendamento
            $amount = $this->calculateAppointmentPrice($appointment);

            // Buscar regra de faturamento
            $billingRule = BillingRule::where('health_plan_id', $appointment->solicitation->health_plan_id)
                ->where('is_active', true)
                ->first();

            if (!$billingRule || !$billingRule->generate_nfe) {
                return response()->json([
                    'message' => 'Regra de faturamento não encontrada ou NFe não habilitada'
                ], 400);
            }

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

            if (is_array($nfeResult) && isset($nfeResult['success']) && $nfeResult['success']) {
                // Atualizar lote com informações da NFe
                $billingBatch->update([
                    'nfe_number' => $nfeResult['nfe_number'] ?? null,
                    'nfe_key' => $nfeResult['nfe_key'] ?? null,
                    'nfe_xml' => $nfeResult['xml_path'] ?? null,
                    'nfe_status' => $nfeResult['status'] ?? 'pending',
                    'nfe_protocol' => $nfeResult['protocol'] ?? null,
                    'nfe_authorization_date' => $nfeResult['authorization_date'] ?? null,
                    'status' => 'completed',
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Nota fiscal gerada com sucesso',
                    'nfe_id' => $billingBatch->id,
                    'nfe_number' => $nfeResult['nfe_number'] ?? null,
                    'nfe_key' => $nfeResult['nfe_key'] ?? null,
                ]);
            } else {
                DB::rollBack();
                $errorMessage = is_array($nfeResult) ? ($nfeResult['error'] ?? 'Erro desconhecido') : 'Erro ao gerar nota fiscal';
                return response()->json([
                    'message' => 'Erro ao gerar nota fiscal: ' . $errorMessage
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

    /**
     * Calculate appointment price based on rules and contracts
     */
    private function calculateAppointmentPrice(Appointment $appointment): float
    {
        // Get the base procedure price, defaulting to 0 if null
        $basePrice = $appointment->procedure_price ?? 0.0;
        
        // If not a consultation (10101012), return standard procedure price
        if ($appointment->solicitation->tuss->code !== '10101012') {
            // Busca o preço na nova tabela health_plan_procedures
            if ($appointment->solicitation && $appointment->solicitation->health_plan_id) {
                $healthPlan = \App\Models\HealthPlan::find($appointment->solicitation->health_plan_id);
                if ($healthPlan) {
                    $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                    if ($price !== null) {
                        return (float) $price;
                    }
                }
            }
            return (float) $basePrice;
        }

        // Check for specialty-specific pricing
        if ($appointment->provider && $appointment->provider->medical_specialty_id) {
            $specialty = $appointment->provider->medicalSpecialty;
            
            if ($specialty) {
                // Try to get price in order:
                // 1. Professional specific price
                $price = $specialty->getPriceForEntity('professional', $appointment->provider_id);
                if ($price !== null && $price > 0) {
                    return (float) $price;
                }

                // 2. Clinic specific price
                if ($appointment->clinic_id) {
                    $price = $specialty->getPriceForEntity('clinic', $appointment->clinic_id);
                    if ($price !== null && $price > 0) {
                        return (float) $price;
                    }
                }

                // 3. Health plan specific price (nova tabela)
                if ($appointment->solicitation && $appointment->solicitation->health_plan_id) {
                    $healthPlan = \App\Models\HealthPlan::find($appointment->solicitation->health_plan_id);
                    if ($healthPlan) {
                        $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                        if ($price !== null && $price > 0) {
                            return (float) $price;
                        }
                    }
                    
                    // Fallback para especialidade
                    $price = $specialty->getPriceForEntity('health_plan', $appointment->solicitation->health_plan_id);
                    if ($price !== null && $price > 0) {
                        return (float) $price;
                    }
                }

                // 4. Specialty default price
                if ($specialty->default_price && $specialty->default_price > 0) {
                    return (float) $specialty->default_price;
                }
            }
        }

        // Return standard procedure price if no specialty pricing found
        return (float) $basePrice;
    }
} 