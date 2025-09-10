<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppNumber;
use App\Models\HealthPlan;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WhatsAppNumberController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:manage whatsapp numbers')->only(['store', 'update', 'destroy']);
    }

    /**
     * Display a listing of WhatsApp numbers
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = WhatsAppNumber::with(['healthPlans']);

            // Filter by type
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone_number', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            $numbers = $query->orderBy('name')->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $numbers
            ]);

        } catch (\Exception $e) {
            Log::error('Error listing WhatsApp numbers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar números do WhatsApp'
            ], 500);
        }
    }

    /**
     * Store a newly created WhatsApp number
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'phone_number' => 'required|string|unique:whatsapp_numbers,phone_number',
                'instance_id' => 'required|string|unique:whatsapp_numbers,instance_id',
                'token' => 'required|string',
                'type' => 'required|string|in:' . implode(',', [
                    WhatsAppNumber::TYPE_DEFAULT,
                    WhatsAppNumber::TYPE_HEALTH_PLAN,
                    WhatsAppNumber::TYPE_PROFESSIONAL,
                    WhatsAppNumber::TYPE_CLINIC
                ]),
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'health_plan_ids' => 'nullable|array',
                'health_plan_ids.*' => 'exists:health_plans,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $whatsappNumber = WhatsAppNumber::create([
                'name' => $request->name,
                'phone_number' => $request->phone_number,
                'instance_id' => $request->instance_id,
                'token' => $request->token,
                'type' => $request->type,
                'description' => $request->description,
                'is_active' => $request->boolean('is_active', true),
                'settings' => $request->settings
            ]);

            // Associate health plans if provided
            if ($request->has('health_plan_ids') && is_array($request->health_plan_ids)) {
                $whatsappNumber->healthPlans()->sync($request->health_plan_ids);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Número do WhatsApp criado com sucesso',
                'data' => $whatsappNumber->load('healthPlans')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating WhatsApp number: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar número do WhatsApp'
            ], 500);
        }
    }

    /**
     * Display the specified WhatsApp number
     */
    public function show(WhatsAppNumber $whatsappNumber): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data' => $whatsappNumber->load('healthPlans')
            ]);

        } catch (\Exception $e) {
            Log::error('Error showing WhatsApp number: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao exibir número do WhatsApp'
            ], 500);
        }
    }

    /**
     * Update the specified WhatsApp number
     */
    public function update(Request $request, WhatsAppNumber $whatsappNumber): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'phone_number' => 'sometimes|required|string|unique:whatsapp_numbers,phone_number,' . $whatsappNumber->id,
                'instance_id' => 'sometimes|required|string|unique:whatsapp_numbers,instance_id,' . $whatsappNumber->id,
                'token' => 'sometimes|required|string',
                'type' => 'sometimes|required|string|in:' . implode(',', [
                    WhatsAppNumber::TYPE_DEFAULT,
                    WhatsAppNumber::TYPE_HEALTH_PLAN,
                    WhatsAppNumber::TYPE_PROFESSIONAL,
                    WhatsAppNumber::TYPE_CLINIC
                ]),
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'settings' => 'nullable|array',
                'health_plan_ids' => 'nullable|array',
                'health_plan_ids.*' => 'exists:health_plans,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro de validação',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $whatsappNumber->update($request->only([
                'name', 'phone_number', 'instance_id', 'token', 'type', 
                'description', 'is_active', 'settings'
            ]));

            // Update health plan associations
            if ($request->has('health_plan_ids')) {
                $whatsappNumber->healthPlans()->sync($request->health_plan_ids ?? []);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Número do WhatsApp atualizado com sucesso',
                'data' => $whatsappNumber->load('healthPlans')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating WhatsApp number: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar número do WhatsApp'
            ], 500);
        }
    }

    /**
     * Remove the specified WhatsApp number
     */
    public function destroy(WhatsAppNumber $whatsappNumber): JsonResponse
    {
        try {
            // Check if number is being used
            if ($whatsappNumber->healthPlans()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir um número que está associado a planos de saúde'
                ], 422);
            }

            $whatsappNumber->delete();

            return response()->json([
                'success' => true,
                'message' => 'Número do WhatsApp excluído com sucesso'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting WhatsApp number: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir número do WhatsApp'
            ], 500);
        }
    }

    /**
     * Get health plans available for association
     */
    public function getAvailableHealthPlans(Request $request): JsonResponse
    {
        try {
            $query = HealthPlan::where('status', 'active');

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('cnpj', 'like', "%{$search}%");
                });
            }

            $healthPlans = $query->select('id', 'name', 'cnpj', 'ans_code')
                                ->orderBy('name')
                                ->paginate($request->per_page ?? 50);

            return response()->json([
                'success' => true,
                'data' => $healthPlans
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting available health plans: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar planos de saúde'
            ], 500);
        }
    }

    /**
     * Get statistics about WhatsApp numbers usage
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_numbers' => WhatsAppNumber::count(),
                'active_numbers' => WhatsAppNumber::where('is_active', true)->count(),
                'numbers_by_type' => WhatsAppNumber::selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type'),
                'health_plans_with_numbers' => HealthPlan::whereHas('whatsappNumbers')->count(),
                'health_plans_without_numbers' => HealthPlan::whereDoesntHave('whatsappNumbers')->count(),
                'total_health_plan_associations' => DB::table('health_plan_whatsapp_numbers')->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting WhatsApp numbers statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao buscar estatísticas'
            ], 500);
        }
    }
}
