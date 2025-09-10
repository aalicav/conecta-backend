<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WhatsAppNumber extends Model
{
    use HasFactory;

    // Types
    const TYPE_DEFAULT = 'default';
    const TYPE_HEALTH_PLAN = 'health_plan';
    const TYPE_PROFESSIONAL = 'professional';
    const TYPE_CLINIC = 'clinic';

    protected $fillable = [
        'name',
        'phone_number',
        'instance_id',
        'token',
        'type',
        'is_active',
        'description',
        'settings'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array'
    ];

    /**
     * Health plans associated with this WhatsApp number
     */
    public function healthPlans(): BelongsToMany
    {
        return $this->belongsToMany(HealthPlan::class, 'health_plan_whatsapp_numbers');
    }

    /**
     * Get the default WhatsApp number for professionals/clinics
     */
    public static function getDefaultNumber(): ?self
    {
        return self::where('type', self::TYPE_DEFAULT)
                  ->where('is_active', true)
                  ->first();
    }

    /**
     * Get WhatsApp number for a specific health plan
     */
    public static function getNumberForHealthPlan(int $healthPlanId): ?self
    {
        return self::whereHas('healthPlans', function ($query) use ($healthPlanId) {
            $query->where('health_plan_id', $healthPlanId);
        })
        ->where('is_active', true)
        ->first();
    }

    /**
     * Get WhatsApp number for professionals/clinics
     */
    public static function getNumberForProfessionals(): ?self
    {
        return self::where('type', self::TYPE_PROFESSIONAL)
                  ->where('is_active', true)
                  ->first() ?? self::getDefaultNumber();
    }

    /**
     * Get WhatsApp number for clinics
     */
    public static function getNumberForClinics(): ?self
    {
        return self::where('type', self::TYPE_CLINIC)
                  ->where('is_active', true)
                  ->first() ?? self::getDefaultNumber();
    }

    /**
     * Get all active WhatsApp numbers
     */
    public static function getActiveNumbers(): \Illuminate\Database\Eloquent\Collection
    {
        return self::where('is_active', true)->get();
    }

    /**
     * Check if this number can be used for health plans
     */
    public function canBeUsedForHealthPlans(): bool
    {
        return in_array($this->type, [self::TYPE_DEFAULT, self::TYPE_HEALTH_PLAN]);
    }

    /**
     * Check if this number can be used for professionals
     */
    public function canBeUsedForProfessionals(): bool
    {
        return in_array($this->type, [self::TYPE_DEFAULT, self::TYPE_PROFESSIONAL]);
    }

    /**
     * Check if this number can be used for clinics
     */
    public function canBeUsedForClinics(): bool
    {
        return in_array($this->type, [self::TYPE_DEFAULT, self::TYPE_CLINIC]);
    }

    /**
     * Get type label
     */
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            self::TYPE_DEFAULT => 'Padrão',
            self::TYPE_HEALTH_PLAN => 'Plano de Saúde',
            self::TYPE_PROFESSIONAL => 'Profissionais',
            self::TYPE_CLINIC => 'Clínicas',
            default => 'Desconhecido'
        };
    }
}