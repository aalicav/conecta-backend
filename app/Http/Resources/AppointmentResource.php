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
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Patient attendance fields
            'patient_attended' => $this->patient_attended,
            'attendance_confirmed_at' => $this->attendance_confirmed_at,
            'attendance_confirmed_by' => $this->attendance_confirmed_by,
            'attendance_notes' => $this->attendance_notes,
            'eligible_for_billing' => $this->eligible_for_billing,
            'billing_batch_id' => $this->billing_batch_id,
            
            // Relationships
            'solicitation' => new SolicitationResource($this->whenLoaded('solicitation')),
            'provider' => $this->provider ? (
                $this->provider_type === 'App\\Models\\Clinic' 
                    ? new ClinicResource($this->provider)
                    : ($this->provider_type === 'App\\Models\\Professional' 
                        ? new ProfessionalResource($this->provider) 
                        : null)
            ) : null,
            'confirmed_by_user' => new UserResource($this->whenLoaded('confirmedBy')),
            'completed_by_user' => new UserResource($this->whenLoaded('completedBy')),
            'cancelled_by_user' => new UserResource($this->whenLoaded('cancelledBy')),
            'attendance_confirmed_by_user' => new UserResource($this->whenLoaded('attendanceConfirmedBy')),
            'created_by_user' => new UserResource($this->whenLoaded('createdBy')),
            'payment' => new PaymentResource($this->whenLoaded('payment')),
            'address' => $this->address,
            
            // Computed values - fixed to avoid closure serialization issues
            'is_active' => !is_null($this->status) ? $this->isActive() : null,
            'is_upcoming' => $this->scheduled_date ? ($this->scheduled_date->isFuture() && $this->isActive()) : null,
            'is_past_due' => $this->scheduled_date ? ($this->scheduled_date->isPast() && $this->isActive()) : null,
            'patient' => ($this->solicitation && $this->solicitation->patient) ? [
                'id' => $this->solicitation->patient->id,
                'name' => $this->solicitation->patient->name, 
                'cpf' => $this->solicitation->patient->cpf
            ] : null,
            'health_plan' => ($this->solicitation && $this->solicitation->healthPlan) ? [
                'id' => $this->solicitation->healthPlan->id,
                'name' => $this->solicitation->healthPlan->name
            ] : null,
            'procedure' => ($this->solicitation && $this->solicitation->tuss) ? [
                'id' => $this->solicitation->tuss->id,
                'code' => $this->solicitation->tuss->code,
                'description' => $this->solicitation->tuss->description
            ] : null,
        ];
    }
} 