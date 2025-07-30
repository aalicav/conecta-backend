<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
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
            'sender_phone' => $this->sender_phone,
            'recipient_phone' => $this->recipient_phone,
            'content' => $this->content,
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'direction' => $this->direction,
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
            'message_type' => $this->message_type,
            'template_name' => $this->template_name,
            'metadata' => $this->metadata,
            'conversation_partner' => $this->conversation_partner,
            'sender_entity' => $this->when($this->direction === 'inbound', function () {
                return $this->senderEntity();
            }),
            'recipient_entity' => $this->when($this->direction === 'outbound', function () {
                return $this->recipientEntity();
            }),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
} 