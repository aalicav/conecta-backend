<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MedicalSpecialty extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'tuss_code',
        'tuss_description',
        'negotiable',
        'active'
    ];

    protected $casts = [
        'negotiable' => 'boolean',
        'active' => 'boolean'
    ];

    /**
     * Get the prices negotiated for this specialty
     */
    public function prices(): HasMany
    {
        return $this->hasMany(SpecialtyPrice::class);
    }

    /**
     * Get active negotiations for this specialty
     */
    public function activeNegotiations()
    {
        return $this->hasMany(SpecialtyPrice::class)
            ->where('status', 'pending')
            ->with('negotiation');
    }

    /**
     * Scope a query to only include negotiable specialties
     */
    public function scopeNegotiable($query)
    {
        return $query->where('negotiable', true)
            ->where('active', true);
    }
} 