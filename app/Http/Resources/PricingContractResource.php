<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PricingContractResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'valid_from' => $this->valid_from,
            'valid_until' => $this->valid_until,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'contractable_type' => $this->contractable_type,
            'contractable_id' => $this->contractable_id,
            'pricing_items' => PricingItemResource::collection($this->whenLoaded('pricingItems')),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
        ];
    }
} 