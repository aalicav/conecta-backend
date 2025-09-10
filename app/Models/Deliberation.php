<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Deliberation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Status constants
     */
    const STATUS_PENDING_APPROVAL = 'pending_approval';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_BILLED = 'billed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Reason constants
     */
    const REASON_NO_TABLE_VALUE = 'no_table_value';
    const REASON_SPECIFIC_DOCTOR_VALUE = 'specific_doctor_value';
    const REASON_SPECIAL_AGREEMENT = 'special_agreement';
    const REASON_EMERGENCY_CASE = 'emergency_case';
    const REASON_OTHER = 'other';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'deliberation_number',
        'status',
        'health_plan_id',
        'clinic_id',
        'professional_id',
        'medical_specialty_id',
        'tuss_procedure_id',
        'appointment_id',
        'solicitation_id',
        'negotiated_value',
        'medlar_percentage',
        'medlar_amount',
        'total_value',
        'original_table_value',
        'reason',
        'justification',
        'notes',
        'requires_operator_approval',
        'operator_approved',
        'operator_approved_by',
        'operator_approved_at',
        'operator_approval_notes',
        'operator_rejection_reason',
        'approved_by',
        'approved_at',
        'approval_notes',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'billing_item_id',
        'billed_at',
        'billing_batch_number',
        'created_by',
        'updated_by'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'negotiated_value' => 'decimal:2',
        'medlar_percentage' => 'decimal:2',
        'medlar_amount' => 'decimal:2',
        'total_value' => 'decimal:2',
        'original_table_value' => 'decimal:2',
        'requires_operator_approval' => 'boolean',
        'operator_approved' => 'boolean',
        'operator_approved_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'billed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($deliberation) {
            if (empty($deliberation->deliberation_number)) {
                $deliberation->deliberation_number = static::generateDeliberationNumber();
            }
        });
    }

    /**
     * Generate a unique deliberation number.
     */
    public static function generateDeliberationNumber(): string
    {
        do {
            $number = 'DEL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (static::where('deliberation_number', $number)->exists());

        return $number;
    }

    /**
     * Get the health plan that owns this deliberation.
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the clinic associated with this deliberation.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Get the professional associated with this deliberation.
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * Get the medical specialty associated with this deliberation.
     */
    public function medicalSpecialty(): BelongsTo
    {
        return $this->belongsTo(MedicalSpecialty::class);
    }

    /**
     * Get the TUSS procedure associated with this deliberation.
     */
    public function tussProcedure(): BelongsTo
    {
        return $this->belongsTo(TussProcedure::class);
    }

    /**
     * Get the appointment associated with this deliberation.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Get the solicitation associated with this deliberation.
     */
    public function solicitation(): BelongsTo
    {
        return $this->belongsTo(Solicitation::class);
    }

    /**
     * Get the billing item associated with this deliberation.
     */
    public function billingItem(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class);
    }

    /**
     * Get the user who created this deliberation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who updated this deliberation.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the user who approved this deliberation.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who rejected this deliberation.
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Get the user who cancelled this deliberation.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Get the user who approved this deliberation on behalf of the operator.
     */
    public function operatorApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_approved_by');
    }

    /**
     * Scope a query to only include pending approval deliberations.
     */
    public function scopePendingApproval($query)
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    /**
     * Scope a query to only include approved deliberations.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * Scope a query to only include rejected deliberations.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    /**
     * Scope a query to only include billed deliberations.
     */
    public function scopeBilled($query)
    {
        return $query->where('status', self::STATUS_BILLED);
    }

    /**
     * Scope a query to only include cancelled deliberations.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope a query to only include deliberations requiring operator approval.
     */
    public function scopeRequiringOperatorApproval($query)
    {
        return $query->where('requires_operator_approval', true)
                    ->whereNull('operator_approved');
    }

    /**
     * Scope a query to only include deliberations approved by operator.
     */
    public function scopeOperatorApproved($query)
    {
        return $query->where('operator_approved', true);
    }

    /**
     * Scope a query to only include deliberations rejected by operator.
     */
    public function scopeOperatorRejected($query)
    {
        return $query->where('operator_approved', false);
    }

    /**
     * Check if the deliberation is pending approval.
     */
    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    /**
     * Check if the deliberation is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /**
     * Check if the deliberation is rejected.
     */
    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Check if the deliberation is billed.
     */
    public function isBilled(): bool
    {
        return $this->status === self::STATUS_BILLED;
    }

    /**
     * Check if the deliberation is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the deliberation requires operator approval.
     */
    public function requiresOperatorApproval(): bool
    {
        return $this->requires_operator_approval;
    }

    /**
     * Check if the deliberation is approved by operator.
     */
    public function isOperatorApproved(): bool
    {
        return $this->operator_approved === true;
    }

    /**
     * Check if the deliberation is rejected by operator.
     */
    public function isOperatorRejected(): bool
    {
        return $this->operator_approved === false;
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
            self::STATUS_BILLED => 'Faturada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => 'Desconhecido'
        };
    }

    /**
     * Get reason label for display.
     */
    public function getReasonLabelAttribute(): string
    {
        return match($this->reason) {
            self::REASON_NO_TABLE_VALUE => 'Ausência de valor na tabela',
            self::REASON_SPECIFIC_DOCTOR_VALUE => 'Valor diferenciado por médico específico',
            self::REASON_SPECIAL_AGREEMENT => 'Acordo especial',
            self::REASON_EMERGENCY_CASE => 'Caso de emergência',
            self::REASON_OTHER => 'Outro motivo',
            default => 'Desconhecido'
        };
    }

    /**
     * Calculate Medlar amount based on negotiated value and percentage.
     */
    public function calculateMedlarAmount(): float
    {
        return $this->negotiated_value * ($this->medlar_percentage / 100);
    }

    /**
     * Calculate total value (negotiated + Medlar amount).
     */
    public function calculateTotalValue(): float
    {
        return $this->negotiated_value + $this->medlar_amount;
    }

    /**
     * Approve the deliberation.
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
            'approval_notes' => $notes,
            'updated_by' => $userId
        ]);
    }

    /**
     * Reject the deliberation.
     */
    public function reject(int $userId, string $reason): bool
    {
        if (!$this->isPendingApproval()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $userId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'updated_by' => $userId
        ]);
    }

    /**
     * Approve by operator.
     */
    public function approveByOperator(int $userId, ?string $notes = null): bool
    {
        if (!$this->requiresOperatorApproval() || $this->operator_approved !== null) {
            return false;
        }

        return $this->update([
            'operator_approved' => true,
            'operator_approved_by' => $userId,
            'operator_approved_at' => now(),
            'operator_approval_notes' => $notes,
            'updated_by' => $userId
        ]);
    }

    /**
     * Reject by operator.
     */
    public function rejectByOperator(int $userId, string $reason): bool
    {
        if (!$this->requiresOperatorApproval() || $this->operator_approved !== null) {
            return false;
        }

        return $this->update([
            'operator_approved' => false,
            'operator_approved_by' => $userId,
            'operator_approved_at' => now(),
            'operator_rejection_reason' => $reason,
            'updated_by' => $userId
        ]);
    }

    /**
     * Mark as billed.
     */
    public function markAsBilled(int $billingItemId, string $billingBatchNumber = null): bool
    {
        if (!$this->isApproved()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_BILLED,
            'billing_item_id' => $billingItemId,
            'billed_at' => now(),
            'billing_batch_number' => $billingBatchNumber
        ]);
    }

    /**
     * Cancel the deliberation.
     */
    public function cancel(int $userId, string $reason): bool
    {
        if ($this->isBilled() || $this->isCancelled()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_by' => $userId,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'updated_by' => $userId
        ]);
    }
}
