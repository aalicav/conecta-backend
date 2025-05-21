<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContractApproval extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'contract_id',
        'type',
        'status',
        'approved_at',
        'approved_by',
        'notes'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'approved_at' => 'datetime',
    ];

    /**
     * Get the contract that this approval record belongs to.
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Get the user who performed this approval action.
     */
    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the step display name.
     *
     * @return string
     */
    public function getStepDisplayAttribute()
    {
        return match($this->step) {
            'submission' => 'Submissão',
            'legal_review' => 'Análise Jurídica',
            'commercial_review' => 'Análise Comercial',
            'director_approval' => 'Aprovação da Direção',
            default => $this->step
        };
    }

    /**
     * Get the status display name.
     *
     * @return string
     */
    public function getStatusDisplayAttribute()
    {
        return match($this->status) {
            'pending' => 'Pendente',
            'completed' => 'Concluído',
            'rejected' => 'Rejeitado',
            default => $this->status
        };
    }

    /**
     * Check if the approval step is completed.
     *
     * @return bool
     */
    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    /**
     * Check if the approval step is rejected.
     *
     * @return bool
     */
    public function isRejected()
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if the approval step is pending.
     *
     * @return bool
     */
    public function isPending()
    {
        return $this->status === 'pending';
    }
} 