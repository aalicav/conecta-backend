<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingRule extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'health_plan_id',
        'contract_id',
        'frequency',
        'monthly_day',
        'batch_size',
        'payment_days',
        'notification_recipients',
        'notification_frequency',
        'document_format',
        'is_active',
        'generate_nfe',
        'nfe_series',
        'nfe_environment',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'notification_recipients' => 'array',
        'is_active' => 'boolean',
        'monthly_day' => 'integer',
        'batch_size' => 'integer',
        'payment_days' => 'integer',
        'generate_nfe' => 'boolean',
        'nfe_series' => 'integer',
        'nfe_environment' => 'integer',
    ];

    /**
     * Get the health plan that owns the billing rule.
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the billing batches for the rule.
     */
    public function billingBatches(): HasMany
    {
        return $this->hasMany(BillingBatch::class);
    }

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

    public function getFrequencyTextAttribute()
    {
        return [
            'daily' => 'Diário',
            'weekly' => 'Semanal',
            'monthly' => 'Mensal'
        ][$this->frequency] ?? 'Desconhecido';
    }

    public function getNotificationFrequencyTextAttribute()
    {
        return [
            'immediate' => 'Imediato',
            'daily' => 'Diário',
            'weekly' => 'Semanal'
        ][$this->notification_frequency] ?? 'Desconhecido';
    }

    public function getDocumentFormatTextAttribute()
    {
        return [
            'pdf' => 'PDF',
            'xml' => 'XML',
            'both' => 'PDF e XML'
        ][$this->document_format] ?? 'Desconhecido';
    }

    public function getNFeEnvironmentTextAttribute()
    {
        return [
            1 => 'Produção',
            2 => 'Homologação'
        ][$this->nfe_environment] ?? 'Desconhecido';
    }
} 