<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PhoneResource extends JsonResource
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
            'number' => $this->number,
            'country_code' => $this->country_code,
            'type' => $this->type,
            'is_whatsapp' => $this->is_whatsapp,
            'is_primary' => $this->is_primary,
            'formatted_number' => $this->formatted_number,
            'phoneable_type' => $this->phoneable_type,
            'phoneable_id' => $this->phoneable_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
} 