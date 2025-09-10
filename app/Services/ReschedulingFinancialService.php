<?php

namespace App\Services;

use App\Models\AppointmentRescheduling;
use App\Models\BillingItem;
use App\Models\BillingBatch;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReschedulingFinancialService
{
    /**
     * Process financial impact of rescheduling
     */
    public function processFinancialImpact(AppointmentRescheduling $rescheduling): bool
    {
        try {
            DB::beginTransaction();

            // Only process if there's financial impact
            if (!$rescheduling->financial_impact) {
                DB::commit();
                return true;
            }

            $originalAppointment = $rescheduling->originalAppointment;
            $newAppointment = $rescheduling->newAppointment;

            // Remove original appointment from billing if it was eligible
            if ($originalAppointment->eligible_for_billing && $originalAppointment->billing_batch_id) {
                $this->removeFromBilling($originalAppointment);
            }

            // Add new appointment to billing if it should be eligible
            if ($this->shouldBeEligibleForBilling($newAppointment)) {
                $this->addToBilling($newAppointment);
            }

            // Update financial amounts
            $this->updateFinancialAmounts($rescheduling);

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing rescheduling financial impact: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove appointment from billing
     */
    protected function removeFromBilling(Appointment $appointment): void
    {
        $billingItem = BillingItem::where('item_type', 'appointment')
            ->where('item_id', $appointment->id)
            ->first();

        if ($billingItem) {
            // Update batch totals
            $batch = $billingItem->billingBatch;
            if ($batch) {
                $batch->total_amount = $batch->total_amount - $billingItem->total_amount;
                $batch->items_count = $batch->items_count - 1;
                $batch->save();
            }

            // Delete billing item
            $billingItem->delete();

            // Update appointment
            $appointment->update([
                'eligible_for_billing' => false,
                'billing_batch_id' => null
            ]);
        }
    }

    /**
     * Add appointment to billing
     */
    protected function addToBilling(Appointment $appointment): void
    {
        // Check if appointment is already in billing
        $existingItem = BillingItem::where('item_type', 'appointment')
            ->where('item_id', $appointment->id)
            ->first();

        if ($existingItem) {
            return; // Already in billing
        }

        // Get or create billing batch for the health plan
        $batch = $this->getOrCreateBillingBatch($appointment);

        // Calculate billing amount
        $amount = $this->calculateBillingAmount($appointment);

        // Create billing item
        BillingItem::create([
            'billing_batch_id' => $batch->id,
            'item_type' => 'appointment',
            'item_id' => $appointment->id,
            'description' => "Reagendamento - {$appointment->provider->specialty} - {$appointment->scheduled_date}",
            'quantity' => 1,
            'unit_price' => $amount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $amount,
            'status' => 'pending',
            'notes' => 'Reagendamento aprovado',
            'reference_type' => 'rescheduling',
            'reference_id' => $appointment->rescheduledFrom->id ?? null,
            'verified_by_operator' => false,
            'verified_at' => null,
            'verification_user' => null,
            'verification_notes' => null,
            'patient_journey_data' => [
                'scheduled_at' => $appointment->scheduled_date,
                'pre_confirmation' => $appointment->pre_confirmation_response,
                'patient_confirmed' => $appointment->patient_confirmed,
                'professional_confirmed' => $appointment->professional_confirmed,
                'guide_status' => $appointment->guide_status,
                'patient_attended' => $appointment->patient_attended,
                'rescheduled' => true,
                'original_appointment_id' => $appointment->rescheduledFrom->original_appointment_id ?? null
            ],
            'tuss_code' => $appointment->solicitation->tuss->code,
            'tuss_description' => $appointment->solicitation->tuss->description,
            'professional_name' => $appointment->provider->name,
            'professional_specialty' => $appointment->provider->specialty,
            'patient_name' => $appointment->solicitation->patient->name,
            'patient_document' => $appointment->solicitation->patient->cpf,
        ]);

        // Update batch totals
        $batch->total_amount = $batch->total_amount + $amount;
        $batch->items_count = $batch->items_count + 1;
        $batch->save();

        // Update appointment
        $appointment->update([
            'eligible_for_billing' => true,
            'billing_batch_id' => $batch->id
        ]);
    }

    /**
     * Get or create billing batch for appointment
     */
    protected function getOrCreateBillingBatch(Appointment $appointment): BillingBatch
    {
        $healthPlanId = $appointment->solicitation->health_plan_id;
        $billingDate = now()->format('Y-m-d');

        // Try to find existing batch for today
        $batch = BillingBatch::where('entity_type', 'health_plan')
            ->where('entity_id', $healthPlanId)
            ->where('billing_date', $billingDate)
            ->where('status', 'pending')
            ->first();

        if (!$batch) {
            // Create new batch
            $batch = BillingBatch::create([
                'entity_type' => 'health_plan',
                'entity_id' => $healthPlanId,
                'reference_period_start' => now()->startOfDay(),
                'reference_period_end' => now()->endOfDay(),
                'billing_date' => $billingDate,
                'due_date' => now()->addDays(30),
                'status' => 'pending',
                'total_amount' => 0,
                'items_count' => 0,
                'created_by' => auth()->id() ?? 1
            ]);
        }

        return $batch;
    }

    /**
     * Calculate billing amount for appointment
     */
    protected function calculateBillingAmount(Appointment $appointment): float
    {
        // This would integrate with your existing billing logic
        // For now, return a placeholder based on the rescheduling
        $rescheduling = $appointment->rescheduledFrom;
        
        if ($rescheduling && $rescheduling->new_amount) {
            return (float) $rescheduling->new_amount;
        }

        // Fallback to original billing calculation
        return 0; // This should be replaced with actual billing calculation
    }

    /**
     * Check if appointment should be eligible for billing
     */
    protected function shouldBeEligibleForBilling(Appointment $appointment): bool
    {
        // Check if appointment meets billing criteria
        return $appointment->patient_confirmed &&
               $appointment->professional_confirmed &&
               $appointment->patient_attended === true &&
               $appointment->guide_status === 'approved';
    }

    /**
     * Update financial amounts in rescheduling record
     */
    protected function updateFinancialAmounts(AppointmentRescheduling $rescheduling): void
    {
        $originalAmount = $this->calculateBillingAmount($rescheduling->originalAppointment);
        $newAmount = $this->calculateBillingAmount($rescheduling->newAppointment);

        $rescheduling->update([
            'original_amount' => $originalAmount,
            'new_amount' => $newAmount,
            'financial_impact' => $originalAmount !== $newAmount
        ]);
    }

    /**
     * Reverse financial impact (for rejected reschedulings)
     */
    public function reverseFinancialImpact(AppointmentRescheduling $rescheduling): bool
    {
        try {
            DB::beginTransaction();

            // Remove new appointment from billing if it was added
            if ($rescheduling->newAppointment->eligible_for_billing) {
                $this->removeFromBilling($rescheduling->newAppointment);
            }

            // Restore original appointment to billing if it should be eligible
            if ($this->shouldBeEligibleForBilling($rescheduling->originalAppointment)) {
                $this->addToBilling($rescheduling->originalAppointment);
            }

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error reversing rescheduling financial impact: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get financial impact summary for rescheduling
     */
    public function getFinancialImpactSummary(AppointmentRescheduling $rescheduling): array
    {
        $originalAmount = $rescheduling->original_amount ?? 0;
        $newAmount = $rescheduling->new_amount ?? 0;
        $difference = $newAmount - $originalAmount;

        return [
            'original_amount' => $originalAmount,
            'new_amount' => $newAmount,
            'difference' => $difference,
            'percentage_change' => $originalAmount > 0 ? ($difference / $originalAmount) * 100 : 0,
            'has_impact' => $difference !== 0,
            'impact_type' => $difference > 0 ? 'increase' : ($difference < 0 ? 'decrease' : 'none')
        ];
    }
}
