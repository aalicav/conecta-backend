<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NegotiationItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'negotiation_id' => $this->negotiation_id,
            'tuss' => $this->tuss ? [
                'id' => $this->tuss->id,
                'code' => $this->tuss->code,
                'name' => $this->tuss->name,
                'description' => $this->tuss->description,
            ] : null,
            'medical_specialty' => $this->whenLoaded('medicalSpecialty', function() {
                return [
                    'id' => $this->medicalSpecialty->id,
                    'name' => $this->medicalSpecialty->name,
                    'default_price' => $this->medicalSpecialty->default_price,
                ];
            }),
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