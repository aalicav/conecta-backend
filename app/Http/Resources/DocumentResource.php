<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use App\Models\User;

class DocumentResource extends JsonResource
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
            'type' => $this->type,
            'description' => $this->description,
            'file_path' => $this->when($this->file_path, function () {
                return Storage::url($this->file_path);
            }),
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'file_size' => $this->file_size,
            'issue_date' => $this->issue_date,
            'expiration_date' => $this->expiration_date,
            'is_verified' => $this->is_verified,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'documentable_type' => $this->documentable_type,
            'documentable_id' => $this->documentable_id,
            'verified_by' => new UserResource($this->whenLoaded('verifier')),
            'uploaded_by' => $this->when($this->uploaded_by, function() {
                return new UserResource(User::find($this->uploaded_by));
            }),
            'is_expired' => $this->when($this->expiration_date, function() {
                return $this->isExpired();
            }),
            'is_about_to_expire' => $this->when($this->expiration_date, function() {
                return $this->isAboutToExpire();
            }),
        ];
    }
} 