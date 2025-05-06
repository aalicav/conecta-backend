<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'reference_id',
        'payable_id',
        'payable_type',
        'payment_type',
        'amount',
        'total_amount',
        'discount_amount',
        'gloss_amount',
        'status',
        'payment_method',
        'payment_gateway',
        'gateway_reference',
        'gateway_response',
        'currency',
        'paid_at',
        'due_date',
        'created_by',
        'processed_by',
        'notes',
        'metadata'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'gloss_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'due_date' => 'datetime',
        'gateway_response' => 'array',
        'metadata' => 'array'
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($payment) {
            // Generate a unique reference ID if not set
            if (!$payment->reference_id) {
                $payment->reference_id = (string) Str::uuid();
            }

            // Set total_amount if not explicitly set
            if (!$payment->total_amount) {
                $payment->total_amount = $payment->amount - $payment->discount_amount;
            }
        });

        static::updating(function ($payment) {
            // Update total amount when amount or discount changes
            if ($payment->isDirty(['amount', 'discount_amount', 'gloss_amount'])) {
                $payment->total_amount = $payment->amount - $payment->discount_amount - $payment->gloss_amount;
            }
        });
    }

    /**
     * Get the parent payable model.
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created this payment.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who processed this payment.
     */
    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * Get the refunds for this payment.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class);
    }

    /**
     * Get the glosses (rejections) for this payment.
     */
    public function glosses(): HasMany
    {
        return $this->hasMany(PaymentGloss::class);
    }

    /**
     * Process the payment.
     *
     * @param array $paymentDetails Payment processing details
     * @param int $processedBy User ID who processed the payment
     * @return self
     */
    public function process(array $paymentDetails, int $processedBy): self
    {
        $this->update([
            'status' => 'completed',
            'payment_method' => $paymentDetails['payment_method'] ?? $this->payment_method,
            'payment_gateway' => $paymentDetails['payment_gateway'] ?? $this->payment_gateway,
            'gateway_reference' => $paymentDetails['gateway_reference'] ?? $this->gateway_reference,
            'gateway_response' => $paymentDetails['gateway_response'] ?? $this->gateway_response,
            'paid_at' => now(),
            'processed_by' => $processedBy,
            'notes' => $paymentDetails['notes'] ?? $this->notes
        ]);

        return $this;
    }

    /**
     * Apply a gloss (reject part of the payment).
     *
     * @param array $glossData Gloss data
     * @param int $appliedBy User ID who applied the gloss
     * @return PaymentGloss
     */
    public function applyGloss(array $glossData, int $appliedBy): PaymentGloss
    {
        $gloss = $this->glosses()->create([
            'amount' => $glossData['amount'],
            'reason' => $glossData['reason'],
            'gloss_code' => $glossData['gloss_code'] ?? null,
            'is_appealable' => $glossData['is_appealable'] ?? true,
            'notes' => $glossData['notes'] ?? null,
            'applied_by' => $appliedBy
        ]);

        // Update the gloss amount on the payment
        $this->increment('gloss_amount', $gloss->amount);
        $this->decrement('total_amount', $gloss->amount);

        return $gloss;
    }

    /**
     * Refund a payment (partial or full).
     *
     * @param array $refundData Refund data
     * @param int $refundedBy User ID who issued the refund
     * @return PaymentRefund
     */
    public function refund(array $refundData, int $refundedBy): PaymentRefund
    {
        $refundAmount = $refundData['amount'];
        $totalRefunded = $this->refunds()->sum('amount');
        
        // Ensure refund amount does not exceed remaining refundable amount
        $maxRefundable = $this->total_amount - $totalRefunded;
        
        if ($refundAmount > $maxRefundable) {
            throw new \InvalidArgumentException("Refund amount exceeds maximum refundable amount of {$maxRefundable}");
        }

        // Create refund record
        $refund = $this->refunds()->create([
            'amount' => $refundAmount,
            'status' => 'completed',
            'reason' => $refundData['reason'],
            'gateway_reference' => $refundData['gateway_reference'] ?? null,
            'gateway_response' => $refundData['gateway_response'] ?? null,
            'refunded_by' => $refundedBy,
            'refunded_at' => now(),
            'notes' => $refundData['notes'] ?? null
        ]);

        // Update payment status based on refund amount
        if ($refundAmount + $totalRefunded >= $this->total_amount) {
            $this->update(['status' => 'refunded']);
        } else {
            $this->update(['status' => 'partially_refunded']);
        }

        return $refund;
    }

    /**
     * Calculate the refundable amount.
     *
     * @return float
     */
    public function getRefundableAmountAttribute(): float
    {
        $totalRefunded = $this->refunds()->where('status', 'completed')->sum('amount');
        return max(0, $this->total_amount - $totalRefunded);
    }

    /**
     * Check if the payment can be refunded.
     *
     * @return bool
     */
    public function canBeRefunded(): bool
    {
        return 
            in_array($this->status, ['completed', 'partially_refunded']) &&
            $this->refundable_amount > 0;
    }

    /**
     * Check if the payment is fully refunded.
     *
     * @return bool
     */
    public function isFullyRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    /**
     * Check if the payment is partially refunded.
     *
     * @return bool
     */
    public function isPartiallyRefunded(): bool
    {
        return $this->status === 'partially_refunded';
    }

    /**
     * Check if the payment is completed.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the payment is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the payment failed.
     *
     * @return bool
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get the original amount before discounts and glosses.
     */
    public function getOriginalAmountAttribute(): float
    {
        return (float) $this->amount;
    }

    /**
     * Get the amount after discounts but before glosses.
     */
    public function getDiscountedAmountAttribute(): float
    {
        return (float) ($this->amount - $this->discount_amount);
    }

    /**
     * Scope a query to only include payments of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('payment_type', $type);
    }

    /**
     * Scope a query to only include payments with a specific status.
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include completed payments.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include pending payments.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include payments due in the past.
     */
    public function scopeOverdue($query)
    {
        return $query->where('status', 'pending')
                     ->whereNotNull('due_date')
                     ->where('due_date', '<', now());
    }
} 