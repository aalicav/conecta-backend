<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillingRule extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'description',
        'entity_type',
        'entity_id',
        'rule_type',
        'billing_cycle',
        'billing_day',
        'payment_term_days',
        'invoice_generation_days_before',
        'payment_method',
        'conditions',
        'discounts',
        'tax_rules',
        'is_active',
        'priority',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'conditions' => 'array',
        'discounts' => 'array',
        'tax_rules' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Get the entity that this rule applies to.
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * Get the user who created the rule.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the billing batches that use this rule.
     */
    public function billingBatches()
    {
        return $this->hasMany(BillingBatch::class);
    }

    /**
     * Scope a query to only include active rules.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get applicable rules for a specific entity
     * 
     * @param string $entityType The fully qualified class name of the entity type
     * @param int $entityId The ID of the entity
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getApplicableRules($entityType, $entityId)
    {
        return self::where(function($query) use ($entityType, $entityId) {
                $query->where([
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                ])
                ->orWhere([
                    'entity_type' => $entityType,
                    'entity_id' => null, // Global rules for the type
                ]);
            })
            ->active()
            ->orderBy('priority', 'desc')
            ->get();
    }
} 