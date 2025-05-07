<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PatientResource;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PatientController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /**
     * Display a listing of patients.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Patient::query();

            // If user has health plan role, only show patients from their health plan
            if (Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin')) {
                $healthPlanId = Auth::user()->entity_id;
                $query->where('health_plan_id', $healthPlanId);
            }

            // Apply filters if provided
            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('cpf', 'like', "%{$search}%");
                });
            }

            // Sort options
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);

            // Pagination
            $perPage = $request->input('per_page', 15);
            $patients = $query->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => PatientResource::collection($patients),
                'meta' => [
                    'total' => $patients->total(),
                    'per_page' => $patients->perPage(),
                    'current_page' => $patients->currentPage(),
                    'last_page' => $patients->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch patients: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch patients',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created patient in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'cpf' => 'required|string|max:14|unique:patients,cpf',
                'birth_date' => 'required|date',
                'gender' => 'required|string|in:male,female,other',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:2',
                'postal_code' => 'nullable|string|max:10',
                'health_plan_id' => 'nullable|exists:health_plans,id',
                'health_card_number' => 'nullable|string|max:50',
                'phones' => 'nullable|array',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
            ]);

            // If user has health plan role, ensure the patient is created with their health plan ID
            if (Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin')) {
                $healthPlanId = Auth::user()->entity_id;
                $validated['health_plan_id'] = $healthPlanId;
            }

            DB::beginTransaction();
            
            $patient = Patient::create($validated);
            
            // Criar telefones para o paciente
            if (isset($validated['phones']) && !empty($validated['phones'])) {
                foreach ($validated['phones'] as $phoneData) {
                    $patient->phones()->create($phoneData);
                }
            }
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Patient created successfully',
                'data' => new PatientResource($patient->load('phones', 'healthPlan'))
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create patient: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create patient',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified patient.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $patient = Patient::with(['phones', 'healthPlan'])->findOrFail($id);

            // If user has health plan role, verify that patient belongs to their health plan
            if ((Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin'))) {
                $healthPlanId = Auth::user()->entity_id;
                if ($patient->health_plan_id != $healthPlanId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized to view this patient'
                    ], 403);
                }
            }

            return response()->json([
                'status' => 'success',
                'data' => new PatientResource($patient)
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch patient: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'patient_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch patient',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Update the specified patient in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $patient = Patient::findOrFail($id);

            // If user has health plan role, verify that patient belongs to their health plan
            if (Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin')) {
                $healthPlanId = Auth::user()->entity_id;
                if ($patient->health_plan_id != $healthPlanId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized to update this patient'
                    ], 403);
                }
                
                // Ensure health_plan_id isn't changed to a different plan
                if ($request->has('health_plan_id') && $request->health_plan_id != $healthPlanId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Cannot change patient to a different health plan'
                    ], 403);
                }
            }

            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'cpf' => 'sometimes|required|string|max:14|unique:patients,cpf,' . $id,
                'birth_date' => 'sometimes|required|date',
                'gender' => 'sometimes|required|string|in:male,female,other',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:2',
                'postal_code' => 'nullable|string|max:10',
                'health_plan_id' => 'nullable|exists:health_plans,id',
                'health_card_number' => 'nullable|string|max:50',
                'phones' => 'nullable|array',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
            ]);

            DB::beginTransaction();
            
            $patient->update($validated);
            
            // Atualizar telefones
            if (isset($validated['phones'])) {
                // Remove telefones existentes
                $patient->phones()->delete();
                
                // Adiciona novos telefones
                foreach ($validated['phones'] as $phoneData) {
                    $patient->phones()->create($phoneData);
                }
            }
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Patient updated successfully',
                'data' => new PatientResource($patient->fresh(['phones', 'healthPlan']))
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update patient: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'patient_id' => $id,
                'request_data' => $request->all()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update patient',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Remove the specified patient from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $patient = Patient::findOrFail($id);

            // If user has health plan role, verify that patient belongs to their health plan
            if (Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin')) {
                $healthPlanId = Auth::user()->entity_id;
                if ($patient->health_plan_id != $healthPlanId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Unauthorized to delete this patient'
                    ], 403);
                }
            }

            DB::beginTransaction();
            
            $patient->delete();
            
            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Patient deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete patient: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'patient_id' => $id
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete patient',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
} 