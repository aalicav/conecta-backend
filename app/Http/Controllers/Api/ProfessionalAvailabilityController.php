<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfessionalAvailability;
use App\Models\Solicitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfessionalAvailabilityController extends Controller
{
    /**
     * Submit availability for a solicitation
     */
    public function submitAvailability(Request $request)
    {
        $request->validate([
            'solicitation_id' => 'required|exists:solicitations,id',
            'available_date' => 'required|date|after_or_equal:today',
            'available_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string',
            'professional_id' => 'required_without:clinic_id|exists:professionals,id',
            'clinic_id' => 'required_without:professional_id|exists:clinics,id',
        ]);

        try {
            DB::beginTransaction();

            $solicitation = Solicitation::findOrFail($request->solicitation_id);
            
            // Check if solicitation is in the correct state
            if ($solicitation->status !== 'waiting_professional_response') {
                return response()->json([
                    'message' => 'Esta solicitação não está mais aguardando disponibilidade'
                ], 400);
            }

            // Check if the date is within the preferred date range
            if ($request->available_date < $solicitation->preferred_date_start || 
                $request->available_date > $solicitation->preferred_date_end) {
                return response()->json([
                    'message' => 'A data selecionada está fora do período preferido pelo paciente'
                ], 400);
            }

            // Create availability record
            $availability = ProfessionalAvailability::create([
                'professional_id' => $request->professional_id,
                'clinic_id' => $request->clinic_id,
                'solicitation_id' => $request->solicitation_id,
                'available_date' => $request->available_date,
                'available_time' => $request->available_time,
                'notes' => $request->notes,
                'status' => 'pending'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Disponibilidade registrada com sucesso',
                'data' => $availability
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting availability: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Erro ao registrar disponibilidade'
            ], 500);
        }
    }

    /**
     * Get availabilities for a solicitation (admin only)
     */
    public function getSolicitationAvailabilities($solicitationId)
    {
        try {
            $availabilities = ProfessionalAvailability::with(['professional', 'clinic', 'selectedBy'])
                ->where('solicitation_id', $solicitationId)
                ->get();

            return response()->json([
                'data' => $availabilities
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching availabilities: ' . $e->getMessage());
            return response()->json([
                'message' => 'Erro ao buscar disponibilidades'
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
                'professional_id' => $availability->professional_id,
                'clinic_id' => $availability->clinic_id,
                'patient_id' => $solicitation->patient_id,
                'health_plan_id' => $solicitation->health_plan_id,
                'tuss_id' => $solicitation->tuss_id,
                'status' => 'pending',
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