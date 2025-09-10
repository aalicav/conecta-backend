<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class AppointmentRescheduling extends Model
{
    use HasFactory, SoftDeletes;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    const STATUS_COMPLETED = 'completed';

    // Reason constants
    const REASON_PAYMENT_NOT_RELEASED = 'payment_not_released';
    const REASON_DOCTOR_ABSENT = 'doctor_absent';
    const REASON_PATIENT_REQUEST = 'patient_request';
    const REASON_CLINIC_REQUEST = 'clinic_request';
    const REASON_OTHER = 'other';

    protected $fillable = [
        'rescheduling_number',
        'original_appointment_id',
        'new_appointment_id',
        'reason',
        'reason_description',
        'status',
        'original_scheduled_date',
        'new_scheduled_date',
        'original_provider_type_id',
        'original_provider_type',
        'new_provider_type_id',
        'new_provider_type',
        'provider_changed',
        'financial_impact',
        'original_amount',
        'new_amount',
        'notes',
        'requested_by',
        'approved_by',
        'approved_at',
        'approval_notes',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'whatsapp_sent',
        'whatsapp_sent_at'
    ];

    protected $casts = [
        'original_scheduled_date' => 'datetime',
        'new_scheduled_date' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
        'provider_changed' => 'boolean',
        'financial_impact' => 'boolean',
        'whatsapp_sent' => 'boolean',
        'original_amount' => 'decimal:2',
        'new_amount' => 'decimal:2'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($rescheduling) {
            if (empty($rescheduling->rescheduling_number)) {
                $rescheduling->rescheduling_number = $rescheduling->generateReschedulingNumber();
            }
        });
    }

    /**
     * Generate unique rescheduling number
     */
    public function generateReschedulingNumber(): string
    {
        $year = now()->year;
        $count = static::whereYear('created_at', $year)->count() + 1;
        return "REAG-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Relationships
     */
    public function originalAppointment()
    {
        return $this->belongsTo(Appointment::class, 'original_appointment_id');
    }

    public function newAppointment()
    {
        return $this->belongsTo(Appointment::class, 'new_appointment_id');
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Scopes
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeWithFinancialImpact($query)
    {
        return $query->where('financial_impact', true);
    }

    public function scopeWithProviderChange($query)
    {
        return $query->where('provider_changed', true);
    }

    /**
     * Status check methods
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Accessors
     */
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING => 'Pendente',
            self::STATUS_APPROVED => 'Aprovado',
            self::STATUS_REJECTED => 'Rejeitado',
            self::STATUS_COMPLETED => 'Concluído',
            default => 'Desconhecido'
        };
    }

    public function getReasonLabelAttribute(): string
    {
        return match($this->reason) {
            self::REASON_PAYMENT_NOT_RELEASED => 'Pagamento não liberado',
            self::REASON_DOCTOR_ABSENT => 'Médico ausente',
            self::REASON_PATIENT_REQUEST => 'Solicitação do paciente',
            self::REASON_CLINIC_REQUEST => 'Solicitação da clínica',
            self::REASON_OTHER => 'Outro motivo',
            default => 'Desconhecido'
        };
    }

    /**
     * Business logic methods
     */
    public function approve(User $user, string $notes = null): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'approval_notes' => $notes
        ]);

        return true;
    }

    public function reject(User $user, string $reason): bool
    {
        if (!$this->isPending()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_REJECTED,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason
        ]);

        return true;
    }

    public function complete(): bool
    {
        if (!$this->isApproved()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_COMPLETED
        ]);

        return true;
    }

    public function markWhatsAppSent(): bool
    {
        $this->update([
            'whatsapp_sent' => true,
            'whatsapp_sent_at' => now()
        ]);

        return true;
    }

    /**
     * Calculate time difference between original and new appointment
     */
    public function getTimeDifferenceAttribute(): string
    {
        $original = Carbon::parse($this->original_scheduled_date);
        $new = Carbon::parse($this->new_scheduled_date);
        
        $diffInDays = $original->diffInDays($new);
        
        if ($diffInDays === 0) {
            return 'Mesmo dia';
        } elseif ($diffInDays === 1) {
            return '1 dia';
        } else {
            return "{$diffInDays} dias";
        }
    }

    /**
     * Check if rescheduling is overdue (more than 7 days old and still pending)
     */
    public function isOverdue(): bool
    {
        return $this->isPending() && $this->created_at->diffInDays(now()) > 7;
    }

    /**
     * Get financial impact amount
     */
    public function getFinancialImpactAmountAttribute(): float
    {
        if (!$this->financial_impact) {
            return 0;
        }

        return ($this->new_amount ?? 0) - ($this->original_amount ?? 0);
    }
}