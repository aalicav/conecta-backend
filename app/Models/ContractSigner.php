<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractSigner extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'name',
        'email',
        'cpf',
        'birthday',
        'order',
        'signature_id',
        'status',
        'signed_at',
        'signature_ip'
    ];

    protected $casts = [
        'birthday' => 'date',
        'signed_at' => 'datetime'
    ];

    /**
     * Obter o contrato associado
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
} 