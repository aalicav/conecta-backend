<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DataDeletionRequest extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'reason',
        'status',
        'requested_at',
        'processed_at',
        'completed_at',
        'ip_address',
        'user_agent',
        'processed_by',
        'notes'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the deletion request.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who processed the request.
     */
    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
} 