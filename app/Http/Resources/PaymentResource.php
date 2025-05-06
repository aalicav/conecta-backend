<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
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
            'appointment_id' => $this->appointment_id,
            'original_amount' => $this->original_amount,
            'final_amount' => $this->final_amount,
            'status' => $this->status,
            'payment_date' => $this->payment_date,
            'payment_method' => $this->payment_method,
            'transaction_id' => $this->transaction_id,
            'gloss_amount' => $this->gloss_amount,
            'gloss_reason' => $this->gloss_reason,
            'gloss_applied_by' => $this->gloss_applied_by,
            'gloss_applied_at' => $this->gloss_applied_at,
            'invoice_number' => $this->invoice_number,
            'invoice_date' => $this->invoice_date,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'gloss_applied_by_user' => new UserResource($this->whenLoaded('glossAppliedByUser')),
        ];
    }
} 