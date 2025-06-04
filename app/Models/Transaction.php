<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'amount',
        'status',
        'type',
        'description',
        'entity_id',
        'entity_type',
        'subscription_id',
        'health_plan_id',
        'due_date',
        'paid_at',
        'payment_method',
        'payment_details',
        'invoice_number',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'due_date' => 'datetime',
        'paid_at' => 'datetime',
        'payment_details' => 'array'
    ];

    public function entity()
    {
        return $this->morphTo();
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function healthPlan()
    {
        return $this->belongsTo(HealthPlan::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
} 