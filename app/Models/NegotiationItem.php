<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\Auditable;

class NegotiationItem extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_COUNTER_OFFERED = 'counter_offered';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'negotiation_id',
        'tuss_id',
        'proposed_value',
        'approved_value',
        'notes',
        'status',
        'responded_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'proposed_value' => 'decimal:2',
        'approved_value' => 'decimal:2',
        'responded_at' => 'datetime',
    ];

    /**
     * The status labels.
     *
     * @var array<string, string>
     */
    protected static $statusLabels = [
        'pending' => 'Pendente',
        'approved' => 'Aprovado',
        'rejected' => 'Rejeitado',
        'counter_offered' => 'Contra-proposta',
    ];

    /**
     * Get the negotiation that owns this item.
     */
    public function negotiation(): BelongsTo
    {
        return $this->belongsTo(Negotiation::class);
    }

    /**
     * Get the TUSS code associated with this item.
     */
    public function tuss(): BelongsTo
    {
        return $this->belongsTo(Tuss::class);
    }

    /**
     * Get the formatted status label.
     *
     * @return string
     */
    public function getStatusLabelAttribute(): string
    {
        return self::$statusLabels[$this->status] ?? $this->status;
    }

    /**
     * Check if the item can be responded to.
     *
     * @return bool
     */
    public function canBeRespondedTo(): bool
    {
        return $this->status === 'pending' && 
               $this->negotiation->status === 'submitted';
    }

    /**
     * Check if the item has been approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if the item has been rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if the item has a counter offer.
     *
     * @return bool
     */
    public function hasCounterOffer(): bool
    {
        return $this->status === 'counter_offered' && 
               $this->approved_value !== null;
    }
} 