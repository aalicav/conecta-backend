<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpecialtyPrice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'medical_specialty_id',
        'negotiation_id',
        'proposed_value',
        'approved_value',
        'status',
        'notes',
        'approved_at',
        'approved_by'
    ];

    protected $casts = [
        'proposed_value' => 'decimal:2',
        'approved_value' => 'decimal:2',
        'approved_at' => 'datetime'
    ];

    /**
     * Get the specialty this price belongs to
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(MedicalSpecialty::class, 'medical_specialty_id');
    }

    /**
     * Get the negotiation this price belongs to
     */
    public function negotiation(): BelongsTo
    {
        return $this->belongsTo(Negotiation::class);
    }

    /**
     * Get the user who approved this price
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope a query to only include pending prices
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved prices
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved')
            ->whereNotNull('approved_at');
    }
} 