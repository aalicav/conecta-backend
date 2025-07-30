<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sender_phone',
        'recipient_phone',
        'content',
        'media_url',
        'media_type',
        'direction', // 'inbound' or 'outbound'
        'status',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'external_id',
        'related_model_type',
        'related_model_id',
        'message_type', // 'text', 'media', 'template'
        'template_name',
        'metadata',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Direction constants
    const DIRECTION_INBOUND = 'inbound';
    const DIRECTION_OUTBOUND = 'outbound';

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_SENT = 'sent';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_READ = 'read';
    const STATUS_FAILED = 'failed';

    // Message type constants
    const TYPE_TEXT = 'text';
    const TYPE_MEDIA = 'media';
    const TYPE_TEMPLATE = 'template';

    /**
     * Get the related model (polymorphic)
     */
    public function relatedModel()
    {
        return $this->morphTo();
    }

    /**
     * Scope query to only include inbound messages
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', self::DIRECTION_INBOUND);
    }

    /**
     * Scope query to only include outbound messages
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', self::DIRECTION_OUTBOUND);
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
     * Scope query to only include successful messages
     */
    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', [
            self::STATUS_SENT,
            self::STATUS_DELIVERED,
            self::STATUS_READ
        ]);
    }

    /**
     * Get conversation partner phone (for grouping conversations)
     */
    public function getConversationPartnerAttribute()
    {
        return $this->direction === self::DIRECTION_INBOUND 
            ? $this->sender_phone 
            : $this->recipient_phone;
    }

    /**
     * Check if message is from a specific entity
     */
    public function isFromEntity($type, $id)
    {
        return $this->related_model_type === $type && $this->related_model_id === $id;
    }

    /**
     * Get the entity that sent this message (for inbound messages)
     */
    public function senderEntity()
    {
        if ($this->direction !== self::DIRECTION_INBOUND) {
            return null;
        }

        // Try to find the entity by phone number using polymorphic relationships
        $phone = $this->sender_phone;
        
        // Check if it's a patient
        $patient = Patient::whereHas('phones', function ($query) use ($phone) {
            $query->where('number', $phone);
        })->first();
        if ($patient) {
            return $patient;
        }

        // Check if it's a professional
        $professional = Professional::whereHas('phones', function ($query) use ($phone) {
            $query->where('number', $phone);
        })->first();
        if ($professional) {
            return $professional;
        }

        // Check if it's a clinic
        $clinic = Clinic::whereHas('phones', function ($query) use ($phone) {
            $query->where('number', $phone);
        })->first();
        if ($clinic) {
            return $clinic;
        }

        return null;
    }

    /**
     * Get the entity that should receive this message (for outbound messages)
     */
    public function recipientEntity()
    {
        if ($this->direction !== self::DIRECTION_OUTBOUND) {
            return null;
        }

        return $this->relatedModel();
    }
} 