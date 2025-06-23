<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtemporaneousNegotiation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Status constants
     */
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_FORMALIZED = 'formalized';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Urgency level constants
     */
    const URGENCY_LOW = 'low';
    const URGENCY_MEDIUM = 'medium';
    const URGENCY_HIGH = 'high';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'tuss_id',
        'requested_value',
        'approved_value',
        'justification',
        'approval_notes',
        'rejection_reason',
        'urgency_level',
        'requested_by',
        'approved_by',
        'approved_at',
        'is_requiring_addendum',
        'addendum_included',
        'addendum_number',
        'addendum_date',
        'addendum_notes',
        'addendum_updated_by',
        'negotiable_type',
        'negotiable_id',
        'tuss_procedure_id',
        'negotiated_price',
        'created_by',
        'rejected_by',
        'formalized_by',
        'cancelled_by',
        'rejected_at',
        'formalized_at',
        'cancelled_at',
        'addendum_signed_at',
        'rejection_notes',
        'formalization_notes',
        'cancellation_notes',
        'solicitation_id',
        'status'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'requested_value' => 'decimal:2',
        'approved_value' => 'decimal:2',
        'negotiated_price' => 'decimal:2',
        'is_requiring_addendum' => 'boolean',
        'addendum_included' => 'boolean',
        'approved_at' => 'datetime',
        'addendum_date' => 'date',
        'rejected_at' => 'datetime',
        'formalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'addendum_signed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Get the entity being negotiated with (clinic or professional).
     */
    public function negotiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the TUSS procedure associated with this negotiation.
     */
    public function tussProcedure(): BelongsTo
    {
        return $this->belongsTo(TussProcedure::class, 'tuss_id');
    }

    /**
     * Get the solicitation that triggered this negotiation.
     */
    public function solicitation(): BelongsTo
    {
        return $this->belongsTo(Solicitation::class);
    }

    /**
     * Get the user who requested this negotiation.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who created this negotiation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved this negotiation.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who rejected this negotiation.
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get the user who formalized this negotiation.
     */
    public function formalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'formalized_by');
    }

    /**
     * Get the user who cancelled this negotiation.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Get the user who updated the addendum.
     */
    public function addendumUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addendum_updated_by');
    }

    /**
     * Scope a query to only include pending approval negotiations.
     */
    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    /**
     * Scope a query to only include approved negotiations.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected negotiations.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to only include formalized negotiations.
     */
    public function scopeFormalized($query)
    {
        return $query->where('status', self::STATUS_FORMALIZED);
    }

    /**
     * Scope a query to only include cancelled negotiations.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope a query to only include negotiations pending formalization.
     */
    public function scopePendingFormalization($query)
    {
        return $query->where('status', self::STATUS_APPROVED)
                    ->whereNull('formalized_at');
    }

    /**
     * Check if the negotiation is pending approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * Check if the negotiation is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the negotiation is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if the negotiation is formalized.
     */
    public function isFormalized(): bool
    {
        return $this->status === self::STATUS_FORMALIZED;
    }

    /**
     * Check if the negotiation is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Get status label for display.
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING_APPROVAL => 'Aguardando Aprovação',
            self::STATUS_APPROVED => 'Aprovada',
            self::STATUS_REJECTED => 'Rejeitada',
            self::STATUS_FORMALIZED => 'Formalizada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => 'Desconhecido'
        };
    }

    /**
     * Approve the negotiation.
     */
    public function approve(int $userId, ?string $notes = null): bool
    {
        if (!$this->isPendingApproval()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $userId,
            'approved_at' => now(),
            'approval_notes' => $notes
        ]);
    }

    /**
     * Reject the negotiation.
     */
    public function reject(int $userId, string $notes): bool
    {
        if (!$this->isPendingApproval()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $userId,
            'rejected_at' => now(),
            'rejection_notes' => $notes
        ]);
    }

    /**
     * Mark the negotiation as formalized.
     */
    public function formalize(int $userId, string $addendumNumber, ?string $notes = null): bool
    {
        if (!$this->isApproved()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_FORMALIZED,
            'formalized_by' => $userId,
            'formalized_at' => now(),
            'addendum_number' => $addendumNumber,
            'formalization_notes' => $notes
        ]);
    }

    /**
     * Cancel the negotiation.
     */
    public function cancel(int $userId, string $notes): bool
    {
        if ($this->isFormalized() || $this->isCancelled()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_by' => $userId,
            'cancelled_at' => now(),
            'cancellation_notes' => $notes
        ]);
    }
} 