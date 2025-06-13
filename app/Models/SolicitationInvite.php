<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitationInvite extends Model
{
    protected $fillable = [
        'solicitation_id',
        'provider_type',
        'provider_id',
        'status',
        'responded_at',
        'response_notes'
    ];

    protected $casts = [
        'responded_at' => 'datetime'
    ];

    /**
     * Get the solicitation that owns the invite.
     */
    public function solicitation(): BelongsTo
    {
        return $this->belongsTo(Solicitation::class);
    }

    /**
     * Get the provider (professional or clinic) that was invited.
     */
    public function provider()
    {
        return $this->morphTo('provider', 'provider_type', 'provider_id');
    }

    /**
     * Scope a query to only include pending invites.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include accepted invites.
     */
    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    /**
     * Scope a query to only include rejected invites.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
} 