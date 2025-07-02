<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthPlanProcedure extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'health_plan_id',
        'tuss_procedure_id',
        'price',
        'notes',
        'is_active',
        'start_date',
        'end_date',
        'created_by',
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
        'end_date' => 'date',
    ];

    /**
     * Get the health plan that owns this procedure.
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the TUSS procedure associated with this item.
     */
    public function procedure(): BelongsTo
    {
        return $this->belongsTo(TussProcedure::class, 'tuss_procedure_id');
    }

    /**
     * Get the user who created this procedure.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if the procedure is active.
     */
    public function isActive(): bool
    {
        return $this->is_active && 
               (!$this->end_date || $this->end_date->isFuture());
    }

    /**
     * Check if the procedure is expired.
     */
    public function isExpired(): bool
    {
        return $this->end_date && $this->end_date->isPast();
    }

    /**
     * Scope a query to only include active procedures.
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
     * Scope a query to only include procedures for a specific health plan.
     */
    public function scopeForHealthPlan($query, $healthPlanId)
    {
        return $query->where('health_plan_id', $healthPlanId);
    }

    /**
     * Scope a query to only include procedures for a specific TUSS procedure.
     */
    public function scopeForProcedure($query, $tussProcedureId)
    {
        return $query->where('tuss_procedure_id', $tussProcedureId);
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

    /**
     * Set this procedure as active.
     */
    public function activate(): self
    {
        $this->update(['is_active' => true]);
        return $this;
    }

    /**
     * Set this procedure as inactive.
     */
    public function deactivate(): self
    {
        $this->update(['is_active' => false]);
        return $this;
    }
} 