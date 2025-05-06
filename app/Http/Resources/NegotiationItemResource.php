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
        $data = [
            'id' => $this->id,
            'negotiation_id' => $this->negotiation_id,
            'tuss_id' => $this->tuss_id,
            'proposed_value' => $this->proposed_value,
            'approved_value' => $this->approved_value,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
        
        // Add tuss relation only if loaded
        if ($this->relationLoaded('tuss')) {
            $data['tuss'] = new TussResource($this->tuss);
        }
        
        return $data;
    }
} 