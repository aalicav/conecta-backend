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
        'status',
        'priority',
        'notes',
        'requested_by',
        'preferred_date_start',
        'preferred_date_end',
        'preferred_location_lat',
        'preferred_location_lng',
        'max_distance_km',
        'scheduled_automatically',
        'completed_at',
        'cancelled_at',
        'cancel_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'preferred_date_start' => 'datetime',
        'preferred_date_end' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'preferred_location_lat' => 'float',
        'preferred_location_lng' => 'float',
        'max_distance_km' => 'float',
        'scheduled_automatically' => 'boolean',
    ];

    /**
     * Status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_FAILED = 'failed';

    /**
     * Priority constants
     */
    const PRIORITY_LOW = 'low';
    const PRIORITY_NORMAL = 'normal';
    const PRIORITY_HIGH = 'high';
    const PRIORITY_URGENT = 'urgent';

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        // Removendo o trigger de agendamento automático aqui, pois agora é feito diretamente pelo controller
        // para garantir que o agendamento ocorra imediatamente após a criação da solicitação
    }

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
     * Scope a query to only include failed solicitations.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
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
     * Check if the solicitation is in processing.
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
     * Check if the solicitation has failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
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
        if (!$this->isPending()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_PROCESSING
        ]);
    }

    /**
     * Mark the solicitation as scheduled.
     */
    public function markAsScheduled(bool $automatically = false): bool
    {
        if (!$this->isPending() && !$this->isProcessing()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_SCHEDULED,
            'scheduled_automatically' => $automatically
        ]);
    }

    /**
     * Mark the solicitation as completed.
     */
    public function markAsCompleted(): bool
    {
        if (!$this->isScheduled()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now()
        ]);
    }

    /**
     * Mark the solicitation as failed.
     */
    public function markAsFailed(): bool
    {
        if (!$this->isPending() && !$this->isProcessing()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_FAILED
        ]);
    }

    /**
     * Cancel the solicitation.
     */
    public function cancel(string $reason = null): bool
    {
        if ($this->isCompleted() || $this->isCancelled()) {
            return false;
        }

        return $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason
        ]);
    }

    /**
     * Determine if this solicitation is still within its date range.
     */
    public function isWithinDateRange(): bool
    {
        $today = Carbon::today();
        return $today->between($this->preferred_date_start, $this->preferred_date_end);
    }

    /**
     * Determine if this solicitation's date range is in the future.
     */
    public function isInFuture(): bool
    {
        return $this->preferred_date_start->isFuture();
    }

    /**
     * Determine if this solicitation's date range is in the past.
     */
    public function isInPast(): bool
    {
        return $this->preferred_date_end->isPast();
    }
} 