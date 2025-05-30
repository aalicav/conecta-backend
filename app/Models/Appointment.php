<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

            // Create a pending payment if needed
            $settingPayOnSchedule = SystemSetting::where('key', 'payment_on_schedule')
                ->where('value', 'true')
                ->first();

            if ($settingPayOnSchedule) {
                Payment::create([
                    'appointment_id' => $appointment->id,
                    'original_amount' => $appointment->price,
                    'final_amount' => $appointment->price,
                    'status' => 'pending',
                ]);
            }
        });

        static::updated(function ($appointment) {
            // If the appointment status changed to completed and attendance confirmed
            if ($appointment->isDirty('status') && $appointment->status === 'completed' && $appointment->patient_attended) {
                // Update solicitation status
                $appointment->solicitation()->update(['status' => 'completed']);

                // Create a payment if one doesn't exist yet
                if (!$appointment->payment) {
                    Payment::create([
                        'appointment_id' => $appointment->id,
                        'original_amount' => $appointment->price,
                        'final_amount' => $appointment->price,
                        'status' => 'pending',
                    ]);
                }
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

                // Cancel any pending payment
                if ($appointment->payment && $appointment->payment->status === 'pending') {
                    $appointment->payment->update(['status' => 'cancelled']);
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
     * Get the payment associated with this appointment.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    /**
     * Get the patient for this appointment through the solicitation.
     */
    public function patient()
    {
        return $this->solicitation->patient;
    }

    /**
     * Get the procedure for this appointment through the solicitation.
     */
    public function procedure()
    {
        return $this->solicitation->procedure;
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
} 