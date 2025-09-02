<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalEvaluation extends Model
{
    protected $fillable = [
        'appointment_id',
        'patient_id',
        'professional_id',
        'category',
        'score_range',
        'phone',
        'source',
        'responded_at',
        'comments',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    // Relationships
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    // Scopes
    public function scopePromoters($query)
    {
        return $query->where('category', 'promoter');
    }

    public function scopeNeutrals($query)
    {
        return $query->where('category', 'neutral');
    }

    public function scopeDetractors($query)
    {
        return $query->where('category', 'detractor');
    }

    public function scopeByProfessional($query, $professionalId)
    {
        return $query->where('professional_id', $professionalId);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('responded_at', [$startDate, $endDate]);
    }

    // Accessors
    public function getCategoryLabelAttribute(): string
    {
        return match($this->category) {
            'promoter' => 'Promotor',
            'neutral' => 'Neutro',
            'detractor' => 'Detrator',
            default => $this->category
        };
    }

    public function getSourceLabelAttribute(): string
    {
        return match($this->source) {
            'whatsapp_button' => 'WhatsApp',
            'web' => 'Web',
            'app' => 'App',
            default => $this->source
        };
    }
}
