<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ValueVerification extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'entity_type',
        'entity_id',
        'value_type',
        'original_value',
        'verified_value',
        'status',
        'requester_id',
        'verifier_id',
        'notes',
        'verified_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'original_value' => 'float',
        'verified_value' => 'float',
        'verified_at' => 'datetime',
    ];

    /**
     * Get the requester user.
     */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /**
     * Get the verifier user.
     */
    public function verifier()
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    /**
     * Get the related entity.
     */
    public function entity()
    {
        return $this->morphTo();
    }

    /**
     * Verify the value.
     */
    public function verify($verifierId, $verifiedValue = null)
    {
        $this->status = 'verified';
        $this->verifier_id = $verifierId;
        $this->verified_at = now();
        
        // Se não for fornecido um valor verificado, assume-se que o valor original está correto
        if ($verifiedValue !== null) {
            $this->verified_value = $verifiedValue;
        } else {
            $this->verified_value = $this->original_value;
        }
        
        $this->save();
        
        return $this;
    }

    /**
     * Reject the value.
     */
    public function reject($verifierId, $notes = null)
    {
        $this->status = 'rejected';
        $this->verifier_id = $verifierId;
        $this->verified_at = now();
        
        if ($notes) {
            $this->notes = $this->notes ? $this->notes . "\n\nRejeição: " . $notes : "Rejeição: " . $notes;
        }
        
        $this->save();
        
        return $this;
    }
} 