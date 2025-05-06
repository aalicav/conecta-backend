<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Clinic extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'cnpj',
        'description',
        'cnes',
        'technical_director',
        'technical_director_document',
        'technical_director_professional_id',
        'parent_clinic_id',
        'address',
        'city',
        'state',
        'postal_code',
        'latitude',
        'longitude',
        'logo',
        'status',
        'approved_at',
        'approved_by',
        'has_signed_contract',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'approved_at' => 'datetime',
        'has_signed_contract' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the clinic branches (child clinics).
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Clinic::class, 'parent_clinic_id');
    }

    /**
     * Get the parent clinic.
     */
    public function parentClinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class, 'parent_clinic_id');
    }

    /**
     * Get the professionals associated with this clinic.
     */
    public function professionals(): HasMany
    {
        return $this->hasMany(Professional::class);
    }

    /**
     * Get the phones for the clinic.
     */
    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    /**
     * Get the documents for the clinic.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get the user that approved this clinic.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the administrators associated with this clinic.
     */
    public function administrators(): MorphMany
    {
        return $this->morphMany(User::class, 'entity');
    }

    /**
     * Get the contract for this clinic.
     */
    public function contract(): MorphOne
    {
        return $this->morphOne(Contract::class, 'contractable')
            ->where('type', 'clinic')
            ->latest();
    }

    /**
     * Get all contracts for this clinic.
     */
    public function contracts(): MorphMany
    {
        return $this->morphMany(Contract::class, 'contractable')
            ->where('type', 'clinic')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get pricing contracts for procedures.
     */
    public function pricingContracts(): MorphMany
    {
        return $this->morphMany(PricingContract::class, 'contractable');
    }

    /**
     * Get appointments associated with this clinic.
     */
    public function appointments(): MorphMany
    {
        return $this->morphMany(Appointment::class, 'provider');
    }

    /**
     * Scope a query to only include pending clinics.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved clinics.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected clinics.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to only include active clinics.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'approved')
            ->where('has_signed_contract', true)
            ->where('is_active', true);
    }

    /**
     * Get the nearby clinics within a certain distance (in kilometers).
     */
    public function scopeNearby($query, $latitude, $longitude, $distanceInKm = 10)
    {
        $haversine = "(6371 * acos(cos(radians($latitude)) 
                       * cos(radians(latitude)) 
                       * cos(radians(longitude) - radians($longitude)) 
                       + sin(radians($latitude)) 
                       * sin(radians(latitude))))";
        
        return $query->selectRaw("{$haversine} AS distance")
                    ->whereRaw("{$haversine} < ?", [$distanceInKm])
                    ->orderBy('distance');
    }
} 