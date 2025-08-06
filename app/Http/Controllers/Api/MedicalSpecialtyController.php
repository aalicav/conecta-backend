<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalSpecialty;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class MedicalSpecialtyController extends Controller
{
    /**
     * Display a listing of medical specialties
     */
    public function index(Request $request)
    {
        try {
            $query = MedicalSpecialty::query();

            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('tuss_code', 'like', "%{$search}%")
                      ->orWhere('tuss_description', 'like', "%{$search}%")
                      ->orWhere('city', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->has('active')) {
                $query->where('active', $request->boolean('active'));
            }

            // Filter by negotiable
            if ($request->has('negotiable')) {
                $query->where('negotiable', $request->boolean('negotiable'));
            }

            // Filter by city
            if ($request->has('city') && !empty($request->city)) {
                $query->where('city', 'like', "%{$request->city}%");
            }

            // Filter by multiple cities
            if ($request->has('cities') && !empty($request->cities)) {
                $cities = explode(',', $request->cities);
                $query->whereIn('city', $cities);
            }

            // Filter by state
            if ($request->has('state') && !empty($request->state)) {
                $query->where('state', $request->state);
            }

            // Sort
            $sortBy = $request->get('sort_by', 'name');
            $sortOrder = $request->get('sort_order', 'asc');
            $query->orderBy($sortBy, $sortOrder);

            // Pagination
            $perPage = $request->get('per_page', 15);
            $specialties = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $specialties
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching medical specialties', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar especialidades médicas'
            ], 500);
        }
    }

    /**
     * Store a newly created medical specialty
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'tuss_code' => 'required|string|max:20|unique:medical_specialties,tuss_code',
                'tuss_description' => 'required|string|max:500',
                'negotiable' => 'boolean',
                'active' => 'boolean',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $specialty = MedicalSpecialty::create($request->all());

            Log::info('Medical specialty created', [
                'specialty_id' => $specialty->id,
                'name' => $specialty->name,
                'created_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Especialidade médica criada com sucesso',
                'data' => $specialty
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error creating medical specialty', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar especialidade médica'
            ], 500);
        }
    }

    /**
     * Display the specified medical specialty
     */
    public function show($id)
    {
        try {
            $specialty = MedicalSpecialty::with(['prices', 'activePrices'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $specialty
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching medical specialty', [
                'specialty_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Especialidade médica não encontrada'
            ], 404);
        }
    }

    /**
     * Update the specified medical specialty
     */
    public function update(Request $request, $id)
    {
        try {
            $specialty = MedicalSpecialty::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'tuss_code' => 'required|string|max:20|unique:medical_specialties,tuss_code,' . $id,
                'tuss_description' => 'required|string|max:500',
                'negotiable' => 'boolean',
                'active' => 'boolean',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dados inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $specialty->update($request->all());

            Log::info('Medical specialty updated', [
                'specialty_id' => $specialty->id,
                'name' => $specialty->name,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Especialidade médica atualizada com sucesso',
                'data' => $specialty
            ]);

        } catch (\Exception $e) {
            Log::error('Error updating medical specialty', [
                'specialty_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar especialidade médica'
            ], 500);
        }
    }

    /**
     * Remove the specified medical specialty
     */
    public function destroy($id)
    {
        try {
            $specialty = MedicalSpecialty::findOrFail($id);

            // Check if specialty is being used
            if ($specialty->prices()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir uma especialidade que possui preços associados'
                ], 400);
            }

            $specialty->delete();

            Log::info('Medical specialty deleted', [
                'specialty_id' => $id,
                'name' => $specialty->name,
                'deleted_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Especialidade médica excluída com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting medical specialty', [
                'specialty_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir especialidade médica'
            ], 500);
        }
    }

    /**
     * Toggle active status
     */
    public function toggleActive($id)
    {
        try {
            $specialty = MedicalSpecialty::findOrFail($id);
            $specialty->update(['active' => !$specialty->active]);

            $status = $specialty->active ? 'ativada' : 'desativada';

            Log::info('Medical specialty status toggled', [
                'specialty_id' => $id,
                'new_status' => $specialty->active,
                'updated_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => "Especialidade médica {$status} com sucesso",
                'data' => $specialty
            ]);

        } catch (\Exception $e) {
            Log::error('Error toggling medical specialty status', [
                'specialty_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao alterar status da especialidade médica'
            ], 500);
        }
    }

    /**
     * Get statistics for medical specialties
     */
    public function statistics()
    {
        try {
            $stats = [
                'total' => MedicalSpecialty::count(),
                'active' => MedicalSpecialty::where('active', true)->count(),
                'negotiable' => MedicalSpecialty::where('negotiable', true)->count(),
                'with_prices' => MedicalSpecialty::whereHas('prices')->count(),
                'recent_additions' => MedicalSpecialty::where('created_at', '>=', now()->subDays(30))->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching medical specialty statistics', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar estatísticas'
            ], 500);
        }
    }

    /**
     * Get active negotiations for a specialty
     */
    public function getActiveNegotiations($id)
    {
        try {
            $specialty = MedicalSpecialty::findOrFail($id);
            $negotiations = $specialty->activeNegotiations()->get();

            return response()->json([
                'success' => true,
                'data' => $negotiations
            ]);

        } catch (\Exception $e) {
            Log::error('Error fetching active negotiations', [
                'specialty_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar negociações ativas'
            ], 500);
        }
    }

    /**
     * Approve a price for a specialty
     */
    public function approvePrice($priceId)
    {
        try {
            $price = SpecialtyPrice::findOrFail($priceId);
            $price->update(['status' => 'approved']);

            Log::info('Specialty price approved', [
                'price_id' => $priceId,
                'specialty_id' => $price->medical_specialty_id,
                'approved_by' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Preço aprovado com sucesso',
                'data' => $price
            ]);

        } catch (\Exception $e) {
            Log::error('Error approving specialty price', [
                'price_id' => $priceId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar preço'
            ], 500);
        }
    }
} 