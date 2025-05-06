<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'solicitation_id' => $this->solicitation_id,
            'provider_type' => $this->provider_type,
            'provider_id' => $this->provider_id,
            'status' => $this->status,
            'scheduled_date' => $this->scheduled_date,
            'confirmed_date' => $this->confirmed_date,
            'completed_date' => $this->completed_date,
            'cancelled_date' => $this->cancelled_date,
            'confirmed_by' => $this->confirmed_by,
            'completed_by' => $this->completed_by,
            'cancelled_by' => $this->cancelled_by,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'solicitation' => new SolicitationResource($this->whenLoaded('solicitation')),
            'provider' => $this->when($this->provider, function() {
                if ($this->provider_type === 'App\\Models\\Clinic') {
                    return new ClinicResource($this->provider);
                } elseif ($this->provider_type === 'App\\Models\\Professional') {
                    return new ProfessionalResource($this->provider);
                }
                return null;
            }),
            'confirmed_by_user' => new UserResource($this->whenLoaded('confirmedBy')),
            'completed_by_user' => new UserResource($this->whenLoaded('completedBy')),
            'cancelled_by_user' => new UserResource($this->whenLoaded('cancelledBy')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            
            // Computed values
            'is_active' => $this->when(!is_null($this->status), function() {
                return $this->isActive();
            }),
            'is_upcoming' => $this->when($this->scheduled_date, function() {
                return $this->scheduled_date->isFuture() && $this->isActive();
            }),
            'is_past_due' => $this->when($this->scheduled_date, function() {
                return $this->scheduled_date->isPast() && $this->isActive();
            }),
            'patient' => $this->when($this->solicitation && $this->solicitation->patient, function() {
                return [
                    'id' => $this->solicitation->patient->id,
                    'name' => $this->solicitation->patient->name, 
                    'cpf' => $this->solicitation->patient->cpf
                ];
            }),
            'health_plan' => $this->when($this->solicitation && $this->solicitation->healthPlan, function() {
                return [
                    'id' => $this->solicitation->healthPlan->id,
                    'name' => $this->solicitation->healthPlan->name
                ];
            }),
            'procedure' => $this->when($this->solicitation && $this->solicitation->tuss, function() {
                return [
                    'id' => $this->solicitation->tuss->id,
                    'code' => $this->solicitation->tuss->code,
                    'description' => $this->solicitation->tuss->description
                ];
            }),
        ];
    }
} 