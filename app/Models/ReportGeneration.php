<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ReportGeneration extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'report_id',
        'file_path',
        'file_format',
        'parameters',
        'generated_by',
        'started_at',
        'completed_at',
        'status',
        'error_message',
        'rows_count',
        'file_size',
        'was_scheduled',
        'was_sent',
        'sent_to'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'parameters' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'was_scheduled' => 'boolean',
        'was_sent' => 'boolean',
        'sent_to' => 'array'
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted()
    {
        static::creating(function ($generation) {
            if (empty($generation->started_at)) {
                $generation->started_at = now();
            }
        });
        
        static::deleting(function ($generation) {
            // Delete the associated file when the generation is deleted
            if ($generation->file_path && Storage::exists($generation->file_path)) {
                Storage::delete($generation->file_path);
            }
        });
    }

    /**
     * Get the report this generation belongs to.
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * Get the user who generated this report.
     */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Mark the generation as completed.
     *
     * @param int|null $rowsCount The number of rows in the report
     * @param string|null $fileSize The size of the generated file
     * @return $this
     */
    public function markAsCompleted(?int $rowsCount = null, ?string $fileSize = null): self
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'rows_count' => $rowsCount,
            'file_size' => $fileSize
        ]);
        
        // Update the parent report's last generation timestamp
        $this->report->updateNextScheduledTime();
        
        return $this;
    }

    /**
     * Mark the generation as failed.
     *
     * @param string $errorMessage The error message
     * @return $this
     */
    public function markAsFailed(string $errorMessage): self
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage
        ]);
        
        return $this;
    }

    /**
     * Mark the generation as sent to recipients.
     *
     * @param array $recipients The recipients who received the report
     * @return $this
     */
    public function markAsSent(array $recipients): self
    {
        $this->update([
            'was_sent' => true,
            'sent_to' => $recipients
        ]);
        
        return $this;
    }

    /**
     * Get the download URL for this generation.
     *
     * @return string|null
     */
    public function getDownloadUrl(): ?string
    {
        if (!$this->file_path || !Storage::exists($this->file_path)) {
            return null;
        }
        
        return Storage::url($this->file_path);
    }

    /**
     * Get the human-readable file size.
     *
     * @return string
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }
        
        return $this->file_size;
    }

    /**
     * Get the human-readable duration of the report generation.
     *
     * @return string
     */
    public function getDurationAttribute(): string
    {
        if (!$this->completed_at || !$this->started_at) {
            return 'In progress';
        }
        
        $duration = $this->started_at->diffInSeconds($this->completed_at);
        
        if ($duration < 60) {
            return $duration . ' seconds';
        }
        
        return floor($duration / 60) . ' minutes ' . ($duration % 60) . ' seconds';
    }

    /**
     * Scope a query to only include completed generations.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope a query to only include failed generations.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to only include processing generations.
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    /**
     * Scope a query to only include generations that were scheduled.
     */
    public function scopeScheduled($query)
    {
        return $query->where('was_scheduled', true);
    }

    /**
     * Scope a query to only include generations that were sent.
     */
    public function scopeSent($query)
    {
        return $query->where('was_sent', true);
    }
} 