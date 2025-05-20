<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_type' => $this->user_type,
            'user_id' => $this->user_id,
            'event' => $this->event,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'old_values' => $this->old_values ? (is_array($this->old_values) ? $this->old_values : json_decode($this->old_values)) : null,
            'new_values' => $this->new_values ? (is_array($this->new_values) ? $this->new_values : json_decode($this->new_values)) : null,
            'url' => $this->url,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'tags' => $this->tags,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'formatted_date' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'user' => $this->when($this->user_id, function () {
                return [
                    'id' => $this->user_id,
                    'name' => optional($this->user)->name,
                    'email' => optional($this->user)->email,
                ];
            }),
            'auditable' => $this->when($this->auditable_id && $this->auditable_type, function () {
                $model = $this->auditable;
                return [
                    'id' => $this->auditable_id,
                    'type' => $this->auditable_type,
                    'name' => ($model && method_exists($model, 'getName')) ? $model->getName() : (
                        $model && isset($model->name) ? $model->name : null
                    ),
                ];
            }),
        ];
    }
} 