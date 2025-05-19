<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NegotiationStatusHistory extends Model
{
    use HasFactory;

    /**
     * A tabela associada ao modelo.
     *
     * @var string
     */
    protected $table = 'negotiation_status_history';

    /**
     * Os atributos que são atribuíveis em massa.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'negotiation_id',
        'from_status',
        'to_status',
        'user_id',
        'reason',
    ];

    /**
     * Obter a negociação associada ao histórico.
     */
    public function negotiation()
    {
        return $this->belongsTo(Negotiation::class);
    }

    /**
     * Obter o usuário que realizou a alteração de status.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
