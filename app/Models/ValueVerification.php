<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ValueVerification extends Model
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
        'value_type',
        'original_value',
        'verified_value',
        'status',
        'requester_id',
        'verifier_id',
        'notes',
        'verified_at',
        'billing_batch_id',
        'billing_item_id',
        'appointment_id',
        'verification_reason',
        'priority',
        'due_date',
        'auto_approve_threshold',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'original_value' => 'float',
        'verified_value' => 'float',
        'verified_at' => 'datetime',
        'due_date' => 'datetime',
        'auto_approve_threshold' => 'float',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_VERIFIED = 'verified';
    const STATUS_REJECTED = 'rejected';
    const STATUS_AUTO_APPROVED = 'auto_approved';

    /**
     * Value type constants
     */
    const TYPE_APPOINTMENT_PRICE = 'appointment_price';
    const TYPE_PROCEDURE_PRICE = 'procedure_price';
    const TYPE_SPECIALTY_PRICE = 'specialty_price';
    const TYPE_CONTRACT_PRICE = 'contract_price';
    const TYPE_BILLING_AMOUNT = 'billing_amount';

    /**
     * Priority constants
     */
    const PRIORITY_LOW = 'low';
    const PRIORITY_MEDIUM = 'medium';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_CRITICAL = 'critical';

    /**
     * Get the requester user.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Get the verifier user.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    /**
     * Get the related entity.
     */
    public function entity(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the billing batch associated with this verification.
     */
    public function billingBatch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class);
    }

    /**
     * Get the billing item associated with this verification.
     */
    public function billingItem(): BelongsTo
    {
        return $this->belongsTo(BillingItem::class);
    }

    /**
     * Get the appointment associated with this verification.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Scope for pending verifications
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for high priority verifications
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_CRITICAL]);
    }

    /**
     * Scope for overdue verifications
     */
    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
                    ->where('status', self::STATUS_PENDING);
    }

    /**
     * Check if verification is overdue
     */
    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if verification can be auto-approved
     */
    public function canBeAutoApproved(): bool
    {
        if (!$this->auto_approve_threshold) {
            return false;
        }

        $difference = abs($this->original_value - $this->verified_value);
        $percentage = ($difference / $this->original_value) * 100;

        return $percentage <= $this->auto_approve_threshold;
    }

    /**
     * Auto-approve verification if within threshold
     */
    public function autoApprove(): bool
    {
        if (!$this->canBeAutoApproved()) {
            return false;
        }

        $this->status = self::STATUS_AUTO_APPROVED;
        $this->verifier_id = null; // No human verifier
        $this->verified_at = now();
        $this->notes = $this->notes ? $this->notes . "\n\nAuto-aprovado: Diferença dentro do limite permitido." : "Auto-aprovado: Diferença dentro do limite permitido.";
        
        return $this->save();
    }

    /**
     * Verify the value.
     */
    public function verify($verifierId, $verifiedValue = null, $notes = null): bool
    {
        $this->status = self::STATUS_VERIFIED;
        $this->verifier_id = $verifierId;
        $this->verified_at = now();
        
        // Se não for fornecido um valor verificado, assume-se que o valor original está correto
        if ($verifiedValue !== null) {
            $this->verified_value = $verifiedValue;
        } else {
            $this->verified_value = $this->original_value;
        }

        if ($notes) {
            $this->notes = $this->notes ? $this->notes . "\n\nVerificação: " . $notes : "Verificação: " . $notes;
        }
        
        $this->save();

        // Update related billing item if exists
        if ($this->billingItem) {
            $this->billingItem->update([
                'unit_price' => $this->verified_value,
                'total_amount' => $this->verified_value * $this->billingItem->quantity,
                'verified_by_operator' => true,
                'verified_at' => now(),
                'verification_user' => $verifierId,
                'verification_notes' => $notes
            ]);

            // Recalculate billing batch total
            $this->recalculateBillingBatchTotal();
        }
        
        return true;
    }

    /**
     * Reject the value.
     */
    public function reject($verifierId, $notes = null): bool
    {
        $this->status = self::STATUS_REJECTED;
        $this->verifier_id = $verifierId;
        $this->verified_at = now();
        
        if ($notes) {
            $this->notes = $this->notes ? $this->notes . "\n\nRejeição: " . $notes : "Rejeição: " . $notes;
        }
        
        $this->save();

        // Update related billing item if exists
        if ($this->billingItem) {
            $this->billingItem->update([
                'verified_by_operator' => false,
                'verified_at' => now(),
                'verification_user' => $verifierId,
                'verification_notes' => $notes
            ]);
        }
        
        return true;
    }

    /**
     * Recalculate billing batch total after verification
     */
    private function recalculateBillingBatchTotal(): void
    {
        if (!$this->billingBatch) {
            return;
        }

        $totalAmount = $this->billingBatch->billingItems->sum('total_amount');
        
        $this->billingBatch->update([
            'total_amount' => $totalAmount
        ]);
    }

    /**
     * Create verification from billing item
     */
    public static function createFromBillingItem(BillingItem $billingItem, $reason = null): self
    {
        return self::create([
            'entity_type' => 'App\\Models\\BillingItem',
            'entity_id' => $billingItem->id,
            'value_type' => self::TYPE_BILLING_AMOUNT,
            'original_value' => $billingItem->unit_price,
            'verified_value' => $billingItem->unit_price,
            'status' => self::STATUS_PENDING,
            'requester_id' => auth()->id(),
            'billing_batch_id' => $billingItem->billing_batch_id,
            'billing_item_id' => $billingItem->id,
            'appointment_id' => $billingItem->item_type === 'appointment' ? $billingItem->item_id : null,
            'verification_reason' => $reason ?? 'Verificação automática de valor',
            'priority' => self::PRIORITY_MEDIUM,
            'due_date' => now()->addDays(3),
            'auto_approve_threshold' => 5.0, // 5% threshold
        ]);
    }

    /**
     * Create verification from appointment
     */
    public static function createFromAppointment(Appointment $appointment, $reason = null): self
    {
        return self::create([
            'entity_type' => 'App\\Models\\Appointment',
            'entity_id' => $appointment->id,
            'value_type' => self::TYPE_APPOINTMENT_PRICE,
            'original_value' => $appointment->procedure_price ?? 0,
            'verified_value' => $appointment->procedure_price ?? 0,
            'status' => self::STATUS_PENDING,
            'requester_id' => auth()->id(),
            'appointment_id' => $appointment->id,
            'verification_reason' => $reason ?? 'Verificação de preço do agendamento',
            'priority' => self::PRIORITY_HIGH,
            'due_date' => now()->addDays(2),
            'auto_approve_threshold' => 10.0, // 10% threshold
        ]);
    }

    /**
     * Get verification statistics
     */
    public static function getStatistics(): array
    {
        return [
            'total' => self::count(),
            'pending' => self::where('status', self::STATUS_PENDING)->count(),
            'verified' => self::where('status', self::STATUS_VERIFIED)->count(),
            'rejected' => self::where('status', self::STATUS_REJECTED)->count(),
            'auto_approved' => self::where('status', self::STATUS_AUTO_APPROVED)->count(),
            'overdue' => self::overdue()->count(),
            'high_priority' => self::highPriority()->count(),
        ];
    }

    /**
     * Get value difference percentage
     */
    public function getDifferencePercentage(): float
    {
        if ($this->original_value == 0) {
            return 0;
        }

        $difference = abs($this->original_value - $this->verified_value);
        return ($difference / $this->original_value) * 100;
    }

    /**
     * Get status text
     */
    public function getStatusTextAttribute(): string
    {
        return [
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_VERIFIED => 'Verificado',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_AUTO_APPROVED => 'Auto-aprovado',
        ][$this->status] ?? 'Desconhecido';
    }

    /**
     * Get priority text
     */
    public function getPriorityTextAttribute(): string
    {
        return [
            self::PRIORITY_LOW => 'Baixa',
            self::PRIORITY_MEDIUM => 'Média',
            self::PRIORITY_HIGH => 'Alta',
            self::PRIORITY_CRITICAL => 'Crítica',
        ][$this->priority] ?? 'Desconhecida';
    }

    /**
     * Get value type text
     */
    public function getValueTypeTextAttribute(): string
    {
        return [
            self::TYPE_APPOINTMENT_PRICE => 'Preço do Agendamento',
            self::TYPE_PROCEDURE_PRICE => 'Preço do Procedimento',
            self::TYPE_SPECIALTY_PRICE => 'Preço da Especialidade',
            self::TYPE_CONTRACT_PRICE => 'Preço do Contrato',
            self::TYPE_BILLING_AMOUNT => 'Valor de Cobrança',
        ][$this->value_type] ?? 'Desconhecido';
    }
} 