<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalAvailability extends Model
{
    use HasFactory;

    protected $fillable = [
        'professional_id',
        'solicitation_id',
        'available_date',
        'available_time',
        'notes',
        'status', // pending, accepted, rejected
        'selected_by',
        'selected_at'
    ];

    protected $casts = [
        'available_date' => 'date',
        'available_time' => 'datetime',
        'selected_at' => 'datetime'
    ];

    /**
     * Get the professional that owns this availability.
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * Get the solicitation that this availability is for.
     */
    public function solicitation(): BelongsTo
    {
        return $this->belongsTo(Solicitation::class);
    }

    /**
     * Get the admin who selected this availability.
     */
    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by');
    }

    /**
     * Scope a query to only include pending availabilities.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include accepted availabilities.
     */
    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    /**
     * Scope a query to only include rejected availabilities.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
} 