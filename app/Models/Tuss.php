<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tuss extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tuss_procedures';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'description',
        'chapter',
        'group',
        'subgroup',
        'category',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the solicitations for this TUSS procedure.
     */
    public function solicitations(): HasMany
    {
        return $this->hasMany(Solicitation::class);
    }

    /**
     * Get the contracts for this TUSS procedure.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Scope a query to only include active procedures.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to search by code or description.
     */
    public function scopeSearch($query, $search)
    {
        return $query->where('code', 'like', "%{$search}%")
            ->orWhere('description', 'like', "%{$search}%");
    }

    /**
     * Scope a query to filter by chapter.
     */
    public function scopeInChapter($query, $chapter)
    {
        return $query->where('chapter', $chapter);
    }

    /**
     * Scope a query to filter by group.
     */
    public function scopeInGroup($query, $group)
    {
        return $query->where('group', $group);
    }

    /**
     * Scope a query to filter by subgroup.
     */
    public function scopeInSubgroup($query, $subgroup)
    {
        return $query->where('subgroup', $subgroup);
    }
} 