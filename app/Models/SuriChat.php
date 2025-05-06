<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SuriChat extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'suri_chats';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'session_id',
        'content',
        'role',
        'intent',
        'response_time',
        'metadata'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'metadata' => 'array',
        'response_time' => 'float'
    ];

    /**
     * Get the user that sent the message.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parent entity (polymorphic).
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include messages from a specific session.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $sessionId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSession($query, $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Scope a query to only include user messages.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUserMessages($query)
    {
        return $query->where('role', 'user');
    }

    /**
     * Scope a query to only include assistant messages.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAssistantMessages($query)
    {
        return $query->where('role', 'assistant');
    }
} 