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
        'active',
        'city',
        'state'
    ];

    protected $attributes = [
        'tuss_code' => '10101012',
        'tuss_description' => 'Consulta em Clínica Médica',
        'negotiable' => true,
        'city' => null,
        'state' => null
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
     * Get active prices for this specialty
     */
    public function activePrices(): HasMany
    {
        return $this->hasMany(SpecialtyPrice::class)
            ->where('active', true)
            ->whereDate('start_date', '<=', now())
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now());
            });
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
     * Get the price for a specific entity
     */
    public function getPriceForEntity(string $entityType, int $entityId): ?float
    {
        $price = $this->activePrices()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->latest('start_date')
            ->first();

        return $price ? $price->price : $this->default_price;
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