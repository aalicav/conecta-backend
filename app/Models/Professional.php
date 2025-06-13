<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Notifications\Notifiable;

class Professional extends Model
{
    use HasFactory, SoftDeletes, Notifiable;

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
        'professional_type',
        'council_type',
        'council_number',
        'council_state',
        'specialty',
        'clinic_id',
        'address',
        'city',
        'state',
        'postal_code',
        'latitude',
        'longitude',
        'photo',
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
        'birth_date' => 'date',
        'latitude' => 'float',
        'longitude' => 'float',
        'approved_at' => 'datetime',
        'has_signed_contract' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the clinic that this professional belongs to.
     */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Get the phones for the professional.
     */
    public function phones(): MorphMany
    {
        return $this->morphMany(Phone::class, 'phoneable');
    }

    /**
     * Get the addresses for the professional.
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    /**
     * Get the documents for the professional.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    /**
     * Get the user that approved this professional.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user account associated with this professional.
     */
    public function user(): MorphOne
    {
        return $this->morphOne(User::class, 'entity');
    }

    /**
     * Get the contract for this professional.
     */
    public function contract(): MorphOne
    {
        return $this->morphOne(Contract::class, 'contractable')
            ->where('type', 'professional')
            ->latest();
    }

    /**
     * Get all contracts for this professional.
     */
    public function contracts(): MorphMany
    {
        return $this->morphMany(Contract::class, 'contractable')
            ->where('type', 'professional')
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get appointments assigned to this professional.
     */
    public function appointments(): MorphMany
    {
        return $this->morphMany(Appointment::class, 'provider');
    }

    /**
     * Get pricing contracts for procedures.
     */
    public function pricingContracts(): MorphMany
    {
        return $this->morphMany(PricingContract::class, 'contractable');
    }

    /**
     * Scope a query to only include pending professionals.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include approved professionals.
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope a query to only include rejected professionals.
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope a query to only include active professionals.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'approved')
            ->where('has_signed_contract', true)
            ->where('is_active', true);
    }

    /**
     * Get the nearby professionals within a certain distance (in kilometers).
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

    /**
     * Scope a query to only include professionals with specific specialties.
     */
    public function scopeWithSpecialty($query, $specialty)
    {
        return $query->where('specialty', $specialty);
    }

    /**
     * Scope a query to only include professionals that can perform a specific procedure.
     */
    public function scopeCanPerformProcedure($query, $tussProcedureId)
    {
        return $query->whereHas('pricingContracts', function ($query) use ($tussProcedureId) {
            $query->where('tuss_procedure_id', $tussProcedureId)
                  ->where('is_active', true)
                  ->where(function ($query) {
                      $query->whereNull('end_date')
                            ->orWhere('end_date', '>=', now());
                  });
        });
    }
} 