<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BillingBatch extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'entity_type',
        'entity_id',
        'reference_period_start',
        'reference_period_end',
        'billing_date',
        'due_date',
        'total_amount',
        'status',
        'items_count',
        'created_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'reference_period_start' => 'date',
        'reference_period_end' => 'date',
        'billing_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'items_count' => 'integer'
    ];

    /**
     * Get the entity that owns the billing batch.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user that created the billing batch.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the billing items for the batch.
     */
    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class);
    }

    /**
     * Get the fiscal documents for the batch.
     */
    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }

    /**
     * Get the payment proofs for the batch.
     */
    public function paymentProofs(): HasMany
    {
        return $this->hasMany(PaymentProof::class);
    }

    /**
     * Get the billing rule that was used for this batch.
     */
    public function billingRule()
    {
        return $this->belongsTo(BillingRule::class);
    }

    /**
     * Scope a query to only include pending batches.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include processed batches.
     */
    public function scopeProcessed($query)
    {
        return $query->where('status', 'processed');
    }

    /**
     * Scope a query to only include completed batches.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include failed batches.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
} 