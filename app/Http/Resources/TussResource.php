<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TussResource extends JsonResource
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
            'code' => $this->code,
            'description' => $this->description,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'type' => $this->type,
            'amb_code' => $this->amb_code,
            'amb_description' => $this->amb_description,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
} 