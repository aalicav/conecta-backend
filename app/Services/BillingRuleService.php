<?php

namespace App\Services;

use App\Models\BillingRule;
use App\Models\BillingBatch;
use App\Models\Appointment;
use App\Models\Notification;
use App\Services\WhatsAppService;
use App\Services\EmailService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingRuleService
{
    protected $whatsappService;
    protected $emailService;

    public function __construct(WhatsAppService $whatsappService, EmailService $emailService)
    {
        $this->whatsappService = $whatsappService;
        $this->emailService = $emailService;
    }

    /**
     * Process all active billing rules and generate batches if needed.
     */
    public function processBillingRules()
    {
        $rules = BillingRule::where('is_active', true)->get();

        foreach ($rules as $rule) {
            try {
                $this->processRule($rule);
            } catch (\Exception $e) {
                Log::error('Error processing billing rule: ' . $e->getMessage(), [
                    'rule_id' => $rule->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Process a single billing rule.
     */
    protected function processRule(BillingRule $rule)
    {
        if (!$this->shouldGenerateBatch($rule)) {
            return;
        }

        DB::beginTransaction();
        try {
            // Get appointments that need to be billed
            $appointments = $this->getAppointmentsForBilling($rule);

            if ($appointments->isEmpty()) {
                return;
            }

            // Create billing batch
            $batch = BillingBatch::create([
                'billing_rule_id' => $rule->id,
                'health_plan_id' => $rule->health_plan_id,
                'contract_id' => $rule->contract_id,
                'status' => 'pending',
                'total_amount' => 0,
                'due_date' => Carbon::now()->addDays($rule->payment_days),
            ]);

            // Add appointments to batch
            foreach ($appointments as $appointment) {
                $batch->items()->create([
                    'appointment_id' => $appointment->id,
                    'amount' => $appointment->procedure->price,
                    'status' => 'pending',
                ]);
            }

            // Update batch total
            $batch->update([
                'total_amount' => $batch->items()->sum('amount')
            ]);

            // Mark appointments as billed
            $appointments->each(function ($appointment) {
                $appointment->update(['billing_status' => 'billed']);
            });

            DB::commit();

            // Send notifications
            $this->sendNotifications($batch);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Check if a batch should be generated based on the rule's frequency.
     */
    protected function shouldGenerateBatch(BillingRule $rule): bool
    {
        $now = Carbon::now();

        switch ($rule->frequency) {
            case 'daily':
                return true;

            case 'weekly':
                return $now->dayOfWeek === 1; // Monday

            case 'monthly':
                return $now->day === $rule->monthly_day;

            default:
                return false;
        }
    }

    /**
     * Get appointments that need to be billed for a rule.
     */
    protected function getAppointmentsForBilling(BillingRule $rule)
    {
        return Appointment::where('health_plan_id', $rule->health_plan_id)
            ->where('contract_id', $rule->contract_id)
            ->where('billing_status', 'pending')
            ->where('status', 'completed')
            ->where('completed_at', '<=', Carbon::now())
            ->take($rule->batch_size)
            ->get();
    }

    /**
     * Send notifications about the generated batch.
     */
    protected function sendNotifications(BillingBatch $batch)
    {
        if (empty($batch->billingRule->notification_recipients)) {
            return;
        }

        try {
            // Database notification
            $this->createDatabaseNotification($batch);

            // Email notification
            $this->sendEmailNotification($batch);

            // WhatsApp notification
            $this->sendWhatsAppNotification($batch);

        } catch (\Exception $e) {
            Log::error('Error sending billing notifications: ' . $e->getMessage(), [
                'batch_id' => $batch->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create a database notification for the billing batch.
     */
    protected function createDatabaseNotification(BillingBatch $batch)
    {
        $healthPlan = $batch->healthPlan;
        $contract = $batch->contract;

        Notification::create([
            'type' => 'billing_batch_created',
            'title' => 'Nova Cobrança Gerada',
            'message' => "Foi gerada uma nova cobrança para o contrato {$contract->number} do plano {$healthPlan->name} no valor de R$ " . number_format($batch->total_amount, 2, ',', '.'),
            'data' => [
                'batch_id' => $batch->id,
                'health_plan_id' => $healthPlan->id,
                'contract_id' => $contract->id,
                'total_amount' => $batch->total_amount,
                'due_date' => $batch->due_date->format('Y-m-d'),
            ],
            'recipient_type' => 'role',
            'recipient_id' => 'plan_admin',
        ]);
    }

    /**
     * Send email notification for the billing batch.
     */
    protected function sendEmailNotification(BillingBatch $batch)
    {
        $healthPlan = $batch->healthPlan;
        $contract = $batch->contract;
        $recipients = $batch->billingRule->notification_recipients;

        $subject = "Nova Cobrança - {$healthPlan->name} - Contrato {$contract->number}";
        $content = view('emails.billing.batch-created', [
            'batch' => $batch,
            'healthPlan' => $healthPlan,
            'contract' => $contract,
        ])->render();

        foreach ($recipients as $email) {
            $this->emailService->send($email, $subject, $content);
        }
    }

    /**
     * Send WhatsApp notification for the billing batch.
     */
    protected function sendWhatsAppNotification(BillingBatch $batch)
    {
        $healthPlan = $batch->healthPlan;
        $contract = $batch->contract;

        $message = "Nova cobrança gerada:\n\n" .
            "Plano: {$healthPlan->name}\n" .
            "Contrato: {$contract->number}\n" .
            "Valor: R$ " . number_format($batch->total_amount, 2, ',', '.') . "\n" .
            "Vencimento: " . $batch->due_date->format('d/m/Y') . "\n\n" .
            "Acesse o sistema para mais detalhes.";

        // Send to all notification recipients
        foreach ($batch->billingRule->notification_recipients as $email) {
            // Get user by email to get their phone number
            $user = \App\Models\User::where('email', $email)->first();
            if ($user && $user->phone) {
                $this->whatsappService->sendMessage($user->phone, $message);
            }
        }
    }
} 