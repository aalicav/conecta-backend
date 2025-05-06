<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'email' => $this->email,
            'avatar' => $this->avatar ? url($this->avatar) : null,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'roles' => $this->when($this->roles, function () {
                return $this->roles->pluck('name');
            }),
            'permissions' => $this->when($this->permissions, function () {
                return $this->getAllPermissions()->pluck('name');
            }),
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'health_plan_id' => $this->health_plan_id,
            'clinic_id' => $this->clinic_id,
            'professional_id' => $this->professional_id,
        ];
    }
} 