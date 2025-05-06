<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingItem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'billing_batch_id',
        'item_type',
        'item_id',
        'reference_id',
        'reference_type',
        'description',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /**
     * Get the billing batch that this item belongs to.
     */
    public function billingBatch()
    {
        return $this->belongsTo(BillingBatch::class);
    }

    /**
     * Get the related item (polymorphic).
     */
    public function item()
    {
        return $this->morphTo();
    }

    /**
     * Get the reference object (polymorphic).
     */
    public function reference()
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include items with a specific status.
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }
} 