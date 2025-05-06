<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TussProcedure extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get solicitations for this procedure.
     */
    public function solicitations(): HasMany
    {
        return $this->hasMany(Solicitation::class);
    }

    /**
     * Get pricing contracts for this procedure.
     */
    public function pricingContracts(): HasMany
    {
        return $this->hasMany(PricingContract::class);
    }

    /**
     * Scope a query to only include active procedures.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include procedures in a specific category.
     */
    public function scopeInCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Find providers (clinics or professionals) who can perform this procedure.
     * 
     * @return array
     */
    public function availableProviders()
    {
        $clinics = Clinic::active()
            ->whereHas('pricingContracts', function ($query) {
                $query->where('tuss_procedure_id', $this->id)
                      ->where('is_active', true)
                      ->where(function ($q) {
                          $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', now());
                      });
            })
            ->get();

        $professionals = Professional::active()
            ->whereHas('pricingContracts', function ($query) {
                $query->where('tuss_procedure_id', $this->id)
                      ->where('is_active', true)
                      ->where(function ($q) {
                          $q->whereNull('end_date')
                            ->orWhere('end_date', '>=', now());
                      });
            })
            ->get();

        return [
            'clinics' => $clinics,
            'professionals' => $professionals
        ];
    }

    /**
     * Get the best price for this procedure across all providers.
     * 
     * @return array|null
     */
    public function getBestPrice()
    {
        $bestPrice = PricingContract::where('tuss_procedure_id', $this->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            })
            ->orderBy('price', 'asc')
            ->first();

        if (!$bestPrice) {
            return null;
        }

        $provider = $bestPrice->contractable;
        
        return [
            'price' => $bestPrice->price,
            'provider_type' => $bestPrice->contractable_type,
            'provider_id' => $bestPrice->contractable_id,
            'provider_name' => $provider->name,
        ];
    }
} 