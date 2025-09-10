<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class HealthPlan extends Model implements Auditable
{
    use HasFactory, SoftDeletes, AuditableTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'cnpj',
        'ans_code',
        'description',
        'municipal_registration',
        'legal_representative_name',
        'legal_representative_cpf',
        'legal_representative_position',
        'legal_representative_id',
        'operational_representative_name',
        'operational_representative_cpf',
        'operational_representative_position',
        'operational_representative_id',
        'address',
        'city',
        'state',
        'postal_code',
        'logo',
        'status',
        'approved_at',
        'approved_by',
        'has_signed_contract',
        'user_id',
        'parent_id',
        'parent_relation_type',
        'is_parent',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'approved_at' => 'datetime',
        'has_signed_contract' => 'boolean',
    ];

    /**
     * Attributes to include in the Audit.
     *
     * @var array
     */
    protected $auditInclude = [
        'name',
        'cnpj',
        'municipal_registration',
        'ans_code',
        'description',
        'legal_representative_name',
        'legal_representative_cpf',
        'legal_representative_position',
        'legal_representative_id',
        'operational_representative_name',
        'operational_representative_cpf',
        'operational_representative_position',
        'operational_representative_id',
        'address',
        'city',
        'state',
        'postal_code',
        'status',
        'has_signed_contract',
        'approved_at',
        'approved_by'
    ];

    /**
     * Attributes to exclude from the Audit.
     *
     * @var array
     */
    protected $auditExclude = [
        'created_at',
        'updated_at',
        'deleted_at'
    ];

    /**
     * Custom audit messages
     */
    public function transformAudit(array $data): array
    {
        if ($data['event'] === 'created') {
            $data['custom_message'] = 'Plano de saúde criado: ' . $this->name;
        } elseif ($data['event'] === 'updated') {
            $data['custom_message'] = 'Plano de saúde atualizado: ' . $this->name;
        } elseif ($data['event'] === 'deleted') {
            $data['custom_message'] = 'Plano de saúde excluído: ' . $this->name;
        }

        return $data;
    }

    /**
     * Get the audit logs for the health plan.
     */
    public function audit(): MorphMany
    {
        return $this->morphMany(\OwenIt\Auditing\Models\Audit::class, 'auditable');
    }

    /**
     * Get the parent health plan.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class, 'parent_id');
    }

    /**
     * Get the child health plans.
     */
    public function children(): HasMany
    {
        return $this->hasMany(HealthPlan::class, 'parent_id');
    }

    /**
     * Get all descendants (children, grandchildren, etc.).
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllDescendants()
    {
        $descendants = collect();
        
        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getAllDescendants());
        }
        
        return $descendants;
    }

    /**
     * Check if this health plan is a parent.
     * 
     * @return bool
     */
    public function isParent(): bool
    {
        return $this->children()->count() > 0;
    }

    /**
     * Check if this health plan has a parent.
     * 
     * @return bool
     */
    public function hasParent(): bool
    {
        return !is_null($this->parent_id);
    }

    /**
     * Get the phones for the health plan.
     */
    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    /**
     * Get the documents for the health plan.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get the patients belonging to this health plan.
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * Get the user that approved this health plan.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the administrators associated with this health plan.
     */
    public function administrators(): MorphMany
    {
        return $this->morphMany(User::class, 'entity');
    }

    /**
     * Get the contract for this health plan.
     */
    public function contract(): MorphOne
    {
        return $this->morphOne(Contract::class, 'contractable')
            ->where('type', 'health_plan')
            ->latest();
    }

    /**
     * Get all contracts for this health plan.
     */
    public function contracts(): MorphMany
    {
        return $this->morphMany(Contract::class, 'contractable')
            ->where('type', 'health_plan')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get pricing contracts for procedures.
     */
    public function pricingContracts(): MorphMany
    {
        return $this->morphMany(PricingContract::class, 'contractable');
    }

    /**
     * Get procedures with pricing for this health plan.
     */
    public function procedures(): HasMany
    {
        return $this->hasMany(HealthPlanProcedure::class);
    }

    /**
     * Get the price for a specific TUSS procedure.
     *
     * @param int $tussProcedureId
     * @return float|null
     */
    public function getProcedurePrice(int $tussProcedureId): ?float
    {
        $procedure = $this->procedures()
            ->where('tuss_procedure_id', $tussProcedureId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->first();

        return $procedure ? (float) $procedure->price : null;
    }

    /**
     * Get all active procedure prices for this health plan.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveProcedurePrices()
    {
        return $this->procedures()
            ->with('procedure')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->get();
    }

    /**
     * Get solicitations made by this health plan.
     */
    public function solicitations(): HasMany
    {
        return $this->hasMany(Solicitation::class);
    }

    /**
     * Get the user that owns this health plan.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the legal representative user.
     */
    public function legalRepresentative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_representative_id');
    }

    /**
     * Get the operational representative user.
     */
    public function operationalRepresentative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operational_representative_id');
    }

    /**
     * Scope a query to only include pending health plans.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved health plans.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected health plans.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to only include active health plans.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'approved')
            ->where('has_signed_contract', true);
    }

    /**
     * Scope a query to only include parent health plans.
     */
    public function scopeParents($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope a query to only include child health plans.
     */
    public function scopeChildren($query)
    {
        return $query->whereNotNull('parent_id');
    }

    /**
     * Check if this is a parent health plan.
     *
     * @return bool
     */
    public function isParentPlan(): bool
    {
        return $this->is_parent === true;
    }

    /**
     * Check if this is a child health plan.
     *
     * @return bool
     */
    public function isChildPlan(): bool
    {
        return !$this->is_parent && $this->parent_id !== null;
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get the billing rules for this health plan.
     */
    public function billingRules(): HasMany
    {
        return $this->hasMany(HealthPlanBillingRule::class);
    }

    /**
     * Get the billing batches for this health plan.
     */
    public function billingBatches(): HasMany
    {
        return $this->hasMany(BillingBatch::class);
    }

    /**
     * Get the appointments for this health plan.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get the extemporaneous negotiations for this health plan.
     */
    public function extemporaneousNegotiations()
    {
        return $this->morphMany(ExtemporaneousNegotiation::class, 'negotiable');
    }

    /**
     * WhatsApp numbers associated with this health plan
     */
    public function whatsappNumbers()
    {
        return $this->belongsToMany(WhatsAppNumber::class, 'health_plan_whatsapp_numbers');
    }

    /**
     * Get the primary WhatsApp number for this health plan
     */
    public function getPrimaryWhatsAppNumber()
    {
        return $this->whatsappNumbers()->where('is_active', true)->first();
    }
} 