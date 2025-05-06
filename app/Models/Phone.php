<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Phone extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'number',
        'country_code',
        'type',
        'is_whatsapp',
        'is_primary',
        'phoneable_id',
        'phoneable_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_whatsapp' => 'boolean',
        'is_primary' => 'boolean',
    ];

    /**
     * Get the parent phoneable model.
     */
    public function phoneable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Format the phone number for display.
     */
    public function getFormattedNumberAttribute(): string
    {
        return $this->country_code . ' ' . $this->number;
    }

    /**
     * Scope a query to only include whatsapp numbers.
     */
    public function scopeWhatsapp($query)
    {
        return $query->where('is_whatsapp', true);
    }

    /**
     * Scope a query to only include primary numbers.
     */
    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope a query to only include phones of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }
} 