<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'type',
        'file_path',
        'issue_date',
        'expiration_date',
        'description',
        'is_verified',
        'verified_by',
        'verified_at',
        'documentable_id',
        'documentable_type',
        'user_id',
        'uploaded_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'issue_date' => 'date',
        'expiration_date' => 'date',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the parent documentable model.
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who verified this document.
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Check if the document is expired.
     */
    public function isExpired(): bool
    {
        return $this->expiration_date && $this->expiration_date->isPast();
    }

    /**
     * Check if the document is about to expire (within 30 days).
     */
    public function isAboutToExpire(): bool
    {
        return $this->expiration_date && 
               $this->expiration_date->isFuture() && 
               $this->expiration_date->diffInDays(now()) <= 30;
    }

    /**
     * Get the full storage path to the document file.
     */
    public function getFullPathAttribute(): string
    {
        return storage_path('app/' . $this->file_path);
    }

    /**
     * Scope a query to only include verified documents.
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope a query to only include unverified documents.
     */
    public function scopeUnverified($query)
    {
        return $query->where('is_verified', false);
    }

    /**
     * Scope a query to only include documents of a specific type.
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope a query to only include expired documents.
     */
    public function scopeExpired($query)
    {
        return $query->where('expiration_date', '<', now());
    }

    /**
     * Scope a query to only include documents about to expire.
     */
    public function scopeAboutToExpire($query, $days = 30)
    {
        return $query->where('expiration_date', '>', now())
            ->where('expiration_date', '<', now()->addDays($days));
    }

    /**
     * Get the user that owns this document.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who uploaded this document.
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
} 