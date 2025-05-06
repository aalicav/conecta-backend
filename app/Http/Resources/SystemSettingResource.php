<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemSettingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        // Cast the value according to its data type
        $castedValue = $this->resource::castValue($this->value, $this->data_type);
        
        return [
            'id' => $this->id,
            'key' => $this->key,
            'value' => $castedValue,
            'raw_value' => $this->value,
            'data_type' => $this->data_type,
            'group' => $this->group,
            'description' => $this->description,
            'is_public' => $this->is_public,
            'updated_by' => $this->updated_by ? [
                'id' => $this->updatedBy?->id,
                'name' => $this->updatedBy?->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
} 