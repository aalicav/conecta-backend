<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemSettingBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'data',
        'created_by',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /**
     * Get the user who created the backup.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
} 