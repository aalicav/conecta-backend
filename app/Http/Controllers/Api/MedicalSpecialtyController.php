<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalSpecialty;
use App\Models\SpecialtyPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

class MedicalSpecialtyController extends Controller
{
    /**
     * Lista todas as especialidades médicas
     */
    public function index(Request $request)
    {
        $query = MedicalSpecialty::query();

        if ($request->negotiable) {
            $query->negotiable();
        }

        if ($request->active) {
            $query->where('active', true);
        }

        if ($request->with_prices) {
            $query->with(['activePrices' => function($q) use ($request) {
                if ($request->entity_type && $request->entity_id) {
                    $q->where('entity_type', $request->entity_type)
                      ->where('entity_id', $request->entity_id);
                }
            }]);
        }

        return response()->json($query->paginate($request->per_page ?? 15));
    }

    /**
     * Cria uma nova especialidade médica
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:medical_specialties',
            'tuss_code' => 'required|string|max:20|unique:medical_specialties',
            'tuss_description' => 'required|string',
            'default_price' => 'required|numeric|min:0',
            'negotiable' => 'boolean',
            'active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $specialty = MedicalSpecialty::create($request->all());

            DB::commit();

            return response()->json($specialty, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao criar especialidade', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Atualiza uma especialidade médica
     */
    public function update(Request $request, MedicalSpecialty $specialty)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255|unique:medical_specialties,name,' . $specialty->id,
            'tuss_code' => 'string|max:20|unique:medical_specialties,tuss_code,' . $specialty->id,
            'tuss_description' => 'string',
            'default_price' => 'numeric|min:0',
            'negotiable' => 'boolean',
            'active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $specialty->update($request->all());

            DB::commit();

            return response()->json($specialty);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao atualizar especialidade', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Define o preço da especialidade para uma entidade específica
     */
    public function setPrice(Request $request, MedicalSpecialty $specialty)
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string|in:professional,clinic,health_plan',
            'entity_id' => 'required|integer',
            'price' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'negotiation_id' => 'nullable|exists:negotiations,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            // Desativa preços anteriores que se sobrepõem ao período
            SpecialtyPrice::where([
                'medical_specialty_id' => $specialty->id,
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'active' => true
            ])
            ->where(function ($query) use ($request) {
                $query->whereBetween('start_date', [$request->start_date, $request->end_date ?? '9999-12-31'])
                    ->orWhereBetween('end_date', [$request->start_date, $request->end_date ?? '9999-12-31'])
                    ->orWhereNull('end_date');
            })
            ->update(['active' => false]);

            // Cria o novo preço
            $price = SpecialtyPrice::create([
                'medical_specialty_id' => $specialty->id,
                'entity_type' => $request->entity_type,
                'entity_id' => $request->entity_id,
                'price' => $request->price,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'negotiation_id' => $request->negotiation_id,
                'active' => true,
                'created_by' => Auth::id()
            ]);

            DB::commit();

            return response()->json($price, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao definir preço', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtém o preço atual da especialidade para uma entidade específica
     */
    public function getPrice(Request $request, MedicalSpecialty $specialty)
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string|in:professional,clinic,health_plan',
            'entity_id' => 'required|integer'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $price = $specialty->getPriceForEntity($request->entity_type, $request->entity_id);

        return response()->json([
            'price' => $price,
            'is_default' => $price === $specialty->default_price
        ]);
    }

    /**
     * Obtém as negociações ativas para uma especialidade
     */
    public function getActiveNegotiations(MedicalSpecialty $specialty)
    {
        if (!$specialty->negotiable) {
            return response()->json([
                'message' => 'Esta especialidade não está disponível para negociação'
            ], 422);
        }

        $negotiations = $specialty->activeNegotiations()
            ->with(['negotiation.entity'])
            ->get();

        return response()->json($negotiations);
    }

    /**
     * Aprova um preço proposto para uma especialidade
     */
    public function approvePrice(Request $request, SpecialtyPrice $price)
    {
        if ($price->status !== 'pending') {
            return response()->json([
                'message' => 'Este preço já foi processado'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'approved_value' => 'required|numeric|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            DB::beginTransaction();

            $price->update([
                'status' => 'approved',
                'price' => $request->approved_value,
                'approved_at' => now(),
                'approved_by' => Auth::id()
            ]);

            DB::commit();

            return response()->json($price->fresh(['specialty', 'negotiation', 'approver']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Erro ao aprovar preço', 'error' => $e->getMessage()], 500);
        }
    }
} 