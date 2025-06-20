<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class HealthPlanBillingRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'health_plan_id',
        'name',
        'description',
        'billing_type',
        'billing_day',
        'batch_threshold_amount',
        'batch_threshold_appointments',
        'payment_term_days',
        'minimum_billing_amount',
        'late_fee_percentage',
        'discount_percentage',
        'discount_if_paid_until_days',
        'notify_on_generation',
        'notify_before_due_date',
        'notify_days_before',
        'notify_on_late_payment',
        'is_active',
        'priority',
        'created_by'
    ];

    protected $casts = [
        'billing_day' => 'integer',
        'batch_threshold_amount' => 'decimal:2',
        'batch_threshold_appointments' => 'integer',
        'payment_term_days' => 'integer',
        'minimum_billing_amount' => 'decimal:2',
        'late_fee_percentage' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'discount_if_paid_until_days' => 'integer',
        'notify_on_generation' => 'boolean',
        'notify_before_due_date' => 'boolean',
        'notify_days_before' => 'integer',
        'notify_on_late_payment' => 'boolean',
        'is_active' => 'boolean',
        'priority' => 'integer'
    ];

    /**
     * Get the health plan that owns this billing rule.
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the user who created this billing rule.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Calculate the next billing date based on the rule configuration.
     */
    public function getNextBillingDate(): Carbon
    {
        $now = Carbon::now();

        switch ($this->billing_type) {
            case 'monthly':
                $nextDate = $now->copy()->startOfMonth()->addDays($this->billing_day - 1);
                if ($nextDate->isPast()) {
                    $nextDate->addMonth();
                }
                return $nextDate;

            case 'weekly':
                $nextDate = $now->copy()->startOfWeek()->addDays($this->billing_day - 1);
                if ($nextDate->isPast()) {
                    $nextDate->addWeek();
                }
                return $nextDate;

            case 'per_appointment':
                return $now; // Bill immediately

            case 'batch':
                return $now; // Bill when threshold is reached

            default:
                return $now;
        }
    }

    /**
     * Calculate the due date based on payment terms.
     */
    public function calculateDueDate(Carbon $billingDate): Carbon
    {
        return $billingDate->copy()->addDays($this->payment_term_days);
    }

    /**
     * Check if a batch should be billed based on thresholds.
     */
    public function shouldBillBatch(float $totalAmount, int $appointmentCount): bool
    {
        if ($this->billing_type !== 'batch') {
            return false;
        }

        return ($this->batch_threshold_amount && $totalAmount >= $this->batch_threshold_amount) ||
               ($this->batch_threshold_appointments && $appointmentCount >= $this->batch_threshold_appointments);
    }

    /**
     * Calculate applicable discount for early payment.
     */
    public function calculateDiscount(float $amount, Carbon $paymentDate, Carbon $dueDate): float
    {
        if (!$this->discount_percentage || !$this->discount_if_paid_until_days) {
            return 0;
        }

        $discountDate = $dueDate->copy()->subDays($this->discount_if_paid_until_days);
        if ($paymentDate->lte($discountDate)) {
            return $amount * ($this->discount_percentage / 100);
        }

        return 0;
    }

    /**
     * Calculate late fee if applicable.
     */
    public function calculateLateFee(float $amount, Carbon $paymentDate, Carbon $dueDate): float
    {
        if (!$this->late_fee_percentage || $paymentDate->lte($dueDate)) {
            return 0;
        }

        return $amount * ($this->late_fee_percentage / 100);
    }
} 