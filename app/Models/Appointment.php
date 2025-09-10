<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Carbon\Carbon;

class Appointment extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'solicitation_id',
        'provider_type',
        'provider_id',
        'status',
        'scheduled_date',
        'confirmed_date',
        'completed_date',
        'cancelled_date',
        'confirmed_by',
        'completed_by',
        'cancelled_by',
        'created_by',
        'address_id',
        'patient_attended',
        'attendance_confirmed_at',
        'attendance_confirmed_by',
        'attendance_notes',
        'eligible_for_billing',
        'billing_batch_id',
        'patient_confirmed',
        'professional_confirmed',
        'guide_status',
        'pre_confirmation_response',
        'pre_confirmation_response_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'scheduled_date' => 'datetime',
        'confirmed_date' => 'datetime',
        'completed_date' => 'datetime',
        'cancelled_date' => 'datetime',
        'attendance_confirmed_at' => 'datetime',
        'patient_attended' => 'boolean',
        'eligible_for_billing' => 'boolean',
        'patient_confirmed' => 'boolean',
        'professional_confirmed' => 'boolean',
        'pre_confirmation_response' => 'boolean',
        'pre_confirmation_response_at' => 'datetime',
    ];

    /**
     * Status constants
     */
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_MISSED = 'missed';

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::created(function ($appointment) {
            // Update solicitation status to scheduled
            $appointment->solicitation()->update(['status' => 'scheduled']);
        });

        static::updated(function ($appointment) {
            // If the appointment status changed to completed and attendance confirmed
            if ($appointment->isDirty('status') && $appointment->status === 'completed' && $appointment->patient_attended) {
                // Update solicitation status
                $appointment->solicitation()->update(['status' => 'completed']);
            }

            // If the appointment status changed to cancelled
            if ($appointment->isDirty('status') && $appointment->status === 'cancelled') {
                // If this was the only appointment for the solicitation, revert to pending
                $otherAppointments = Appointment::where('solicitation_id', $appointment->solicitation_id)
                    ->where('id', '!=', $appointment->id)
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if (!$otherAppointments) {
                    $appointment->solicitation()->update(['status' => 'pending']);
                }
            }
        });
    }

    /**
     * Get the solicitation that owns the appointment.
     */
    public function solicitation(): BelongsTo
    {
        return $this->belongsTo(Solicitation::class);
    }

    /**
     * Get the provider (clinic or professional) for the appointment.
     */
    public function provider(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who confirmed the appointment.
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * Get the user who completed the appointment.
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the user who cancelled the appointment.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Get the user who created the appointment.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the patient for this appointment through the solicitation.
     */
    public function patient()
    {
        return $this->hasOneThrough(
            Patient::class,
            Solicitation::class,
            'id', // Foreign key on solicitations table
            'id', // Foreign key on patients table
            'solicitation_id', // Local key on appointments table
            'patient_id' // Local key on solicitations table
        );
    }

    /**
     * Get the procedure for this appointment through the solicitation.
     */
    public function procedure()
    {
        return $this->hasOneThrough(
            TussProcedure::class,
            Solicitation::class,
            'id', // Foreign key on solicitations table
            'id', // Foreign key on tuss_procedures table
            'solicitation_id', // Local key on appointments table
            'tuss_id' // Local key on solicitations table
        );
    }

    /**
     * Get the address for this appointment.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the user who confirmed attendance.
     */
    public function attendanceConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendance_confirmed_by');
    }

    /**
     * Get the billing batch for this appointment.
     */
    public function billingBatch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class);
    }

    /**
     * Scope a query to only include scheduled appointments.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /**
     * Scope a query to only include confirmed appointments.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    /**
     * Scope a query to only include completed appointments.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include cancelled appointments.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope a query to only include missed appointments.
     */
    public function scopeMissed($query)
    {
        return $query->where('status', self::STATUS_MISSED);
    }

    /**
     * Scope a query to only include active appointments.
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED, self::STATUS_MISSED]);
    }

    /**
     * Scope a query to only include appointments for a specific provider.
     */
    public function scopeForProvider($query, $providerType, $providerId)
    {
        return $query->where('provider_type', $providerType)
            ->where('provider_id', $providerId);
    }

    /**
     * Scope a query to only include future appointments.
     */
    public function scopeFuture($query)
    {
        return $query->where('scheduled_date', '>', now());
    }

    /**
     * Scope a query to only include past appointments.
     */
    public function scopePast($query)
    {
        return $query->where('scheduled_date', '<', now());
    }

    /**
     * Check if the appointment is scheduled.
     */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * Check if the appointment is confirmed.
     */
    public function isConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Check if the appointment is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the appointment is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the appointment is missed.
     */
    public function isMissed(): bool
    {
        return $this->status === self::STATUS_MISSED;
    }

    /**
     * Check if the appointment is active.
     */
    public function isActive(): bool
    {
        return !$this->isCompleted() && !$this->isCancelled() && !$this->isMissed();
    }

    /**
     * Confirm the appointment.
     */
    public function confirm(int $userId): bool
    {
        if (!$this->isScheduled()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CONFIRMED,
            'confirmed_date' => now(),
            'confirmed_by' => $userId
        ]);
    }

    /**
     * Complete the appointment.
     */
    public function complete(int $userId): bool
    {
        if (!$this->isConfirmed()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_date' => now(),
            'completed_by' => $userId
        ]);
    }

    /**
     * Cancel the appointment.
     */
    public function cancel(int $userId): bool
    {
        if ($this->isCompleted() || $this->isCancelled() || $this->isMissed()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_date' => now(),
            'cancelled_by' => $userId
        ]);
    }

    /**
     * Mark the appointment as missed.
     */
    public function markAsMissed(): bool
    {
        if (!$this->isConfirmed()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_MISSED
        ]);
    }

    /**
     * Check if the appointment is in the future.
     */
    public function isFuture(): bool
    {
        return $this->scheduled_date->isFuture();
    }

    /**
     * Check if the appointment is in the past.
     */
    public function isPast(): bool
    {
        return $this->scheduled_date->isPast();
    }

    /**
     * Scope a query to only include appointments eligible for billing.
     */
    public function scopeEligibleForBilling($query)
    {
        return $query->where('eligible_for_billing', true);
    }

    /**
     * Scope a query to only include attended appointments.
     */
    public function scopeAttended($query)
    {
        return $query->where('patient_attended', true);
    }

    /**
     * Scope a query to only include missed appointments.
     */
    public function scopeMissedAttendance($query)
    {
        return $query->where('patient_attended', false);
    }

    /**
     * Scope a query to only include appointments without attendance marked.
     */
    public function scopePendingAttendance($query)
    {
        return $query->whereNull('patient_attended');
    }

    /**
     * Check if the patient attended the appointment.
     */
    public function hasPatientAttended(): bool
    {
        return $this->patient_attended === true;
    }

    /**
     * Check if the patient missed the appointment.
     */
    public function hasPatientMissed(): bool
    {
        return $this->patient_attended === false;
    }

    /**
     * Check if attendance is pending.
     */
    public function isAttendancePending(): bool
    {
        return is_null($this->patient_attended);
    }

    /**
     * Check if the appointment is eligible for billing.
     */
    public function isEligibleForBilling(): bool
    {
        return $this->eligible_for_billing === true;
    }

    /**
     * Mark patient as attended.
     */
    public function markAsAttended(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'patient_attended' => true,
            'attendance_confirmed_at' => now(),
            'attendance_confirmed_by' => $userId,
            'attendance_notes' => $notes,
            'status' => self::STATUS_COMPLETED
        ]);
    }

    /**
     * Mark patient as missed.
     */
    public function markAsMissedAttendance(int $userId, ?string $notes = null): bool
    {
        return $this->update([
            'patient_attended' => false,
            'attendance_confirmed_at' => now(),
            'attendance_confirmed_by' => $userId,
            'attendance_notes' => $notes,
            'status' => self::STATUS_MISSED
        ]);
    }

    /**
     * Mark appointment as eligible for billing.
     */
    public function markAsEligibleForBilling(): bool
    {
        return $this->update([
            'eligible_for_billing' => true
        ]);
    }

    /**
     * Rescheduling relationships and methods
     */
    public function reschedulings()
    {
        return $this->hasMany(AppointmentRescheduling::class, 'original_appointment_id');
    }

    public function rescheduledFrom()
    {
        return $this->hasOne(AppointmentRescheduling::class, 'new_appointment_id');
    }

    /**
     * Check if appointment can be rescheduled
     */
    public function canBeRescheduled(): bool
    {
        return in_array($this->status, [
            self::STATUS_SCHEDULED,
            self::STATUS_CONFIRMED,
            self::STATUS_PENDING_CONFIRMATION
        ]) && !$this->isCompleted() && !$this->isCancelled();
    }

    /**
     * Reschedule appointment to new date/time
     */
    public function reschedule(
        Carbon $newScheduledDate,
        User $requestedBy,
        string $reason,
        string $reasonDescription,
        ?Model $newProvider = null,
        ?string $notes = null
    ): AppointmentRescheduling {
        // Create new appointment
        $newAppointment = $this->replicate();
        $newAppointment->scheduled_date = $newScheduledDate;
        $newAppointment->status = self::STATUS_SCHEDULED;
        $newAppointment->patient_confirmed = false;
        $newAppointment->professional_confirmed = false;
        $newAppointment->guide_status = 'pending';
        $newAppointment->eligible_for_billing = false;
        $newAppointment->billing_batch_id = null;
        $newAppointment->created_by = $requestedBy->id;
        
        if ($newProvider) {
            $newAppointment->provider_type = get_class($newProvider);
            $newAppointment->provider_id = $newProvider->id;
        }
        
        $newAppointment->save();

        // Create rescheduling record
        $rescheduling = AppointmentRescheduling::create([
            'original_appointment_id' => $this->id,
            'new_appointment_id' => $newAppointment->id,
            'reason' => $reason,
            'reason_description' => $reasonDescription,
            'original_scheduled_date' => $this->scheduled_date,
            'new_scheduled_date' => $newScheduledDate,
            'original_provider_type_id' => $this->provider_id,
            'original_provider_type' => $this->provider_type,
            'new_provider_type_id' => $newAppointment->provider_id,
            'new_provider_type' => $newAppointment->provider_type,
            'provider_changed' => $newProvider ? true : false,
            'financial_impact' => $this->eligible_for_billing,
            'original_amount' => $this->getBillingAmount(),
            'new_amount' => $newAppointment->getBillingAmount(),
            'notes' => $notes,
            'requested_by' => $requestedBy->id,
            'status' => AppointmentRescheduling::STATUS_PENDING
        ]);

        // Cancel original appointment
        $this->cancel($requestedBy, "Reagendado para {$newScheduledDate->format('d/m/Y H:i')} - {$reasonDescription}");

        return $rescheduling;
    }

    /**
     * Get billing amount for appointment
     */
    public function getBillingAmount(): float
    {
        if (!$this->eligible_for_billing) {
            return 0;
        }

        // This would integrate with your existing billing logic
        // For now, return a placeholder
        return 0;
    }

    /**
     * Check if appointment was rescheduled
     */
    public function wasRescheduled(): bool
    {
        return $this->rescheduledFrom()->exists();
    }

    /**
     * Get original appointment if this was rescheduled
     */
    public function getOriginalAppointment(): ?Appointment
    {
        $rescheduling = $this->rescheduledFrom;
        return $rescheduling ? $rescheduling->originalAppointment : null;
    }

    /**
     * Get rescheduling history
     */
    public function getReschedulingHistory(): \Illuminate\Database\Eloquent\Collection
    {
        $history = collect();
        
        // Get all reschedulings from this appointment
        $reschedulings = $this->reschedulings()->with('newAppointment')->get();
        $history = $history->merge($reschedulings);
        
        // Get rescheduling that created this appointment
        $rescheduledFrom = $this->rescheduledFrom;
        if ($rescheduledFrom) {
            $history->prepend($rescheduledFrom);
        }
        
        return $history->sortBy('created_at');
    }
} 