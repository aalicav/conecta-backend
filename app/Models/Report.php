<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Report extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'type',
        'description',
        'parameters',
        'file_path',
        'file_format',
        'created_by',
        'last_generated_at',
        'is_scheduled',
        'schedule_frequency',
        'next_scheduled_at',
        'recipients',
        'is_public',
        'is_template'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'parameters' => 'array',
        'last_generated_at' => 'datetime',
        'next_scheduled_at' => 'datetime',
        'recipients' => 'array',
        'is_scheduled' => 'boolean',
        'is_public' => 'boolean',
        'is_template' => 'boolean'
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($report) {
            // If no file format is specified, use PDF as default
            if (empty($report->file_format)) {
                $report->file_format = 'pdf';
            }
            
            // Set next scheduled time based on frequency if scheduled
            if ($report->is_scheduled && $report->schedule_frequency) {
                $report->next_scheduled_at = static::calculateNextScheduledTime($report->schedule_frequency);
            }
        });
        
        static::updating(function ($report) {
            // Update next scheduled time if frequency changed or scheduling is enabled
            if ($report->isDirty('schedule_frequency') || ($report->isDirty('is_scheduled') && $report->is_scheduled)) {
                if ($report->is_scheduled && $report->schedule_frequency) {
                    $report->next_scheduled_at = static::calculateNextScheduledTime($report->schedule_frequency);
                } else {
                    $report->next_scheduled_at = null;
                }
            }
        });
    }

    /**
     * Get the user who created this report.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the report generations for this report.
     */
    public function generations(): HasMany
    {
        return $this->hasMany(ReportGeneration::class);
    }
    
    /**
     * Get the latest generation for this report.
     */
    public function latestGeneration()
    {
        return $this->generations()->latest('completed_at')->first();
    }
    
    /**
     * Get successful generations for this report.
     */
    public function successfulGenerations()
    {
        return $this->generations()->where('status', 'completed');
    }

    /**
     * Calculate the next scheduled time based on frequency.
     *
     * @param string $frequency
     * @return \Carbon\Carbon
     */
    protected static function calculateNextScheduledTime(string $frequency)
    {
        $now = now();
        
        switch ($frequency) {
            case 'daily':
                return $now->addDay()->startOfDay()->addHours(6); // 6 AM next day
            case 'weekly':
                return $now->addWeek()->startOfWeek()->addHours(6); // 6 AM Monday
            case 'monthly':
                return $now->addMonth()->startOfMonth()->addHours(6); // 6 AM first day of next month
            case 'quarterly':
                return $now->addQuarter()->startOfQuarter()->addHours(6); // 6 AM first day of next quarter
            default:
                return $now->addDay();
        }
    }
    
    /**
     * Check if this report is due for scheduled generation.
     *
     * @return bool
     */
    public function isScheduleDue(): bool
    {
        return $this->is_scheduled && 
               $this->next_scheduled_at && 
               $this->next_scheduled_at->isPast();
    }
    
    /**
     * Update the next scheduled time after a generation.
     *
     * @return void
     */
    public function updateNextScheduledTime(): void
    {
        if ($this->is_scheduled && $this->schedule_frequency) {
            $this->update([
                'next_scheduled_at' => static::calculateNextScheduledTime($this->schedule_frequency),
                'last_generated_at' => now()
            ]);
        } else {
            $this->update([
                'last_generated_at' => now()
            ]);
        }
    }

    /**
     * Scope a query to only include reports of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include scheduled reports.
     */
    public function scopeScheduled($query)
    {
        return $query->where('is_scheduled', true);
    }

    /**
     * Scope a query to only include reports due for generation.
     */
    public function scopeDueForGeneration($query)
    {
        return $query->where('is_scheduled', true)
                     ->whereNotNull('next_scheduled_at')
                     ->where('next_scheduled_at', '<=', now());
    }

    /**
     * Scope a query to only include public reports.
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope a query to only include template reports.
     */
    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }
} 