<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\HealthPlan;
use App\Models\HealthPlanBillingRule;
use App\Models\BillingBatch;
use App\Notifications\BillingNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessHealthPlanBilling extends Command
{
    protected $signature = 'billing:process-health-plans';
    protected $description = 'Process billing rules for health plans and generate invoices';

    public function handle()
    {
        $this->info('Starting health plan billing process...');

        try {
            // Get all active health plans with active billing rules
            $healthPlans = HealthPlan::whereHas('billingRules', function ($query) {
                $query->where('is_active', true);
            })->get();

            foreach ($healthPlans as $healthPlan) {
                $this->processHealthPlan($healthPlan);
            }

            $this->info('Health plan billing process completed successfully.');
        } catch (\Exception $e) {
            $this->error('Error processing health plan billing: ' . $e->getMessage());
            Log::error('Error in billing:process-health-plans command: ' . $e->getMessage());
        }
    }

    protected function processHealthPlan(HealthPlan $healthPlan)
    {
        $this->info("Processing health plan: {$healthPlan->name}");

        // Get active billing rules for the health plan
        $rules = $healthPlan->billingRules()
            ->where('is_active', true)
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($rules as $rule) {
            $this->processRule($healthPlan, $rule);
        }
    }

    protected function processRule(HealthPlan $healthPlan, HealthPlanBillingRule $rule)
    {
        $now = Carbon::now();

        try {
            DB::beginTransaction();

            switch ($rule->billing_type) {
                case 'monthly':
                    $this->processMonthlyBilling($healthPlan, $rule);
                    break;

                case 'weekly':
                    $this->processWeeklyBilling($healthPlan, $rule);
                    break;

                case 'batch':
                    $this->processBatchBilling($healthPlan, $rule);
                    break;

                case 'per_appointment':
                    $this->processPerAppointmentBilling($healthPlan, $rule);
                    break;
            }

            // Process notifications for existing billings
            $this->processNotifications($healthPlan, $rule);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error processing rule {$rule->id} for health plan {$healthPlan->id}: " . $e->getMessage());
            $this->error("Error processing rule {$rule->name}: " . $e->getMessage());
        }
    }

    protected function processMonthlyBilling(HealthPlan $healthPlan, HealthPlanBillingRule $rule)
    {
        $now = Carbon::now();
        $billingDay = $rule->billing_day;

        // Check if today is billing day
        if ($now->day == $billingDay) {
            // Get appointments since last billing
            $lastBilling = BillingBatch::where('health_plan_id', $healthPlan->id)
                ->where('billing_rule_id', $rule->id)
                ->latest()
                ->first();

            $startDate = $lastBilling ? 
                $lastBilling->period_end->addDay() : 
                $now->copy()->subMonth()->startOfDay();

            $endDate = $now->copy()->endOfDay();

            $this->generateBilling($healthPlan, $rule, $startDate, $endDate);
        }
    }

    protected function processWeeklyBilling(HealthPlan $healthPlan, HealthPlanBillingRule $rule)
    {
        $now = Carbon::now();
        $billingDay = $rule->billing_day; // 1 = Monday, 7 = Sunday

        // Check if today is billing day
        if ($now->dayOfWeek == $billingDay) {
            // Get appointments since last billing
            $lastBilling = BillingBatch::where('health_plan_id', $healthPlan->id)
                ->where('billing_rule_id', $rule->id)
                ->latest()
                ->first();

            $startDate = $lastBilling ? 
                $lastBilling->period_end->addDay() : 
                $now->copy()->subWeek()->startOfDay();

            $endDate = $now->copy()->endOfDay();

            $this->generateBilling($healthPlan, $rule, $startDate, $endDate);
        }
    }

    protected function processBatchBilling(HealthPlan $healthPlan, HealthPlanBillingRule $rule)
    {
        // Get unbilled appointments
        $appointments = $healthPlan->appointments()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('billing_batch_items')
                    ->whereColumn('billing_batch_items.appointment_id', 'appointments.id');
            })
            ->get();

        $totalAmount = $appointments->sum('amount');
        $appointmentCount = $appointments->count();

        // Check if thresholds are met
        if ($rule->shouldBillBatch($totalAmount, $appointmentCount)) {
            $this->generateBilling($healthPlan, $rule, $appointments->min('date'), $appointments->max('date'));
        }
    }

    protected function processPerAppointmentBilling(HealthPlan $healthPlan, HealthPlanBillingRule $rule)
    {
        // Get unbilled appointments
        $appointments = $healthPlan->appointments()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('billing_batch_items')
                    ->whereColumn('billing_batch_items.appointment_id', 'appointments.id');
            })
            ->get();

        foreach ($appointments as $appointment) {
            $this->generateBilling(
                $healthPlan, 
                $rule, 
                $appointment->date->startOfDay(), 
                $appointment->date->endOfDay(),
                [$appointment]
            );
        }
    }

    protected function generateBilling(
        HealthPlan $healthPlan, 
        HealthPlanBillingRule $rule, 
        Carbon $startDate, 
        Carbon $endDate,
        array $specificAppointments = null
    ) {
        // Get appointments for the period
        $appointments = $specificAppointments ?? $healthPlan->appointments()
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('billing_batch_items')
                    ->whereColumn('billing_batch_items.appointment_id', 'appointments.id');
            })
            ->get();

        $totalAmount = $appointments->sum('amount');

        // Check minimum billing amount
        if ($rule->minimum_billing_amount && $totalAmount < $rule->minimum_billing_amount) {
            return;
        }

        // Create billing batch
        $batch = BillingBatch::create([
            'health_plan_id' => $healthPlan->id,
            'billing_rule_id' => $rule->id,
            'period_start' => $startDate,
            'period_end' => $endDate,
            'amount' => $totalAmount,
            'status' => 'pending',
            'due_date' => $rule->calculateDueDate(Carbon::now()),
        ]);

        // Add appointments to batch
        foreach ($appointments as $appointment) {
            $batch->items()->create([
                'appointment_id' => $appointment->id,
                'amount' => $appointment->amount
            ]);
        }

        // Send notification if enabled
        if ($rule->notify_on_generation) {
            $healthPlan->notify(new BillingNotification(
                'billing_generated',
                $healthPlan,
                $batch,
                null,
                $totalAmount
            ));
        }
    }

    protected function processNotifications(HealthPlan $healthPlan, HealthPlanBillingRule $rule)
    {
        $now = Carbon::now();

        // Get active billing batches
        $batches = BillingBatch::where('health_plan_id', $healthPlan->id)
            ->where('billing_rule_id', $rule->id)
            ->where('status', 'pending')
            ->get();

        foreach ($batches as $batch) {
            // Due date notifications
            if ($rule->notify_before_due_date) {
                $daysUntilDue = $now->diffInDays($batch->due_date, false);
                if ($daysUntilDue == $rule->notify_days_before) {
                    $healthPlan->notify(new BillingNotification(
                        'payment_due',
                        $healthPlan,
                        $batch,
                        $daysUntilDue,
                        $batch->amount
                    ));
                }
            }

            // Late payment notifications
            if ($rule->notify_on_late_payment && $batch->due_date->isPast()) {
                $healthPlan->notify(new BillingNotification(
                    'payment_late',
                    $healthPlan,
                    $batch,
                    null,
                    $batch->amount
                ));
            }

            // Early payment discount notifications
            if ($rule->discount_percentage && $rule->discount_if_paid_until_days) {
                $discountDate = $batch->due_date->copy()->subDays($rule->discount_if_paid_until_days);
                if ($now->isSameDay($discountDate)) {
                    $healthPlan->notify(new BillingNotification(
                        'early_payment_discount',
                        $healthPlan,
                        $batch,
                        null,
                        $batch->amount
                    ));
                }
            }
        }
    }
} 