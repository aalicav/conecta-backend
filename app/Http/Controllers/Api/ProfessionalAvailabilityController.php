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
            'available_date' => 'required|date|after:today',
            'available_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            DB::beginTransaction();

            $solicitation = Solicitation::findOrFail($request->solicitation_id);
            
            // Check if solicitation is in the correct state
            if ($solicitation->status !== 'waiting_professional_response') {
                return response()->json([
                    'message' => 'Solicitation is not in the correct state for availability submission'
                ], 400);
            }

            // Create availability record
            $availability = ProfessionalAvailability::create([
                'professional_id' => auth()->user()->professional->id,
                'solicitation_id' => $solicitation->id,
                'available_date' => $request->available_date,
                'available_time' => $request->available_time,
                'notes' => $request->notes,
                'status' => 'pending'
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Availability submitted successfully',
                'data' => $availability
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error submitting availability: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Error submitting availability'
            ], 500);
        }
    }

    /**
     * Get availabilities for a solicitation (admin only)
     */
    public function getSolicitationAvailabilities($solicitationId)
    {
        $availabilities = ProfessionalAvailability::with(['professional', 'selectedBy'])
            ->where('solicitation_id', $solicitationId)
            ->orderBy('available_date')
            ->orderBy('available_time')
            ->get();

        return response()->json([
            'data' => $availabilities
        ]);
    }

    /**
     * Select an availability (admin only)
     */
    public function selectAvailability(Request $request, $availabilityId)
    {
        $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            DB::beginTransaction();

            $availability = ProfessionalAvailability::findOrFail($availabilityId);
            
            // Check if availability is still pending
            if ($availability->status !== 'pending') {
                return response()->json([
                    'message' => 'This availability has already been processed'
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
                'scheduled_date' => $availability->available_date,
                'scheduled_time' => $availability->available_time,
                'notes' => $request->notes,
                'status' => 'scheduled'
            ]);

            // Reject other availabilities
            ProfessionalAvailability::where('solicitation_id', $solicitation->id)
                ->where('id', '!=', $availability->id)
                ->update([
                    'status' => 'rejected'
                ]);

            DB::commit();

            return response()->json([
                'message' => 'Availability selected successfully',
                'data' => [
                    'availability' => $availability,
                    'appointment' => $appointment
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error selecting availability: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Error selecting availability'
            ], 500);
        }
    }
} 