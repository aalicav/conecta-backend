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
            
            // Campos de controle de ciclos
            'negotiation_cycle' => $this->negotiation_cycle,
            'max_cycles_allowed' => $this->max_cycles_allowed,
            
            // Campos de bifurcação
            'is_fork' => $this->is_fork,
            'forked_at' => $this->forked_at ? $this->forked_at->format('Y-m-d H:i:s') : null,
            'fork_count' => $this->fork_count,
            'parent_negotiation_id' => $this->parent_negotiation_id,
            
            // Relations
            'negotiable_type' => $this->negotiable_type,
            'negotiable_id' => $this->negotiable_id,
            'negotiable' => $negotiableResource,
            'health_plan' => new HealthPlanResource($this->whenLoaded('healthPlan')), // For backward compatibility
            'creator' => new UserResource($this->whenLoaded('creator')),
            'items' => NegotiationItemResource::collection($this->whenLoaded('items')),
            'parent_negotiation' => new NegotiationResource($this->whenLoaded('parentNegotiation')),
            'forked_negotiations' => NegotiationResource::collection($this->whenLoaded('forkedNegotiations')),
            'status_history' => $this->whenLoaded('statusHistory', function() {
                return $this->statusHistory->map(function($history) {
                    return [
                        'id' => $history->id,
                        'from_status' => $history->from_status,
                        'to_status' => $history->to_status,
                        'reason' => $history->reason,
                        'user' => new UserResource($history->user),
                        'created_at' => $history->created_at->format('Y-m-d H:i:s')
                    ];
                });
            }),
            
            // Approval information
            'approved_by' => $this->when($this->approved_by, function() {
                return [
                    'id' => $this->approver->id,
                    'name' => $this->approver->name,
                    'email' => $this->approver->email,
                ];
            }),
            'approved_at' => $this->approved_at,
            'approval_notes' => $this->approval_notes,
            
            // Rejection information
            'rejected_by' => $this->when($this->rejected_by, function() {
                return [
                    'id' => $this->rejecter->id,
                    'name' => $this->rejecter->name,
                    'email' => $this->rejecter->email,
                ];
            }),
            'rejected_at' => $this->rejected_at,
            'rejection_notes' => $this->rejection_notes,
            
            // Permissions
            'can_approve' => $request->user() && 
                           $request->user()->hasPermissionTo('approve_negotiations') && 
                           $request->user()->id !== $this->creator_id,
            'can_submit_for_approval' => $request->user() && 
                                       $request->user()->hasRole('commercial'),
            'can_edit' => $request->user() && (
                $request->user()->id === $this->creator_id || 
                $request->user()->hasRole(['super_admin', 'commercial'])
            ),
        ];
    }
} 