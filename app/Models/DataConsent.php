<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataConsent extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'consent_type',
        'consent_given',
        'entity_type',
        'entity_id',
        'consent_text',
        'ip_address',
        'user_agent',
        'consented_at',
        'revoked_at',
        'notes'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'consent_given' => 'boolean',
        'consented_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * Get the user that owns the consent record.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the related entity that this consent applies to.
     */
    public function consentable()
    {
        return $this->morphTo('entity');
    }

    /**
     * Scope a query to only include valid consents.
     */
    public function scopeValid($query)
    {
        return $query->where('consent_given', true)
            ->whereNull('revoked_at');
    }
} 