<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SolicitationInvite;
use App\Models\ProfessionalAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class SolicitationInviteController extends Controller
{
    /**
     * List all invites for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $query = SolicitationInvite::with(['solicitation.patient', 'solicitation.tuss', 'solicitation.healthPlan']);

            // If user is not admin/manager/director, filter by their provider
            if (!Auth::user()->hasAnyRole(['network_manager', 'super_admin', 'director'])) {
                $query->where('provider_type', Auth::user()->hasRole('professional') ? 'professional' : 'clinic')
                      ->where('provider_id', Auth::user()->entity_id);
            }

            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Order by creation date, newest first
            $query->orderBy('created_at', 'desc');

            $invites = $query->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $invites
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching invites: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar convites',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept an invite and optionally create an availability
     */
    public function accept(Request $request, $inviteId)
    {
        $request->validate([
            'available_date' => 'required|date|after_or_equal:today',
            'available_time' => 'required|date_format:H:i',
            'notes' => 'nullable|string',
        ]);

        try {
            DB::beginTransaction();

            $invite = SolicitationInvite::findOrFail($inviteId);

            // Verify that the invite belongs to the authenticated user
            if (!Auth::user()->hasRole('super_admin') && 
                ($invite->provider_type !== (Auth::user()->hasRole('professional') ? 'professional' : 'clinic') ||
                $invite->provider_id !== Auth::user()->entity_id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Convite não encontrado'
                ], 404);
            }

            // Verify that the invite is still pending
            if ($invite->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este convite já foi respondido'
                ], 422);
            }

            // Verify that the availability date is within the solicitation's preferred date range
            $availableDate = Carbon::parse($request->available_date);
            if ($availableDate < $invite->solicitation->preferred_date_start || 
                $availableDate > $invite->solicitation->preferred_date_end) {
                return response()->json([
                    'success' => false,
                    'message' => 'A data de disponibilidade deve estar dentro do período preferencial da solicitação'
                ], 422);
            }

            // Update invite status
            $invite->update([
                'status' => 'accepted',
                'responded_at' => now(),
                'response_notes' => $request->notes
            ]);

            // Create availability
            $availability = ProfessionalAvailability::create([
                'solicitation_id' => $invite->solicitation_id,
                'professional_id' => $invite->provider_type === 'professional' ? $invite->provider_id : null,
                'clinic_id' => $invite->provider_type === 'clinic' ? $invite->provider_id : null,
                'available_date' => $request->available_date,
                'available_time' => $request->available_time,
                'notes' => $request->notes,
                'status' => 'pending'
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Convite aceito e disponibilidade registrada com sucesso',
                'data' => [
                    'invite' => $invite,
                    'availability' => $availability
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error accepting invite: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao aceitar convite',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject an invite
     */
    public function reject(Request $request, $inviteId)
    {
        $request->validate([
            'notes' => 'nullable|string'
        ]);

        try {
            $invite = SolicitationInvite::findOrFail($inviteId);

            // Verify that the invite belongs to the authenticated user
            if ($invite->provider_type !== (Auth::user()->hasRole('professional') ? 'professional' : 'clinic') ||
                $invite->provider_id !== Auth::user()->entity_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Convite não encontrado'
                ], 404);
            }

            // Verify that the invite is still pending
            if ($invite->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este convite já foi respondido'
                ], 422);
            }

            // Update invite status
            $invite->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'response_notes' => $request->notes
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Convite rejeitado com sucesso',
                'data' => $invite
            ]);

        } catch (\Exception $e) {
            Log::error('Error rejecting invite: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar convite',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 