<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalAvailability;
use App\Models\Solicitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class ProfessionalAvailabilityController extends Controller
{
    /**
     * Submit availability for a solicitation
     */
    public function submitAvailability(Request $request)
    {
        // Ensure user is a professional or clinic
        if (!Auth::user()->hasRole('professional') && !Auth::user()->hasRole('clinic')) {
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

            if (!$invite) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não foi convidado para esta solicitação'
                ], 403);
            }

            // Verify that the professional/clinic has the required specialty for this solicitation
            $hasSpecialty = $solicitation->tuss->specialties()
                ->where(function ($query) {
                    if (Auth::user()->hasRole('professional')) {
                        $query->whereHas('professionals', function ($q) {
                            $q->where('professionals.id', Auth::user()->entity_id);
                        });
                    } else {
                        $query->whereHas('clinics', function ($q) {
                            $q->where('clinics.id', Auth::user()->entity_id);
                        });
                    }
                })
                ->exists();

            if (!$hasSpecialty) {
                return response()->json([
                    'success' => false,
                    'message' => 'Você não possui a especialidade necessária para esta solicitação'
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
                ->with(['professional.user', 'clinic.user'])
                ->get();

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
            'notes' => 'nullable|string'
        ]);

        try {
            DB::beginTransaction();

            $availability = ProfessionalAvailability::findOrFail($availabilityId);
            
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
            $appointment = $solicitation->appointments()->create([
                'provider_type' => $availability->professional_id ? 'App\\Models\\Professional' : 'App\\Models\\Clinic',
                'provider_id' => $availability->professional_id ?? $availability->clinic_id,
                'patient_id' => $solicitation->patient_id,
                'health_plan_id' => $solicitation->health_plan_id,
                'tuss_id' => $solicitation->tuss_id,
                'status' => 'scheduled',
                'scheduled_for' => $availability->available_date . ' ' . $availability->available_time,
                'notes' => $request->notes
            ]);

            // Reject other availabilities
            ProfessionalAvailability::where('solicitation_id', $solicitation->id)
                ->where('id', '!=', $availability->id)
                ->update([
                    'status' => 'rejected'
                ]);

            DB::commit();

            return response()->json([
                'message' => 'Disponibilidade selecionada com sucesso',
                'data' => [
                    'availability' => $availability,
                    'appointment' => $appointment
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