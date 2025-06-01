<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait Auditable
{
    /**
     * Boot the trait.
     */
    protected static function bootAuditable()
    {
        static::creating(function ($model) {
            if (Auth::check()) {
                $model->created_by = Auth::id();
            }
        });

        static::updating(function ($model) {
            if (Auth::check()) {
                $model->updated_by = Auth::id();
            }
        });
    }

    /**
     * Get the user that created the model.
     */
    public function creator()
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'created_by');
    }

    /**
     * Get the user that last updated the model.
     */
    public function updater()
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'updated_by');
    }

    /**
     * Record an audit entry.
     *
     * @param string $action
     * @param array $oldValues
     * @param array $newValues
     * @return void
     */
    public function recordAudit(string $action, array $oldValues = [], array $newValues = []): void
    {
        if (Auth::check()) {
            $this->audits()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ]);
        }
    }
} 