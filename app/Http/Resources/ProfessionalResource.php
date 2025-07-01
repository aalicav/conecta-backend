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
            'document' => $this->cpf, // Para compatibilidade com o frontend
            'documentType' => 'cpf', // Para compatibilidade com o frontend
            'professional_type' => $this->professional_type,
            'professional_id' => $this->professional_id,
            'specialty' => $this->specialty,
            'registration_number' => $this->registration_number,
            'registration_state' => $this->registration_state,
            'clinic_id' => $this->clinic_id,
            'bio' => $this->bio,
            'photo' => $this->photo ? Storage::url($this->photo) : null,
            'avatar' => $this->photo ? Storage::url($this->photo) : null, // Para compatibilidade
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'is_active' => $this->is_active,
            'has_signed_contract' => $this->has_signed_contract,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Campos adicionais do modelo
            'birth_date' => $this->birth_date,
            'gender' => $this->gender,
            'council_type' => $this->council_type,
            'council_number' => $this->council_number,
            'council_state' => $this->council_state,
            
            // Dados do endereço principal (para compatibilidade)
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            
            // Email do user associado
            'email' => $this->whenLoaded('user', function() {
                return $this->user->email ?? null;
            }),
            
            // Telefone principal (para compatibilidade)
            'phone' => $this->whenLoaded('phones', function() {
                $primaryPhone = $this->phones->where('is_primary', true)->first();
                return $primaryPhone ? $primaryPhone->formatted_number : null;
            }),
            
            // Relationships
            'phones' => PhoneResource::collection($this->whenLoaded('phones')),
            'addresses' => $this->whenLoaded('addresses'),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'clinic' => new ClinicResource($this->whenLoaded('clinic')),
            'user' => new UserResource($this->whenLoaded('user')),
            'contract' => new ContractResource($this->whenLoaded('contract')),
            
            // Counts
            'appointments_count' => $this->when(isset($this->appointments_count), $this->appointments_count),
        ];
    }
} 