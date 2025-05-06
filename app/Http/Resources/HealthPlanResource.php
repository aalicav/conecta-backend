<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class HealthPlanResource extends JsonResource
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
            'cnpj' => $this->cnpj,
            'ans_code' => $this->ans_code,
            'description' => $this->description,
            'legal_representative_name' => $this->legal_representative_name,
            'legal_representative_cpf' => $this->legal_representative_cpf,
            'legal_representative_position' => $this->legal_representative_position,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,
            'logo' => $this->logo ? Storage::url($this->logo) : null,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'has_signed_contract' => $this->has_signed_contract,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'phones' => PhoneResource::collection($this->whenLoaded('phones')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'approver' => new UserResource($this->whenLoaded('approver')),
            'contract' => new ContractResource($this->whenLoaded('contract')),
            'pricing_contracts' => PricingContractResource::collection($this->whenLoaded('pricingContracts')),
            'user' => new UserResource($this->whenLoaded('user')),
            'parent' => new HealthPlanResource($this->whenLoaded('parent')),
            'children' => HealthPlanResource::collection($this->whenLoaded('children')),
            'parent_relation_type' => $this->when($this->parent_id, $this->parent_relation_type),
            
            // Meta data
            'patients_count' => $this->when(isset($this->patients_count), $this->patients_count),
            'solicitations_count' => $this->when(isset($this->solicitations_count), $this->solicitations_count),
        ];
    }
} 