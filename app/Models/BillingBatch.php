<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingBatch extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'billing_rule_id',
        'entity_type',
        'entity_id',
        'reference_period_start',
        'reference_period_end',
        'items_count',
        'total_amount',
        'fees_amount',
        'taxes_amount',
        'net_amount',
        'billing_date',
        'due_date',
        'status',
        'invoice_number',
        'invoice_path',
        'created_by',
        'processing_notes',
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
        'fees_amount' => 'decimal:2',
        'taxes_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
    ];

    /**
     * Get the billing rule that was used for this batch.
     */
    public function billingRule()
    {
        return $this->belongsTo(BillingRule::class);
    }

    /**
     * Get the entity that this billing batch applies to.
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created the billing batch.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the billing items for this batch.
     */
    public function items()
    {
        return $this->hasMany(BillingItem::class);
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