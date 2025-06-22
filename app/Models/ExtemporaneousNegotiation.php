<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'negotiable_type',
        'negotiable_id',
        'tuss_procedure_id',
        'negotiated_price',
        'justification',
        'status',
        'created_by',
        'approved_by',
        'rejected_by',
        'formalized_by',
        'cancelled_by',
        'approved_at',
        'rejected_at',
        'formalized_at',
        'cancelled_at',
        'contract_id',
        'addendum_number',
        'addendum_signed_at',
        'approval_notes',
        'rejection_notes',
        'formalization_notes',
        'cancellation_notes',
        'solicitation_id'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'negotiated_price' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'formalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'addendum_signed_at' => 'datetime'
    ];

    /**
     * Get the entity being negotiated with (clinic or health plan).
     */
    public function negotiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the TUSS procedure associated with this negotiation.
     */
    public function tussProcedure()
    {
        return $this->belongsTo(TussProcedure::class);
    }

    /**
     * Get the contract associated with this negotiation.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the solicitation that triggered this negotiation.
     */
    public function solicitation()
    {
        return $this->belongsTo(Solicitation::class);
    }

    /**
     * Get the user who created this negotiation.
     */
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who approved this negotiation.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who rejected this negotiation.
     */
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get the user who formalized this negotiation.
     */
    public function formalizedBy()
    {
        return $this->belongsTo(User::class, 'formalized_by');
    }

    /**
     * Get the user who cancelled this negotiation.
     */
    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
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