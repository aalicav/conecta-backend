<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingItem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'pricing_contract_id',
        'tuss_procedure_id',
        'price',
        'notes',
        'is_active',
        'start_date',
        'end_date'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date'
    ];

    /**
     * Get the pricing contract that owns this item.
     */
    public function pricingContract(): BelongsTo
    {
        return $this->belongsTo(PricingContract::class);
    }

    /**
     * Get the TUSS procedure associated with this item.
     */
    public function tuss(): BelongsTo
    {
        return $this->belongsTo(TussProcedure::class, 'tuss_procedure_id');
    }

    /**
     * Check if the pricing item is active.
     */
    public function isActive(): bool
    {
        return $this->is_active && 
               (!$this->end_date || $this->end_date->isFuture());
    }

    /**
     * Scope a query to only include active pricing items.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }
} 