<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
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
            'name' => $this->name,
            'cpf' => $this->cpf,
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'health_plan_id' => $this->health_plan_id,
            'health_card_number' => $this->health_card_number,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'health_plan' => new HealthPlanResource($this->whenLoaded('healthPlan')),
            'phones' => PhoneResource::collection($this->whenLoaded('phones')),
            
            // Computed attributes - fixed to avoid closure serialization issues
            'age' => $this->birth_date ? $this->birth_date->age : null,
        ];
    }
} 