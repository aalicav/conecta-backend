<?php

namespace App\Services;

use App\Models\Deliberation;
use App\Models\BillingItem;
use App\Models\BillingBatch;
use App\Models\HealthPlanBillingRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DeliberationBillingService
{
    /**
     * Process approved deliberations for billing.
     */
    public function processApprovedDeliberations(): array
    {
        $processed = [];
        $errors = [];

        try {
            // Get all approved deliberations that haven't been billed yet
            $deliberations = Deliberation::approved()
                ->whereNull('billing_item_id')
                ->with(['healthPlan', 'clinic', 'medicalSpecialty', 'tussProcedure'])
                ->get();

            foreach ($deliberations as $deliberation) {
                try {
                    $billingItem = $this->createBillingItem($deliberation);
                    $deliberation->markAsBilled($billingItem->id);
                    $processed[] = $deliberation;
                } catch (\Exception $e) {
                    $errors[] = [
                        'deliberation_id' => $deliberation->id,
                        'deliberation_number' => $deliberation->deliberation_number,
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error('Failed to process deliberation for billing', [
                        'deliberation_id' => $deliberation->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            return [
                'processed' => $processed,
                'errors' => $errors,
                'total_processed' => count($processed),
                'total_errors' => count($errors)
            ];
        } catch (\Exception $e) {
            Log::error('Failed to process approved deliberations', [
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    /**
     * Create a billing item for a deliberation.
     */
    protected function createBillingItem(Deliberation $deliberation): BillingItem
    {
        DB::beginTransaction();

        try {
            // Find or create billing batch for the health plan
            $billingBatch = $this->getOrCreateBillingBatch($deliberation);

            // Create billing item
            $billingItem = BillingItem::create([
                'billing_batch_id' => $billingBatch->id,
                'item_type' => 'deliberation',
                'item_id' => $deliberation->id,
                'reference_type' => 'App\\Models\\Deliberation',
                'reference_id' => $deliberation->id,
                'description' => $this->generateDescription($deliberation),
                'quantity' => 1,
                'unit_price' => $deliberation->total_value,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $deliberation->total_value,
                'status' => 'pending',
                'notes' => $this->generateNotes($deliberation),
                'verified_by_operator' => false,
                'patient_journey_data' => $this->generatePatientJourneyData($deliberation),
                'tuss_code' => $deliberation->tussProcedure?->code,
                'tuss_description' => $deliberation->tussProcedure?->description,
                'professional_name' => $deliberation->professional?->name,
                'professional_specialty' => $deliberation->medicalSpecialty->name,
                'patient_name' => $this->getPatientName($deliberation),
                'patient_document' => $this->getPatientDocument($deliberation),
            ]);

            DB::commit();

            return $billingItem;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get or create billing batch for the health plan.
     */
    protected function getOrCreateBillingBatch(Deliberation $deliberation): BillingBatch
    {
        // Check if there's an active billing batch for this health plan
        $billingBatch = BillingBatch::where('health_plan_id', $deliberation->health_plan_id)
            ->where('status', 'draft')
            ->where('billing_date', '>=', Carbon::now()->startOfDay())
            ->first();

        if (!$billingBatch) {
            // Create new billing batch
            $billingBatch = BillingBatch::create([
                'health_plan_id' => $deliberation->health_plan_id,
                'batch_number' => $this->generateBatchNumber($deliberation->health_plan_id),
                'billing_date' => Carbon::now(),
                'status' => 'draft',
                'total_amount' => 0,
                'total_items' => 0,
                'created_by' => 1, // System user
                'notes' => 'Lote criado automaticamente para deliberações'
            ]);
        }

        return $billingBatch;
    }

    /**
     * Generate billing item description.
     */
    protected function generateDescription(Deliberation $deliberation): string
    {
        $description = "Deliberação {$deliberation->deliberation_number} - ";
        $description .= "{$deliberation->medicalSpecialty->name} - ";
        $description .= "{$deliberation->clinic->name}";
        
        if ($deliberation->professional) {
            $description .= " - {$deliberation->professional->name}";
        }

        if ($deliberation->tussProcedure) {
            $description .= " - {$deliberation->tussProcedure->code}";
        }

        return $description;
    }

    /**
     * Generate billing item notes.
     */
    protected function generateNotes(Deliberation $deliberation): string
    {
        $notes = "Deliberação de valor avulso\n";
        $notes .= "Motivo: {$deliberation->reason_label}\n";
        $notes .= "Justificativa: {$deliberation->justification}\n";
        $notes .= "Valor negociado: R$ " . number_format((float) $deliberation->negotiated_value, 2, ',', '.') . "\n";
        $notes .= "Percentual Medlar: {$deliberation->medlar_percentage}%\n";
        $notes .= "Valor Medlar: R$ " . number_format((float) $deliberation->medlar_amount, 2, ',', '.') . "\n";
        
        if ($deliberation->original_table_value) {
            $notes .= "Valor original da tabela: R$ " . number_format((float) $deliberation->original_table_value, 2, ',', '.') . "\n";
        }

        if ($deliberation->notes) {
            $notes .= "Observações: {$deliberation->notes}\n";
        }

        return $notes;
    }

    /**
     * Generate patient journey data.
     */
    protected function generatePatientJourneyData(Deliberation $deliberation): array
    {
        return [
            'deliberation_id' => $deliberation->id,
            'deliberation_number' => $deliberation->deliberation_number,
            'reason' => $deliberation->reason,
            'requires_operator_approval' => $deliberation->requires_operator_approval,
            'operator_approved' => $deliberation->operator_approved,
            'created_at' => $deliberation->created_at->toISOString(),
            'approved_at' => $deliberation->approved_at?->toISOString(),
        ];
    }

    /**
     * Get patient name from related appointment or solicitation.
     */
    protected function getPatientName(Deliberation $deliberation): ?string
    {
        if ($deliberation->appointment?->patient) {
            return $deliberation->appointment->patient->name;
        }

        if ($deliberation->solicitation?->patient) {
            return $deliberation->solicitation->patient->name;
        }

        return null;
    }

    /**
     * Get patient document from related appointment or solicitation.
     */
    protected function getPatientDocument(Deliberation $deliberation): ?string
    {
        if ($deliberation->appointment?->patient) {
            return $deliberation->appointment->patient->document;
        }

        if ($deliberation->solicitation?->patient) {
            return $deliberation->solicitation->patient->document;
        }

        return null;
    }

    /**
     * Generate batch number.
     */
    protected function generateBatchNumber(int $healthPlanId): string
    {
        $date = Carbon::now()->format('Ymd');
        $sequence = BillingBatch::where('health_plan_id', $healthPlanId)
            ->whereDate('created_at', Carbon::today())
            ->count() + 1;

        return "DEL-{$healthPlanId}-{$date}-" . str_pad($sequence, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Get deliberations statistics for billing.
     */
    public function getBillingStatistics(): array
    {
        $totalDeliberations = Deliberation::count();
        $approvedDeliberations = Deliberation::approved()->count();
        $billedDeliberations = Deliberation::billed()->count();
        $pendingBilling = Deliberation::approved()->whereNull('billing_item_id')->count();

        $totalValue = Deliberation::billed()->sum('total_value');
        $pendingValue = Deliberation::approved()
            ->whereNull('billing_item_id')
            ->sum('total_value');

        return [
            'total_deliberations' => $totalDeliberations,
            'approved_deliberations' => $approvedDeliberations,
            'billed_deliberations' => $billedDeliberations,
            'pending_billing' => $pendingBilling,
            'total_value_billed' => $totalValue,
            'pending_value' => $pendingValue,
            'billing_rate' => $approvedDeliberations > 0 ? ($billedDeliberations / $approvedDeliberations) * 100 : 0
        ];
    }

    /**
     * Mark deliberations as billed in a specific batch.
     */
    public function markDeliberationsAsBilledInBatch(BillingBatch $billingBatch): int
    {
        $billingItems = $billingBatch->billingItems()
            ->where('item_type', 'deliberation')
            ->get();

        $count = 0;
        foreach ($billingItems as $billingItem) {
            $deliberation = Deliberation::find($billingItem->item_id);
            if ($deliberation && !$deliberation->isBilled()) {
                $deliberation->markAsBilled($billingItem->id, $billingBatch->batch_number);
                $count++;
            }
        }

        return $count;
    }
}
