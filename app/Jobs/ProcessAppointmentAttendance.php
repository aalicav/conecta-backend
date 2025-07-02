<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Appointment;
use App\Models\BillingRule;
use App\Models\BillingBatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\HealthPlan;

class ProcessAppointmentAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            DB::beginTransaction();

            // Get all confirmed appointments that haven't been marked as attended/missed
            // and their scheduled date has passed
            $appointments = Appointment::where('status', 'confirmed')
                ->whereNull('patient_attended')
                ->where('scheduled_date', '<=', now())
                ->get();

            foreach ($appointments as $appointment) {
                // If we haven't marked it as missed by now, assume patient attended
                $appointment->patient_attended = true;
                $appointment->attendance_confirmed_at = now();
                $appointment->status = 'completed';
                $appointment->attendance_notes = 'Automatically marked as attended by system';

                // Check if appointment is eligible for billing
                if ($this->isEligibleForBilling($appointment)) {
                    $appointment->eligible_for_billing = true;
                    
                    // Get applicable billing rule
                    $billingRule = $this->getApplicableBillingRule($appointment);
                    
                    if ($billingRule) {
                        // If rule type is per_appointment, create billing batch immediately
                        if ($billingRule->rule_type === 'per_appointment') {
                            $this->createBillingBatch($appointment, $billingRule);
                        }
                    }
                }

                $appointment->save();
            }

            DB::commit();
            
            Log::info('ProcessAppointmentAttendance completed successfully', [
                'appointments_processed' => $appointments->count()
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in ProcessAppointmentAttendance job: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Check if appointment is eligible for billing
     */
    private function isEligibleForBilling(Appointment $appointment): bool
    {
        return $appointment->patient_confirmed &&
               $appointment->professional_confirmed &&
               $appointment->patient_attended === true &&
               $appointment->guide_status === 'approved';
    }

    /**
     * Get applicable billing rule for the appointment
     */
    private function getApplicableBillingRule(Appointment $appointment): ?BillingRule
    {
        // Try to get specific rule for the provider
        $rule = BillingRule::where('entity_type', get_class($appointment->provider))
            ->where('entity_id', $appointment->provider_id)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->first();

        if ($rule) {
            return $rule;
        }

        // Try to get global rule for the provider type
        $rule = BillingRule::where('entity_type', get_class($appointment->provider))
            ->whereNull('entity_id')
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->first();

        if ($rule) {
            return $rule;
        }

        // Try to get rule for the health plan
        $rule = BillingRule::where('entity_type', 'App\\Models\\HealthPlan')
            ->where('entity_id', $appointment->solicitation->health_plan_id)
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->first();

        if ($rule) {
            return $rule;
        }

        // Try to get global rule for health plans
        return BillingRule::where('entity_type', 'App\\Models\\HealthPlan')
            ->whereNull('entity_id')
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->first();
    }

    /**
     * Create a billing batch for the appointment
     */
    private function createBillingBatch(Appointment $appointment, BillingRule $rule): void
    {
        // Create billing batch
        $batch = BillingBatch::create([
            'billing_rule_id' => $rule->id,
            'entity_type' => 'health_plan',
            'entity_id' => $appointment->solicitation->health_plan_id,
            'reference_period_start' => $appointment->scheduled_date->startOfDay(),
            'reference_period_end' => $appointment->scheduled_date->endOfDay(),
            'billing_date' => now(),
            'due_date' => now()->addDays($rule->payment_term_days ?? 30),
            'status' => 'pending',
            'items_count' => 1,
            'total_amount' => $this->calculateAppointmentPrice($appointment),
            'created_by' => 1 // System user
        ]);

        // Create billing item
        $batch->billingItems()->create([
            'item_type' => 'appointment',
            'item_id' => $appointment->id,
            'description' => "Atendimento {$appointment->provider->specialty} - {$appointment->scheduled_date}",
            'unit_price' => $this->calculateAppointmentPrice($appointment),
            'total_amount' => $this->calculateAppointmentPrice($appointment),
            'tuss_code' => $appointment->procedure_code,
            'tuss_description' => $appointment->procedure_description,
            'professional_name' => $appointment->provider->name,
            'professional_specialty' => $appointment->provider->specialty,
            'patient_name' => $appointment->solicitation->patient->name,
            'patient_document' => $appointment->solicitation->patient->document,
            'patient_journey_data' => [
                'scheduled_at' => $appointment->scheduled_date,
                'pre_confirmation' => $appointment->pre_confirmation_response,
                'patient_confirmed' => $appointment->patient_confirmed,
                'professional_confirmed' => $appointment->professional_confirmed,
                'guide_status' => $appointment->guide_status,
                'patient_attended' => $appointment->patient_attended
            ]
        ]);

        // Update appointment with batch reference
        $appointment->billing_batch_id = $batch->id;
        $appointment->save();
    }

    /**
     * Calculate appointment price based on rules and contracts
     */
    private function calculateAppointmentPrice(Appointment $appointment): float
    {
        // If not a consultation (10101012), return standard procedure price
        if ($appointment->procedure_code !== '10101012') {
            // Busca o preço na nova tabela health_plan_procedures
            if ($appointment->solicitation && $appointment->solicitation->health_plan_id) {
                $healthPlan = HealthPlan::find($appointment->solicitation->health_plan_id);
                if ($healthPlan) {
                    $price = $healthPlan->getProcedurePrice($appointment->solicitation->tuss_id);
                    if ($price !== null) {
                        return $price;
                    }
                }
            }
            return $appointment->procedure_price;
        }

        // Check for specialty-specific pricing
        if ($appointment->provider->medical_specialty_id) {
            $specialty = $appointment->provider->medicalSpecialty;
            
            // Try to get price in order:
            // 1. Professional specific price
            $price = $specialty->getPriceForEntity('professional', $appointment->provider_id);
            if ($price) {
                return $price;
            }

            // 2. Clinic specific price
            if ($appointment->clinic_id) {
                $price = $specialty->getPriceForEntity('clinic', $appointment->clinic_id);
                if ($price) {
                    return $price;
                }
            }

            // 3. Health plan specific price (nova tabela)
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

            // 4. Specialty default price
            if ($specialty->default_price) {
                return $specialty->default_price;
            }
        }

        // Return standard procedure price if no specialty pricing found
        return $appointment->procedure_price;
    }
} 