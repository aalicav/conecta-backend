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
            'status' => $this->status,
            'priority' => $this->priority,
            'notes' => $this->notes,
            'requested_by' => $this->requested_by,
            'preferred_date_start' => $this->preferred_date_start,
            'preferred_date_end' => $this->preferred_date_end,
            'preferred_location_lat' => $this->preferred_location_lat,
            'preferred_location_lng' => $this->preferred_location_lng,
            'max_distance_km' => $this->max_distance_km,
            'scheduled_automatically' => $this->scheduled_automatically,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            
            // Relationships
            'health_plan' => new HealthPlanResource($this->whenLoaded('healthPlan')),
            'patient' => new PatientResource($this->whenLoaded('patient')),
            'tuss' => new TussResource($this->whenLoaded('tuss')),
            'requested_by_user' => new UserResource($this->whenLoaded('requestedBy')),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            
            // Computed values
            'is_active' => $this->when(!is_null($this->status), function() {
                return $this->isActive();
            }),
            'days_remaining' => $this->when($this->preferred_date_end, function() {
                if ($this->isActive() && $this->preferred_date_end->isFuture()) {
                    return now()->diffInDays($this->preferred_date_end);
                }
                return 0;
            }),
            'is_expired' => $this->when($this->preferred_date_end, function() {
                return $this->preferred_date_end->isPast() && $this->isActive();
            }),
        ];
    }
} 