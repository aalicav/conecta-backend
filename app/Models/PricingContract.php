<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PricingContract extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tuss_procedure_id',
        'contractable_id',
        'contractable_type',
        'price',
        'start_date',
        'end_date',
        'is_active',
        'notes',
        'created_by',
        'medical_specialty_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'price' => 'float',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the TUSS procedure associated with this pricing contract.
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(TussProcedure::class, 'tuss_procedure_id');
    }

    /**
     * Get the medical specialty associated with this pricing contract.
     */
    public function medicalSpecialty(): BelongsTo
    {
        return $this->belongsTo(MedicalSpecialty::class, 'medical_specialty_id');
    }

    /**
     * Get the contractable entity (health plan, clinic, professional).
     */
    public function contractable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created this pricing contract.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if the pricing contract is active.
     */
    public function isActive(): bool
    {
        return $this->is_active && 
               (!$this->end_date || $this->end_date->isFuture());
    }

    /**
     * Check if the pricing contract is expired.
     */
    public function isExpired(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    /**
     * Set this pricing contract as active.
     */
    public function activate(): self
    {
        $this->update(['is_active' => true]);
        return $this;
    }

    /**
     * Set this pricing contract as inactive.
     */
    public function deactivate(): self
    {
        $this->update(['is_active' => false]);
        return $this;
    }

    /**
     * Scope a query to only include active pricing contracts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Scope a query to only include pricing contracts for a specific procedure.
     */
    public function scopeForProcedure($query, $tussProcedureId)
    {
        return $query->where('tuss_procedure_id', $tussProcedureId);
    }

    /**
     * Scope a query to only include pricing contracts for a specific provider.
     */
    public function scopeForProvider($query, $providerType, $providerId)
    {
        return $query->where('contractable_type', $providerType)
            ->where('contractable_id', $providerId);
    }

    /**
     * Scope a query to order by price (ascending).
     */
    public function scopeOrderByLowestPrice($query)
    {
        return $query->orderBy('price', 'asc');
    }

    /**
     * Scope a query to order by price (descending).
     */
    public function scopeOrderByHighestPrice($query)
    {
        return $query->orderBy('price', 'desc');
    }
} 