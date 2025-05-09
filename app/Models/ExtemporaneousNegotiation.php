<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExtemporaneousNegotiation extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'contract_id',
        'tuss_id',
        'requested_value',
        'approved_value',
        'justification',
        'approval_notes',
        'rejection_reason',
        'status',
        'urgency_level',
        'requested_by',
        'approved_by',
        'approved_at',
        'is_requiring_addendum',
        'addendum_included',
        'addendum_number',
        'addendum_date',
        'addendum_notes',
        'addendum_updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'requested_value' => 'float',
        'approved_value' => 'float',
        'approved_at' => 'datetime',
        'addendum_date' => 'date',
        'is_requiring_addendum' => 'boolean',
        'addendum_included' => 'boolean',
    ];

    /**
     * Get the contract associated with this negotiation.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the TUSS procedure associated with this negotiation.
     */
    public function tuss()
    {
        return $this->belongsTo(Tuss::class);
    }

    /**
     * Get the user who requested this negotiation.
     */
    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who approved/rejected this negotiation.
     */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who updated the addendum information.
     */
    public function addendumUpdatedBy()
    {
        return $this->belongsTo(User::class, 'addendum_updated_by');
    }

    /**
     * Scope a query to only include negotiations with pending status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include negotiations with approved status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include negotiations with rejected status.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to only include negotiations requiring addendum.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRequiringAddendum($query)
    {
        return $query->where('is_requiring_addendum', true)
                    ->where('addendum_included', false);
    }

    /**
     * Get status label for display.
     *
     * @return string
     */
    public function getStatusLabelAttribute()
    {
        return match($this->status) {
            'pending' => 'Pendente',
            'approved' => 'Aprovada',
            'rejected' => 'Rejeitada',
            default => 'Desconhecido'
        };
    }

    /**
     * Get urgency level label for display.
     *
     * @return string
     */
    public function getUrgencyLevelLabelAttribute()
    {
        return match($this->urgency_level) {
            'low' => 'Baixa',
            'medium' => 'Média',
            'high' => 'Alta',
            default => 'Média'
        };
    }

    /**
     * Get the value difference between requested and contract.
     *
     * @return float|null
     */
    public function getValueDifferenceAttribute()
    {
        // This would require fetching the original contract value for this TUSS
        // This is a simplified example - in practice, you'd need to get this from contract items
        return null;
    }
} 