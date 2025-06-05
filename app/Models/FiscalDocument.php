<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FiscalDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'billing_batch_id',
        'number',
        'issue_date',
        'file_path',
        'file_type',
        'file_size'
    ];

    protected $casts = [
        'issue_date' => 'date',
        'file_size' => 'integer'
    ];

    protected $appends = [
        'file_url'
    ];

    /**
     * Get the billing batch that owns the document.
     */
    public function billingBatch(): BelongsTo
    {
        return $this->belongsTo(BillingBatch::class);
    }

    /**
     * Get the URL for the document file.
     */
    public function getFileUrlAttribute(): string
    {
        return Storage::url($this->file_path);
    }
} 