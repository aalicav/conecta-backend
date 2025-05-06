<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ContractResource extends JsonResource
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
            'contract_number' => $this->contract_number,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'file_path' => $this->when($this->file_path, function () {
                return Storage::url($this->file_path);
            }),
            'is_signed' => $this->is_signed,
            'signed_at' => $this->signed_at,
            'signature_ip' => $this->signature_ip,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'contractable_type' => $this->contractable_type,
            'contractable_id' => $this->contractable_id,
            'creator' => new UserResource($this->whenLoaded('creator')),
            
            // Computed attributes
            'is_active' => $this->when(!is_null($this->status), function() {
                return $this->isActive();
            }),
            'is_expired' => $this->when(!is_null($this->end_date), function() {
                return $this->isExpired();
            }),
            'is_about_to_expire' => $this->when(!is_null($this->end_date), function() {
                return $this->isAboutToExpire();
            }),
        ];
    }
} 