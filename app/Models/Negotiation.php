<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Traits\Auditable;

class Negotiation extends Model
{
    use HasFactory, SoftDeletes, Auditable;

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
        'status',
        'approval_level',
        'formalization_status',
        'start_date',
        'end_date',
        'notes',
        'creator_id',
        'approved_by',
        'approved_at',
        'approval_notes',
        'rejected_by',
        'rejected_at',
        'rejection_notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'previous_cycles_data' => 'array',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'rejected_at' => 'datetime',
        'completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'forked_at' => 'datetime',
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
    public function approvalHistory(): HasMany
    {
        return $this->hasMany(NegotiationApprovalHistory::class);
    }

    /**
     * Relacionamento com o histórico de status.
     */
    public function statusHistory()
    {
        return $this->hasMany(NegotiationStatusHistory::class);
    }

    /**
     * Obter a negociação pai (quando for uma bifurcação).
     */
    public function parentNegotiation()
    {
        return $this->belongsTo(Negotiation::class, 'parent_negotiation_id');
    }

    /**
     * Obter as negociações bifurcadas desta negociação.
     */
    public function forkedNegotiations()
    {
        return $this->hasMany(Negotiation::class, 'parent_negotiation_id');
    }

    /**
     * Get the user who approved the negotiation.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who rejected the negotiation.
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Check if the negotiation can be submitted for approval.
     *
     * @return bool
     */
    public function canBeSubmittedForApproval(): bool
    {
        return $this->status === 'draft' || 
               $this->status === 'submitted';
    }

    /**
     * Check if the negotiation can be approved.
     *
     * @return bool
     */
    public function canBeApproved(): bool
    {
        return $this->approval_level === 'pending_approval';
    }

    /**
     * Check if the negotiation can be formalized.
     *
     * @return bool
     */
    public function canBeFormalized(): bool
    {
        return $this->status === 'approved' && 
               $this->formalization_status === 'pending_aditivo';
    }

    /**
     * Record an approval history entry.
     *
     * @param string $action
     * @param int $userId
     * @param string|null $notes
     * @return NegotiationApprovalHistory
     */
    public function recordApprovalHistory(string $action, int $userId, ?string $notes = null): NegotiationApprovalHistory
    {
        return $this->approvalHistory()->create([
            'action' => $action,
            'user_id' => $userId,
            'notes' => $notes,
        ]);
    }
} 