<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Patient extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'cpf',
        'birth_date',
        'gender',
        'health_plan_id',
        'health_card_number',
        'address',
        'city',
        'state',
        'postal_code',
        'latitude',
        'longitude',
        'location_consent',
        'photo',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'birth_date' => 'date',
        'latitude' => 'float',
        'longitude' => 'float',
        'location_consent' => 'boolean',
    ];

    /**
     * Get the health plan that the patient belongs to.
     */
    public function healthPlan(): BelongsTo
    {
        return $this->belongsTo(HealthPlan::class);
    }

    /**
     * Get the phones for the patient.
     */
    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    /**
     * Get solicitations for this patient.
     */
    public function solicitations(): HasMany
    {
        return $this->hasMany(Solicitation::class);
    }

    /**
     * Get appointments for this patient through solicitations.
     */
    public function appointments()
    {
        return Appointment::whereHas('solicitation', function ($query) {
            $query->where('patient_id', $this->id);
        });
    }

    /**
     * Calculate age based on birth date.
     *
     * @return int
     */
    public function getAgeAttribute(): int
    {
        return $this->birth_date->age;
    }

    /**
     * Calculate distance from another location.
     *
     * @param float $targetLatitude
     * @param float $targetLongitude
     * @return float|null Distance in kilometers or null if coordinates are not available
     */
    public function distanceFrom(float $targetLatitude, float $targetLongitude): ?float
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        // Earth's radius in kilometers
        $earthRadius = 6371;

        $latFrom = deg2rad($this->latitude);
        $lonFrom = deg2rad($this->longitude);
        $latTo = deg2rad($targetLatitude);
        $lonTo = deg2rad($targetLongitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));
        
        return $angle * $earthRadius;
    }

    /**
     * Scope a query to only include patients with location consent.
     */
    public function scopeWithLocationConsent($query)
    {
        return $query->where('location_consent', true);
    }

    /**
     * Scope a query to only include patients from a specific health plan.
     */
    public function scopeFromHealthPlan($query, $healthPlanId)
    {
        return $query->where('health_plan_id', $healthPlanId);
    }
} 