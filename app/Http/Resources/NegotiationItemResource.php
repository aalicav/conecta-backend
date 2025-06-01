<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NegotiationItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'negotiation_id' => $this->negotiation_id,
            'tuss' => [
                'id' => $this->tuss->id,
                'code' => $this->tuss->code,
                'description' => $this->tuss->description,
            ],
            'proposed_value' => $this->proposed_value,
            'approved_value' => $this->approved_value,
            'status' => $this->status,
            'notes' => $this->notes,
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Audit information
            'created_by' => $this->when($this->creator, function() {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            'updated_by' => $this->when($this->updater, function() {
                return [
                    'id' => $this->updater->id,
                    'name' => $this->updater->name,
                ];
            }),
            
            // Helper flags
            'can_respond' => $this->canBeRespondedTo(),
            'is_approved' => $this->isApproved(),
            'is_rejected' => $this->isRejected(),
            'has_counter_offer' => $this->hasCounterOffer(),
        ];
    }
} 