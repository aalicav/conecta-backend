<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SolicitationResource extends JsonResource
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
            'health_plan_id' => $this->health_plan_id,
            'patient_id' => $this->patient_id,
            'tuss_id' => $this->tuss_id,
            'medical_specialty_id' => $this->medical_specialty_id,
            'status' => $this->status,
            'priority' => $this->priority,
            'description' => $this->description,
            'requested_by' => $this->requested_by,
            'scheduled_automatically' => $this->scheduled_automatically,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'state' => $this->state,
            'city' => $this->city,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'health_plan' => new HealthPlanResource($this->whenLoaded('healthPlan')),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'tuss' => new TussResource($this->whenLoaded('tuss')),
            'medical_specialty' => new MedicalSpecialtyResource($this->whenLoaded('medicalSpecialty')),
            'requested_by_user' => new UserResource($this->whenLoaded('requestedBy')),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            
            // Computed values - fixed to avoid closure serialization issues
            'is_active' => !is_null($this->status) ? $this->isActive() : null,
        ];
    }
} 