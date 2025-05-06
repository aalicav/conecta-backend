<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PricingItemResource extends JsonResource
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
            'tuss_id' => $this->tuss_id,
            'tuss_code' => $this->whenLoaded('tuss', function () {
                return $this->tuss->code;
            }),
            'tuss_description' => $this->whenLoaded('tuss', function () {
                return $this->tuss->description;
            }),
            'price' => $this->price,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
} 