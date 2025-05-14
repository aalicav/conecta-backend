<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ClinicResource extends JsonResource
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
            'name' => $this->name,
            'cnpj' => $this->cnpj,
            'description' => $this->description,
            'cnes' => $this->cnes,
            'technical_director' => $this->technical_director,
            'technical_director_document' => $this->technical_director_document,
            'technical_director_professional_id' => $this->technical_director_professional_id,
            'parent_clinic_id' => $this->parent_clinic_id,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'logo' => $this->logo ? Storage::url($this->logo) : null,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'has_signed_contract' => $this->has_signed_contract,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'phones' => PhoneResource::collection($this->whenLoaded('phones')),
            'addresses' => $this->whenLoaded('addresses'),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'contract' => new ContractResource($this->whenLoaded('contract')),
            'parent_clinic' => new ClinicResource($this->whenLoaded('parentClinic')),
            'pricing_contracts' => PricingContractResource::collection($this->whenLoaded('pricingContracts')),
            'professionals' => ProfessionalResource::collection($this->whenLoaded('professionals')),
            
            // Counts
            'professionals_count' => $this->when(isset($this->professionals_count), $this->professionals_count),
            'appointments_count' => $this->when(isset($this->appointments_count), $this->appointments_count),
            'branches_count' => $this->when(isset($this->branches_count), $this->branches_count),
            
            // Computed values
            'distance' => $this->when(isset($this->distance), number_format($this->distance, 2) . ' km'),
        ];
        
        return $data;
    }
} 