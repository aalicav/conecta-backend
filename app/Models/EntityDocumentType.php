<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EntityDocumentType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'entity_type',
        'name',
        'code',
        'description',
        'is_required',
        'is_active',
        'expiration_alert_days'
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'expiration_alert_days' => 'integer'
    ];

    public function scopeForEntity($query, $entityType)
    {
        return $query->where('entity_type', $entityType)
                    ->where('is_active', true);
    }

    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }
} 