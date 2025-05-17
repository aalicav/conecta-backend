<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NegotiationApprovalHistory extends Model
{
    protected $fillable = [
        'negotiation_id',
        'level',
        'status',
        'user_id',
        'notes'
    ];

    /**
     * Get the negotiation that owns the approval history.
     */
    public function negotiation(): BelongsTo
    {
        return $this->belongsTo(Negotiation::class);
    }

    /**
     * Get the user who made the approval decision.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
} 