<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContractLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'action',
        'status',
        'user_id',
        'details',
        'notes',
        'changes'
    ];

    protected $casts = [
        'changes' => 'array'
    ];

    /**
     * Obter o contrato associado
     */
    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Obter o usuário que realizou a ação
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
} 