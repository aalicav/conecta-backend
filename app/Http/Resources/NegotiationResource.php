<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Models\HealthPlan;
use App\Models\Professional;
use App\Models\Clinic;

class NegotiationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Determine entity type and create appropriate resource
        $negotiableResource = null;
        if ($this->whenLoaded('negotiable')) {
            $negotiableResource = match(get_class($this->negotiable)) {
                HealthPlan::class => new HealthPlanResource($this->negotiable),
                Professional::class => new ProfessionalResource($this->negotiable),
                Clinic::class => new ClinicResource($this->negotiable),
                default => null
            };
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'total_value' => $this->calculateTotalValue(),
            'notes' => $this->notes,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
            
            // Relations
            'negotiable_type' => $this->negotiable_type,
            'negotiable_id' => $this->negotiable_id,
            'negotiable' => $negotiableResource,
            'health_plan' => new HealthPlanResource($this->whenLoaded('healthPlan')), // For backward compatibility
            'creator' => new UserResource($this->whenLoaded('creator')),
            'items' => NegotiationItemResource::collection($this->whenLoaded('items')),
        ];
    }
} 