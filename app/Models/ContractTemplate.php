<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractTemplate extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'content',
        'description',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get the user who created this template.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this template.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get the contracts that use this template.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'template_id');
    }

    /**
     * Get the negotiations that use this template.
     */
    public function negotiations(): HasMany
    {
        return $this->hasMany(Negotiation::class, 'contract_template_id');
    }

    /**
     * Process the contract content with negotiation procedures.
     *
     * @param array $variables
     * @param \App\Models\Negotiation|null $negotiation
     * @return string
     */
    public function processContent(array $variables, $negotiation = null): string
    {
        $content = $this->content;

        // Replace standard variables
        foreach ($variables as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        // Process procedure table if negotiation is provided
        if ($negotiation) {
            $proceduresTable = $this->generateProceduresTable($negotiation);
            $content = str_replace('<!-- Tabela de procedimentos será preenchida dinamicamente -->', $proceduresTable, $content);
        }

        return $content;
    }

    /**
     * Generate HTML table with negotiation procedures.
     *
     * @param \App\Models\Negotiation $negotiation
     * @return string
     */
    protected function generateProceduresTable($negotiation): string
    {
        if (!$negotiation || $negotiation->items->isEmpty()) {
            return '<tr><td colspan="4" style="text-align: center;">Nenhum procedimento negociado encontrado.</td></tr>';
        }

        $tableRows = '';
        
        foreach ($negotiation->items as $item) {
            $tuss = $item->tuss;
            if (!$tuss) continue;

            $tableRows .= '<tr>';
            $tableRows .= '<td>' . $tuss->code . '</td>';
            $tableRows .= '<td>' . $tuss->name . '</td>';
            $tableRows .= '<td>R$ ' . number_format($item->approved_value ?: $item->proposed_value, 2, ',', '.') . '</td>';
            $tableRows .= '<td>' . ($item->notes ?: '-') . '</td>';
            $tableRows .= '</tr>';
        }

        return $tableRows;
    }

    /**
     * Generate a contract content with replaced variables and procedures.
     *
     * @param array $variables
     * @param \App\Models\Negotiation|null $negotiation
     * @return string
     */
    public function generateContractContent(array $variables, $negotiation = null): string
    {
        return $this->processContent($variables, $negotiation);
    }

    /**
     * Scope a query to only include active templates.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
} 