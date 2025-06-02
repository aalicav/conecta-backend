<?php

namespace App\Observers;

use App\Models\Negotiation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class NegotiationObserver
{
    /**
     * Handle the Negotiation "created" event.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function created(Negotiation $negotiation)
    {
        Log::info('Negotiation created', [
            'negotiation_id' => $negotiation->id,
            'creator_id' => Auth::id(),
            'status' => $negotiation->status
        ]);
    }

    /**
     * Handle the Negotiation "updated" event.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function updated(Negotiation $negotiation)
    {
        $changes = $negotiation->getDirty();
        
        if (!empty($changes)) {
            Log::info('Negotiation updated', [
                'negotiation_id' => $negotiation->id,
                'user_id' => Auth::id(),
                'changes' => $changes
            ]);

            // Log specific status changes
            if (isset($changes['status'])) {
                Log::info('Negotiation status changed', [
                    'negotiation_id' => $negotiation->id,
                    'old_status' => $negotiation->getOriginal('status'),
                    'new_status' => $negotiation->status,
                    'user_id' => Auth::id()
                ]);
            }

            // Log approval level changes
            if (isset($changes['approval_level'])) {
                Log::info('Negotiation approval level changed', [
                    'negotiation_id' => $negotiation->id,
                    'old_level' => $negotiation->getOriginal('approval_level'),
                    'new_level' => $negotiation->approval_level,
                    'user_id' => Auth::id()
                ]);
            }

            // Log formalization status changes
            if (isset($changes['formalization_status'])) {
                Log::info('Negotiation formalization status changed', [
                    'negotiation_id' => $negotiation->id,
                    'old_status' => $negotiation->getOriginal('formalization_status'),
                    'new_status' => $negotiation->formalization_status,
                    'user_id' => Auth::id()
                ]);
            }
        }
    }

    /**
     * Handle the Negotiation "deleted" event.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function deleted(Negotiation $negotiation)
    {
        Log::info('Negotiation deleted', [
            'negotiation_id' => $negotiation->id,
            'user_id' => Auth::id()
        ]);
    }

    /**
     * Handle the Negotiation "restored" event.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function restored(Negotiation $negotiation)
    {
        Log::info('Negotiation restored', [
            'negotiation_id' => $negotiation->id,
            'user_id' => Auth::id()
        ]);
    }

    /**
     * Handle the Negotiation "force deleted" event.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function forceDeleted(Negotiation $negotiation)
    {
        Log::info('Negotiation force deleted', [
            'negotiation_id' => $negotiation->id,
            'user_id' => Auth::id()
        ]);
    }
} 