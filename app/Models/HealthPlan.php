<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthPlan extends Model
{
    use HasFactory, SoftDeletes;

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
} 