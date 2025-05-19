<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Negotiation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Status constants
     */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_PARTIALLY_COMPLETE = 'partially_complete';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PARTIALLY_APPROVED = 'partially_approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'start_date',
        'end_date',
        'negotiable_type',
        'negotiable_id',
        'contract_template_id',
        'contract_id',
        'creator_id',
        'status',
        'notes',
        'current_approval_level',
        'approved_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'rejected_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The status labels.
     *
     * @var array<string, string>
     */
    protected static $statusLabels = [
        'draft' => 'Rascunho',
        'submitted' => 'Enviado',
        'pending' => 'Pendente',
        'complete' => 'Completo',
        'partially_complete' => 'Parcialmente Completo',
        'approved' => 'Aprovado',
        'partially_approved' => 'Parcialmente Aprovado',
        'rejected' => 'Rejeitado',
        'cancelled' => 'Cancelado',
    ];

    /**
     * Get the entity (health plan, professional, clinic) that this negotiation is with.
     */
    public function negotiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the health plan associated with this negotiation (for backward compatibility).
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class, 'negotiable_id')
            ->where('negotiable_type', HealthPlan::class);
    }

    /**
     * Get the user who created this negotiation.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get the contract template associated with this negotiation.
     */
    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class);
    }

    /**
     * Get the contract generated from this negotiation.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the items associated with this negotiation.
     */
    public function items(): HasMany
    {
        return $this->hasMany(NegotiationItem::class);
    }

    /**
     * Get the formatted status label.
     *
     * @return string
     */
    public function getStatusLabelAttribute(): string
    {
        return self::$statusLabels[$this->status] ?? $this->status;
    }

    /**
     * Calculate the total proposed value of all items.
     *
     * @return float
     */
    public function calculateTotalValue(): float
    {
        return $this->items->sum('proposed_value');
    }

    /**
     * Get the approval history for the negotiation.
     */
    public function approvalHistory()
    {
        return $this->hasMany(NegotiationApprovalHistory::class);
    }
} 