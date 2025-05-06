<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsappMessage extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'recipient',
        'message',
        'media_url',
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'external_id',
        'related_model_type',
        'related_model_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_FAILED = 'failed';

    /**
     * Get the related model (polymorphic)
     */
    public function relatedModel()
    {
        return $this->morphTo();
    }

    /**
     * Scope query to only include failed messages
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope query to only include pending messages
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope query to only include sent/delivered/read messages
     */
    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ
        ]);
    }
} 