<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalAvailability;
use App\Models\Solicitation;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ProfessionalAvailabilityController extends Controller
{
    /**
     * The notification service instance.
     *
     * @var \App\Services\NotificationService
     */
    protected $notificationService;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\NotificationService  $notificationService
     * @return void
     */
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Submit availability for a solicitation
     */
    public function submitAvailability(Request $request)
    {
        // Ensure user is a professional or clinic
        if (!Auth::user()->hasRole('professional') && !Auth::user()->hasRole('clinic') && !Auth::user()->hasRole('network_manager') && !Auth::user()->hasRole('super_admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas profissionais e clínicas podem registrar disponibilidade'
            ], 403);
        }

        $request->validate([
            'solicitation_id' => 'required|exists:solicitations,id',
            'available_date' => 'required|date|after_or_equal:today',
            'available_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            // Get the solicitation
            $solicitation = Solicitation::findOrFail($request->solicitation_id);

            // Verify that the user was invited to this solicitation
            $invite = $solicitation->invites()
                ->where('provider_type', Auth::user()->hasRole('professional') ? 'professional' : 'clinic')
                ->where('provider_id', Auth::user()->entity_id)
                ->where('status', 'accepted')
                ->first();

            if (!$invite && !Auth::user()->hasRole('network_manager') && !Auth::user()->hasRole('super_admin')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não foi convidado para esta solicitação'
                ], 403);
            }

            // Verify that the professional/clinic has the required specialty for this solicitation
            // This verification is now handled through pricing contracts
            $hasPricingContract = false;
            
            if (Auth::user()->hasRole('professional')) {
                $hasPricingContract = \App\Models\Professional::where('id', Auth::user()->entity_id)
                    ->whereHas('pricingContracts', function ($query) use ($solicitation) {
                        $query->where('tuss_procedure_id', $solicitation->tuss_id)
                            ->where('is_active', true);
                    })
                    ->exists();
            } else {
                $hasPricingContract = \App\Models\Clinic::where('id', Auth::user()->entity_id)
                    ->whereHas('pricingContracts', function ($query) use ($solicitation) {
                        $query->where('tuss_procedure_id', $solicitation->tuss_id)
                            ->where('is_active', true);
                    })
                    ->exists();
            }

            if (!$hasPricingContract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não possui contrato de preço para este procedimento'
                ], 403);
            }

            // Verify that the availability date is within the solicitation's preferred date range
            $availableDate = Carbon::parse($request->available_date);
            if ($availableDate < $solicitation->preferred_date_start || $availableDate > $solicitation->preferred_date_end) {
                return response()->json([
                    'success' => false,
                    'message' => 'A data de disponibilidade deve estar dentro do período preferencial da solicitação'
                ], 422);
            }

            // Create the availability record
            $availability = ProfessionalAvailability::create([
                'solicitation_id' => $solicitation->id,
                'provider_type' => Auth::user()->hasRole('professional') ? 'professional' : 'clinic',
                'provider_id' => Auth::user()->entity_id,
                'available_date' => $request->available_date,
                'available_time' => $request->available_time,
                'notes' => $request->notes,
                'status' => 'pending'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Disponibilidade registrada com sucesso',
                'data' => $availability
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting availability: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao registrar disponibilidade',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get availabilities for a solicitation
     */
    public function getSolicitationAvailabilities(Request $request, $solicitationId)
    {
        try {
            // Get the solicitation
            $solicitation = Solicitation::findOrFail($solicitationId);

            // Get availabilities for this solicitation
            $availabilities = ProfessionalAvailability::where('solicitation_id', $solicitationId)
                ->with([
                    'professional.user',
                    'professional.addresses',
                    'professional.pricingContracts' => function($query) use ($solicitation) {
                        $query->where('tuss_procedure_id', $solicitation->tuss_id)
                            ->where('is_active', true);
                    },
                    'clinic.user',
                    'clinic.addresses',
                    'clinic.pricingContracts' => function($query) use ($solicitation) {
                        $query->where('tuss_procedure_id', $solicitation->tuss_id)
                            ->where('is_active', true);
                    }
                ])
                ->get()
                ->map(function($availability) {
                    // Get the provider (professional or clinic)
                    $provider = $availability->professional ?? $availability->clinic;
                    
                    // Get the active pricing contract for this procedure
                    $pricingContract = $provider->pricingContracts->first();
                    
                    // Add pricing information to the availability
                    $availability->price = $pricingContract ? $pricingContract->price : null;
                    $availability->pricing_contract = $pricingContract ? [
                        'id' => $pricingContract->id,
                        'price' => $pricingContract->price,
                        'notes' => $pricingContract->notes,
                        'start_date' => $pricingContract->start_date,
                        'end_date' => $pricingContract->end_date
                    ] : null;
                    
                    // Add provider information
                    $availability->provider = [
                        'id' => $provider->id,
                        'name' => $provider->name,
                        'type' => $availability->professional_id ? 'professional' : 'clinic',
                        'addresses' => $provider->addresses->map(function($address) {
                            return [
                                'id' => $address->id,
                                'street' => $address->street,
                                'number' => $address->number,
                                'complement' => $address->complement,
                                'neighborhood' => $address->neighborhood,
                                'city' => $address->city,
                                'state' => $address->state,
                                'postal_code' => $address->postal_code,
                                'is_primary' => $address->is_primary,
                                'full_address' => $address->full_address
                            ];
                        })
                    ];
                    
                    return $availability;
                });

            return response()->json([
                'success' => true,
                'data' => $availabilities
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching availabilities: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar disponibilidades',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Select an availability (admin only)
     */
    public function selectAvailability(Request $request, $availabilityId)
    {
        $request->validate([
            'notes' => 'nullable|string',
            'address_id' => 'nullable|exists:addresses,id',
            'custom_address' => 'nullable|array',
            'custom_address.street' => 'required_with:custom_address|string',
            'custom_address.number' => 'required_with:custom_address|string',
            'custom_address.complement' => 'nullable|string',
            'custom_address.neighborhood' => 'required_with:custom_address|string',
            'custom_address.city' => 'required_with:custom_address|string',
            'custom_address.state' => 'required_with:custom_address|string|size:2',
            'custom_address.postal_code' => 'required_with:custom_address|string|size:8',
        ]);

        try {
            DB::beginTransaction();

            $availability = ProfessionalAvailability::with(['professional.addresses', 'clinic.addresses'])
                ->findOrFail($availabilityId);
            
            // Check if availability is still pending
            if ($availability->status !== 'pending') {
                return response()->json([
                    'message' => 'Esta disponibilidade não está mais pendente'
                ], 400);
            }

            // Update availability status
            $availability->update([
                'status' => 'accepted',
                'selected_by' => auth()->id(),
                'selected_at' => now()
            ]);

            // Update solicitation status
            $solicitation = $availability->solicitation;
            $solicitation->update([
                'status' => 'scheduled'
            ]);

            // Create appointment
            $scheduledFor = Carbon::parse($availability->available_date)
                ->setTimeFromTimeString($availability->available_time);

            // Get the provider (professional or clinic)
            $provider = $availability->professional_id 
                ? $availability->professional
                : $availability->clinic;

            // Handle address selection
            $address = null;
            if ($request->address_id) {
                // Use existing address
                $address = $provider->addresses()->find($request->address_id);
            } elseif ($request->custom_address) {
                // Create temporary address for this appointment
                $address = $provider->addresses()->create([
                    'street' => $request->custom_address['street'],
                    'number' => $request->custom_address['number'],
                    'complement' => $request->custom_address['complement'] ?? null,
                    'neighborhood' => $request->custom_address['neighborhood'],
                    'city' => $request->custom_address['city'],
                    'state' => $request->custom_address['state'],
                    'postal_code' => $request->custom_address['postal_code'],
                    'is_primary' => false,
                    'is_temporary' => true, // Mark as temporary
                ]);
            } else {
                // Use primary address
                $address = $provider->addresses()->where('is_primary', true)->first();
            }

            $appointment = $solicitation->appointments()->create([
                'provider_type' => $availability->professional_id ? 'App\\Models\\Professional' : 'App\\Models\\Clinic',
                'provider_id' => $availability->professional_id ?? $availability->clinic_id,
                'patient_id' => $solicitation->patient_id,
                'health_plan_id' => $solicitation->health_plan_id,
                'tuss_id' => $solicitation->tuss_id,
                'status' => 'scheduled',
                'scheduled_date' => $scheduledFor->format('Y-m-d H:i:s'),
                'notes' => $request->notes,
                'address_id' => $address ? $address->id : null
            ]);

            // Reject other availabilities
            $rejectedAvailabilities = ProfessionalAvailability::where('solicitation_id', $solicitation->id)
                ->where('id', '!=', $availability->id)
                ->where('status', 'pending')
                ->get();

            ProfessionalAvailability::where('solicitation_id', $solicitation->id)
                ->where('id', '!=', $availability->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'rejected_at' => now(),
                    'rejection_reason' => 'Outra disponibilidade foi selecionada'
                ]);

            // Reject all pending invites for this solicitation
            $rejectedInvites = \App\Models\SolicitationInvite::where('solicitation_id', $solicitation->id)
                ->where('status', 'pending')
                ->get();

            \App\Models\SolicitationInvite::where('solicitation_id', $solicitation->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'responded_at' => now(),
                    'response_notes' => 'Disponibilidade selecionada - convites automaticamente rejeitados'
                ]);

            Log::info("Availability selected and others rejected", [
                'solicitation_id' => $solicitation->id,
                'selected_availability_id' => $availability->id,
                'rejected_availabilities_count' => $rejectedAvailabilities->count(),
                'rejected_invites_count' => $rejectedInvites->count(),
                'selected_provider_type' => $availability->professional_id ? 'professional' : 'clinic',
                'selected_provider_id' => $availability->professional_id ?? $availability->clinic_id
            ]);

            DB::commit();

            // Send notifications after successful transaction
            try {
                // Notify the selected provider
                $this->notificationService->notifyAvailabilitySelected($availability, $appointment);
                
                // Notify rejected providers (both availabilities and invites)
                if ($rejectedAvailabilities->isNotEmpty()) {
                    $this->notificationService->notifyAvailabilitiesRejected($rejectedAvailabilities);
                }

                // Notify providers with rejected invites
                if ($rejectedInvites->isNotEmpty()) {
                    foreach ($rejectedInvites as $rejectedInvite) {
                        try {
                            // Get the provider user
                            $providerClass = $rejectedInvite->provider_type;
                            $provider = $providerClass::find($rejectedInvite->provider_id);
                            
                            if ($provider && $provider->user) {
                                // Send generic notification about invite rejection
                                $this->notificationService->sendToUser($provider->user->id, [
                                    'title' => 'Convite de Agendamento Rejeitado',
                                    'body' => "Seu convite para a solicitação #{$rejectedInvite->solicitation_id} foi rejeitado pois outra disponibilidade foi selecionada.",
                                    'action_link' => "/solicitation-invites/{$rejectedInvite->id}",
                                    'icon' => 'x-circle',
                                    'type' => 'invite_rejected',
                                    'priority' => 'normal'
                                ]);
                                
                                Log::info("Sent invite rejection notification", [
                                    'invite_id' => $rejectedInvite->id,
                                    'provider_type' => $rejectedInvite->provider_type,
                                    'provider_id' => $rejectedInvite->provider_id,
                                    'user_id' => $provider->user->id
                                ]);
                            }
                        } catch (\Exception $inviteNotificationError) {
                            Log::error('Failed to send rejection notification for invite: ' . $inviteNotificationError->getMessage(), [
                                'invite_id' => $rejectedInvite->id,
                                'provider_type' => $rejectedInvite->provider_type,
                                'provider_id' => $rejectedInvite->provider_id
                            ]);
                        }
                    }
                }
                
                // Notify about the new appointment (using existing appointment notification)
                $this->notificationService->notifyAppointmentScheduled($appointment);
                
            } catch (\Exception $notificationError) {
                Log::error('Failed to send notifications after availability selection: ' . $notificationError->getMessage(), [
                    'availability_id' => $availability->id,
                    'appointment_id' => $appointment->id
                ]);
                // Don't throw the error, as the main operation was successful
            }

            return response()->json([
                'success' => true,
                'message' => 'Disponibilidade selecionada com sucesso',
                'data' => [
                    'availability' => $availability->load(['professional.addresses', 'clinic.addresses']),
                    'appointment' => $appointment->load('address'),
                    'rejected_availabilities_count' => $rejectedAvailabilities->count(),
                    'rejected_invites_count' => $rejectedInvites->count(),
                    'solicitation_status' => $solicitation->status
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error selecting availability: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Erro ao selecionar disponibilidade'
            ], 500);
        }
    }
} 