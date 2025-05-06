<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WhatsappMessageResource extends JsonResource
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
            'recipient' => $this->recipient,
            'message' => $this->message,
            'media_url' => $this->media_url,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'sent_at' => $this->sent_at ? $this->sent_at->format('Y-m-d H:i:s') : null,
            'delivered_at' => $this->delivered_at ? $this->delivered_at->format('Y-m-d H:i:s') : null,
            'read_at' => $this->read_at ? $this->read_at->format('Y-m-d H:i:s') : null,
            'external_id' => $this->external_id,
            'related_model' => $this->related_model_type ? [
                'type' => $this->related_model_type,
                'id' => $this->related_model_id,
            ] : null,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
} 