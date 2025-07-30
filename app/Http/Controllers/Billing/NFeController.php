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
    protected NFEService $nfeService;

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
            $existingNFe = BillingBatch::where('entity_id', $appointment->solicitation->health_plan_id)
                ->whereHas('items', function($query) use ($appointmentId) {
                    $query->where('item_type', 'appointment')
                          ->where('item_id', $appointmentId);
                })
                ->whereNotNull('nfe_number')
                ->first();

            if ($existingNFe) {
                return response()->json([
                    'message' => 'Já existe uma nota fiscal para este agendamento',
                    'nfe_id' => $existingNFe->id
                ], 400);
            }

            // Buscar o lote de faturamento existente para este plano de saúde
            $billingBatch = BillingBatch::where('entity_id', $appointment->solicitation->health_plan_id)
                ->whereNull('nfe_number')
                ->whereHas('items', function($query) use ($appointmentId) {
                    $query->where('item_type', 'appointment')
                          ->where('item_id', intval($appointmentId));
                })->first();

            if (!$billingBatch) {
                return response()->json([
                    'message' => 'Lote de faturamento não encontrado para este agendamento'
                ], 400);
            }

            // Verificar se o lote tem regra de faturamento com NFe habilitada
            if (!$billingBatch->billingRule || !$billingBatch->billingRule->generate_nfe) {
                return response()->json([
                    'message' => 'Regra de faturamento não encontrada ou NFe não habilitada'
                ], 400);
            }

            // Gerar dados da NFe (sem enviar para SEFAZ ainda)
            $nfeData = [
                'billing_batch_id' => $billingBatch->id,
                'health_plan' => $appointment->solicitation->healthPlan,
                'patient' => $appointment->solicitation->patient,
                'procedure' => $appointment->solicitation->tuss,
                'amount' => $billingBatch->total_amount,
                'appointment_date' => $appointment->scheduled_date,
            ];

            // Gerar nNF (número da nota fiscal)
            $nNF = $billingBatch->nfe_number ?? null;
            // Gerar cNF (código numérico da nota fiscal) diferente de nNF
            $cNF = null;
            if ($nNF) {
                do {
                    $cNF = str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
                } while ($cNF == $nNF);
                $nfeData['cNF'] = $cNF;
            }

            // Criar NFe com status pending (sem enviar para SEFAZ)
            $nfeResult = $this->nfeService->createNFeDraft($nfeData);

            if (is_array($nfeResult) && isset($nfeResult['success']) && $nfeResult['success']) {
                // Atualizar lote com informações da NFe (status pending)
                $billingBatch->update([
                    'nfe_number' => $nfeResult['nfe_number'] ?? null,
                    'nfe_key' => $nfeResult['nfe_key'] ?? null,
                    'nfe_xml' => $nfeResult['xml_path'] ?? null,
                    'nfe_status' => 'pending', // Status inicial
                    'nfe_protocol' => null,
                    'nfe_authorization_date' => null,
                    'status' => 'pending', // Lote também fica pending até autorização
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Nota fiscal criada com sucesso. Aguardando envio para SEFAZ.',
                    'nfe_id' => $billingBatch->id,
                    'nfe_number' => $nfeResult['nfe_number'] ?? null,
                    'nfe_key' => $nfeResult['nfe_key'] ?? null,
                    'status' => 'pending',
                    'requires_sefaz_submission' => true
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
     * Send NFe to SEFAZ for authorization
     */
    public function sendToSefaz($id)
    {
        try {
            $nfe = BillingBatch::with(['healthPlan', 'items.procedure'])
                ->whereNotNull('nfe_number')
                ->findOrFail($id);

            if ($nfe->nfe_status !== 'pending') {
                return response()->json([
                    'message' => 'Apenas NFes com status pending podem ser enviadas para SEFAZ'
                ], 400);
            }

            // Enviar para SEFAZ usando o serviço
            $result = $this->nfeService->sendToSefaz($nfe);

            if ($result['success']) {
                // Atualizar status da NFe
                $nfe->update([
                    'nfe_status' => $result['status'] ?? 'authorized',
                    'nfe_protocol' => $result['protocol'] ?? null,
                    'nfe_authorization_date' => $result['authorization_date'] ?? now(),
                    'status' => 'completed', // Lote completado após autorização
                ]);

                return response()->json([
                    'message' => 'NFe enviada para SEFAZ com sucesso',
                    'nfe_id' => $nfe->id,
                    'status' => $result['status'] ?? 'authorized',
                    'protocol' => $result['protocol'] ?? null,
                    'authorization_date' => $result['authorization_date'] ?? now(),
                ]);
            } else {
                return response()->json([
                    'message' => 'Erro ao enviar NFe para SEFAZ: ' . $result['error']
                ], 500);
            }

        } catch (\Exception $e) {
            Log::error('Erro ao enviar NFe para SEFAZ', [
                'nfe_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erro interno ao enviar NFe para SEFAZ'
            ], 500);
        }
    }

    /**
     * Send multiple pending NFes to SEFAZ
     */
    public function sendMultipleToSefaz(Request $request)
    {
        try {
            $request->validate([
                'nfe_ids' => 'required|array',
                'nfe_ids.*' => 'integer|exists:billing_batches,id'
            ]);

            $nfeIds = $request->input('nfe_ids');
            $pendingNfes = BillingBatch::whereIn('id', $nfeIds)
                ->where('nfe_status', 'pending')
                ->whereNotNull('nfe_number')
                ->get();

            if ($pendingNfes->isEmpty()) {
                return response()->json([
                    'message' => 'Nenhuma NFe pendente encontrada para envio'
                ], 400);
            }

            $results = [];
            $successCount = 0;
            $errorCount = 0;

            foreach ($pendingNfes as $nfe) {
                try {
                    $result = $this->nfeService->sendToSefaz($nfe);
                    
                    if ($result['success']) {
                        $nfe->update([
                            'nfe_status' => $result['status'] ?? 'authorized',
                            'nfe_protocol' => $result['protocol'] ?? null,
                            'nfe_authorization_date' => $result['authorization_date'] ?? now(),
                            'status' => 'completed',
                        ]);
                        
                        $results[] = [
                            'nfe_id' => $nfe->id,
                            'nfe_number' => $nfe->nfe_number,
                            'status' => 'success',
                            'message' => 'Enviada com sucesso',
                            'protocol' => $result['protocol'] ?? null,
                        ];
                        $successCount++;
                    } else {
                        $results[] = [
                            'nfe_id' => $nfe->id,
                            'nfe_number' => $nfe->nfe_number,
                            'status' => 'error',
                            'message' => $result['error'] ?? 'Erro desconhecido',
                        ];
                        $errorCount++;
                    }
                } catch (\Exception $e) {
                    $results[] = [
                        'nfe_id' => $nfe->id,
                        'nfe_number' => $nfe->nfe_number,
                        'status' => 'error',
                        'message' => $e->getMessage(),
                    ];
                    $errorCount++;
                }
            }

            return response()->json([
                'message' => "Processamento concluído. {$successCount} enviadas com sucesso, {$errorCount} com erro.",
                'results' => $results,
                'summary' => [
                    'total' => count($pendingNfes),
                    'success' => $successCount,
                    'error' => $errorCount,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao enviar múltiplas NFes para SEFAZ', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erro interno ao processar envio para SEFAZ'
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

    /**
     * Get appointments eligible for NFe generation
     */
    public function getEligibleAppointments(Request $request)
    {
        try {
            $query = Appointment::with([
                'solicitation.healthPlan',
                'solicitation.patient',
                'solicitation.tuss',
                'provider.medicalSpecialty'
            ])
            ->where('status', 'completed')
            ->where('patient_attended', true)
            ->whereHas('solicitation', function($q) {
                $q->whereHas('healthPlan');
            });

            // Verificar se já existe NFe para o agendamento
            $query->whereNotExists(function($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('billing_batches')
                    ->join('billing_items', 'billing_batches.id', '=', 'billing_items.billing_batch_id')
                    ->whereRaw('billing_items.item_type = ?', 'appointment')
                    ->whereRaw('billing_items.item_id = appointments.id')
                    ->whereNotNull('billing_batches.nfe_number');
            });

            // Verificar se existe lote de faturamento criado para o agendamento
            $query->whereExists(function($subQuery) {
                $subQuery->select(DB::raw(1))
                    ->from('billing_batches')
                    ->join('billing_items', 'billing_batches.id', '=', 'billing_items.billing_batch_id')
                    ->whereRaw('billing_items.item_type = ?', [Appointment::class])
                    ->whereRaw('billing_items.item_id = appointments.id')
                    ->whereNull('billing_batches.nfe_number')
                    ->whereHas('billingRule', function($ruleQuery) {
                        $ruleQuery->where('is_active', true)
                                  ->where('generate_nfe', true);
                    });
            });

            // Filtrar por plano de saúde se especificado
            if ($request->has('health_plan_id')) {
                $query->whereHas('solicitation', function($q) use ($request) {
                    $q->where('health_plan_id', $request->health_plan_id);
                });
            }

            // Filtrar por data se especificado
            if ($request->has('date_from')) {
                $query->where('scheduled_date', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->where('scheduled_date', '<=', $request->date_to);
            }

            $appointments = $query->orderBy('scheduled_date', 'desc')
                ->paginate($request->get('per_page', 15));

            // Adicionar valor do lote de faturamento para cada agendamento
            $appointments->getCollection()->transform(function($appointment) {
                $billingBatch = BillingBatch::where('health_plan_id', $appointment->solicitation->health_plan_id)
                    ->whereNull('nfe_number')
                    ->whereHas('items', function($query) use ($appointment) {
                        $query->where('item_type', Appointment::class)
                              ->where('item_id', $appointment->id);
                    })
                    ->first();
                
                $appointment->amount = $billingBatch ? $billingBatch->total_amount : 0;
                return $appointment;
            });

            return response()->json($appointments);

        } catch (\Exception $e) {
            Log::error('Erro ao buscar agendamentos elegíveis para NFe', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Erro ao buscar agendamentos elegíveis'
            ], 500);
        }
    }
} 