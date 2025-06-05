<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentGloss extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'billing_item_id',
        'amount',
        'reason',
        'status',
        'resolution_notes',
        'resolved_at',
        'resolved_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'amount' => 'decimal:2',
        'resolved_at' => 'datetime'
    ];

    /**
     * Get the billing item that owns the gloss.
     */
    public function billingItem(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class);
    }

    /**
     * Get the user who resolved the gloss.
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the payment this gloss belongs to.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the user who applied this gloss.
     */
    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * Get the user who reverted this gloss.
     */
    public function revertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    /**
     * Revert this gloss.
     *
     * @param int $revertedBy User ID who reverted the gloss
     * @param string|null $notes Notes about the reversion
     * @return self
     */
    public function revert(int $revertedBy, ?string $notes = null): self
    {
        // Only applied glosses can be reverted
        if ($this->status !== 'applied') {
            throw new \InvalidArgumentException("Cannot revert a gloss with status {$this->status}");
        }

        $this->update([
            'status' => 'reverted',
            'reverted_by' => $revertedBy,
            'reverted_at' => now(),
            'notes' => $notes ?? $this->notes
        ]);

        // Update the parent payment
        $payment = $this->payment;
        $payment->decrement('gloss_amount', $this->amount);
        $payment->increment('total_amount', $this->amount);

        return $this;
    }

    /**
     * Mark this gloss as appealed.
     *
     * @return self
     */
    public function markAsAppealed(): self
    {
        // Only applied glosses that are appealable can be appealed
        if ($this->status !== 'applied' || !$this->is_appealable) {
            throw new \InvalidArgumentException("Cannot appeal a gloss with status {$this->status} or that is not appealable");
        }

        $this->update([
            'status' => 'appealed'
        ]);

        return $this;
    }

    /**
     * Check if the gloss is applied.
     *
     * @return bool
     */
    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    /**
     * Check if the gloss is appealed.
     *
     * @return bool
     */
    public function isAppealed(): bool
    {
        return $this->status === 'appealed';
    }

    /**
     * Check if the gloss is reverted.
     *
     * @return bool
     */
    public function isReverted(): bool
    {
        return $this->status === 'reverted';
    }

    /**
     * Check if the gloss can be appealed.
     *
     * @return bool
     */
    public function canBeAppealed(): bool
    {
        return $this->is_appealable && $this->status === 'applied';
    }

    /**
     * Check if the gloss can be reverted.
     *
     * @return bool
     */
    public function canBeReverted(): bool
    {
        return $this->status === 'applied';
    }

    /**
     * Scope a query to only include glosses with a specific status.
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope a query to only include applied glosses.
     */
    public function scopeApplied($query)
    {
        return $query->where('status', 'applied');
    }

    /**
     * Scope a query to only include appealed glosses.
     */
    public function scopeAppealed($query)
    {
        return $query->where('status', 'appealed');
    }

    /**
     * Scope a query to only include reverted glosses.
     */
    public function scopeReverted($query)
    {
        return $query->where('status', 'reverted');
    }

    /**
     * Scope a query to only include appealable glosses.
     */
    public function scopeAppealable($query)
    {
        return $query->where('is_appealable', true);
    }
} 