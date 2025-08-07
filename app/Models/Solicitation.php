<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\AppointmentScheduler;
use App\Services\SchedulingConfigService;
use Carbon\Carbon;

class Solicitation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'health_plan_id',
        'patient_id',
        'tuss_id',
        'medical_specialty_id',
        'status',
        'priority',
        'description',
        'requested_by',
        'scheduled_automatically',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
        'state',
        'city',
        'preferred_date_start',
        'preferred_date_end',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'scheduled_automatically' => 'boolean',
        'preferred_date_start' => 'date',
        'preferred_date_end' => 'date',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Priority constants
     */
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';

    /**
     * Get the health plan that owns the solicitation.
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the patient that this solicitation is for.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the TUSS procedure for this solicitation.
     */
    public function tuss(): BelongsTo
    {
        return $this->belongsTo(Tuss::class);
    }

    /**
     * Get the medical specialty for this solicitation.
     */
    public function medicalSpecialty(): BelongsTo
    {
        return $this->belongsTo(MedicalSpecialty::class, 'medical_specialty_id');
    }

    /**
     * Get the user who requested this solicitation.
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the appointments associated with this solicitation.
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Get the latest appointment for this solicitation.
     */
    public function latestAppointment()
    {
        return $this->appointments()->latest()->first();
    }

    /**
     * Get the invites for the solicitation.
     */
    public function invites()
    {
        return $this->hasMany(SolicitationInvite::class);
    }

    /**
     * Scope a query to only include pending solicitations.
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope a query to only include processing solicitations.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope a query to only include scheduled solicitations.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /**
     * Scope a query to only include completed solicitations.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope a query to only include cancelled solicitations.
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    /**
     * Scope a query to only include active solicitations (not completed or cancelled).
     */
    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    /**
     * Scope a query to only include solicitations for a specific health plan.
     */
    public function scopeForHealthPlan($query, $healthPlanId)
    {
        return $query->where('health_plan_id', $healthPlanId);
    }

    /**
     * Check if the solicitation is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the solicitation is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Check if the solicitation is scheduled.
     */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * Check if the solicitation is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the solicitation is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Check if the solicitation is active.
     */
    public function isActive(): bool
    {
        return !$this->isCompleted() && !$this->isCancelled();
    }

    /**
     * Mark the solicitation as processing.
     */
    public function markAsProcessing(): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        $this->status = self::STATUS_PROCESSING;
        return $this->save();
    }

    /**
     * Mark the solicitation as scheduled.
     */
    public function markAsScheduled(bool $automatically = false): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        $this->status = self::STATUS_SCHEDULED;
        $this->scheduled_automatically = $automatically;
        return $this->save();
    }

    /**
     * Mark the solicitation as completed.
     */
    public function markAsCompleted(): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        $this->status = self::STATUS_COMPLETED;
        $this->completed_at = now();
        return $this->save();
    }

    /**
     * Mark the solicitation as pending.
     */
    public function markAsPending(): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        $this->status = self::STATUS_PENDING;
        return $this->save();
    }

    /**
     * Cancel the solicitation.
     */
    public function cancel(string $reason = null): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        $this->status = self::STATUS_CANCELLED;
        $this->cancelled_at = now();
        $this->cancel_reason = $reason;
        return $this->save();
    }
} 