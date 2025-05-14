<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProfessionalResource extends JsonResource
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
            'professional_type' => $this->professional_type,
            'professional_id' => $this->professional_id,
            'specialty' => $this->specialty,
            'registration_number' => $this->registration_number,
            'registration_state' => $this->registration_state,
            'clinic_id' => $this->clinic_id,
            'bio' => $this->bio,
            'photo' => $this->photo ? Storage::url($this->photo) : null,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'phones' => PhoneResource::collection($this->whenLoaded('phones')),
            'addresses' => $this->whenLoaded('addresses'),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'clinic' => new ClinicResource($this->whenLoaded('clinic')),
            
            // Counts
            'appointments_count' => $this->when(isset($this->appointments_count), $this->appointments_count),
        ];
    }
} 