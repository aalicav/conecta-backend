<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingItem extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'billing_batch_id',
        'item_type',
        'item_id',
        'reference_type',
        'reference_id',
        'description',
        'quantity',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'status',
        'notes',
        'verified_by_operator',
        'verified_at',
        'verification_user',
        'verification_notes',
        'patient_journey_data',
        'tuss_code',
        'tuss_description',
        'professional_name',
        'professional_specialty',
        'patient_name',
        'patient_document',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'item_id' => 'integer',
        'total_amount' => 'decimal:2',
        'verified_by_operator' => 'boolean',
        'verified_at' => 'datetime',
        'patient_journey_data' => 'array',
    ];

    /**
     * Get the billing batch that owns the item.
     */
    public function billingBatch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class);
    }

    /**
     * Get the appointment associated with the item.
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Get the glosas for this billing item.
     */
    public function glosas(): HasMany
    {
        return $this->hasMany(PaymentGloss::class);
    }

    /**
     * Get the value verifications for this billing item.
     */
    public function valueVerifications(): HasMany
    {
        return $this->hasMany(ValueVerification::class);
    }

    /**
     * Get pending value verifications for this billing item.
     */
    public function pendingValueVerifications(): HasMany
    {
        return $this->hasMany(ValueVerification::class)->pending();
    }

    /**
     * Check if this item needs value verification
     */
    public function needsValueVerification(): bool
    {
        // Check if there are pending verifications
        if ($this->pendingValueVerifications()->exists()) {
            return true;
        }

        // Check if the price is significantly different from expected
        $expectedPrice = $this->getExpectedPrice();
        if ($expectedPrice && $this->unit_price) {
            $difference = abs($this->unit_price - $expectedPrice);
            $percentage = ($difference / $expectedPrice) * 100;
            
            // If difference is more than 10%, needs verification
            return $percentage > 10;
        }

        return false;
    }

    /**
     * Get expected price based on contracts and rules
     */
    private function getExpectedPrice(): ?float
    {
        // This would implement the logic to get the expected price
        // based on contracts, specialty prices, etc.
        // For now, return null to indicate no expected price available
        return null;
    }
} 