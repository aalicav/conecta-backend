<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'created_by',
        'billing_rule_id',
        'health_plan_id',
        'contract_id',
        'nfe_number',
        'nfe_key',
        'nfe_xml',
        'nfe_status',
        'nfe_protocol',
        'nfe_authorization_date',
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
        'due_date' => 'datetime',
        'total_amount' => 'decimal:2',
        'items_count' => 'integer',
        'nfe_authorization_date' => 'datetime',
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
     * Alias para o relacionamento de billing items (compatível com o controller).
     */
    public function billingItems(): HasMany
    {
        return $this->items();
    }

    /**
     * Get the fiscal documents for the batch (polimórfico).
     */
    public function fiscalDocuments()
    {
        return $this->morphMany(FiscalDocument::class, 'documentable');
    }

    /**
     * Get the value verifications for the batch.
     */
    public function valueVerifications(): HasMany
    {
        return $this->hasMany(ValueVerification::class);
    }

    /**
     * Get pending value verifications for the batch.
     */
    public function pendingValueVerifications(): HasMany
    {
        return $this->hasMany(ValueVerification::class)->pending();
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
     * Get the health plan for this batch.
     */
    public function healthPlan()
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the contract for this batch.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
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

    /**
     * Get the NFe status text for this batch.
     */
    public function getNFeStatusTextAttribute()
    {
        return [
            'pending' => 'Pendente',
            'issued' => 'Emitida',
            'authorized' => 'Autorizada',
            'cancelled' => 'Cancelada',
            'error' => 'Erro'
        ][$this->nfe_status] ?? 'Desconhecido';
    }
} 