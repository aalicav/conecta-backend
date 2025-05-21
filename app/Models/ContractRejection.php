<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractRejection extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'rejected_by',
        'reason',
        'previous_status',
        'notes'
    ];

    /**
     * Obter o contrato associado
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Obter o usuário que rejeitou
     */
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
} 