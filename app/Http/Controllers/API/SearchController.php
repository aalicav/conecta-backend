<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\HealthPlan;
use App\Models\Clinic;
use App\Models\Professional;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SearchController extends Controller
{
    /**
     * Search for health plans, clinics, or professionals
     *
     * @param Request $request
     * @param string $type 'health_plan', 'clinic', or 'professional'
     * @return \Illuminate\Http\JsonResponse
     */
    public function search(Request $request, $type)
    {
        $query = $request->query('query');
        
        if (empty($query) || strlen($query) < 2) {
            return response()->json([
                'status' => 'error',
                'message' => 'Search query must be at least 2 characters long',
                'data' => []
            ]);
        }
        
        try {
            $results = [];
            
            switch ($type) {
                case 'health_plan':
                    $results = HealthPlan::where('name', 'like', "%{$query}%")
                        ->orWhere('ans_code', 'like', "%{$query}%")
                        ->limit(10)
                        ->get(['id', 'name', 'ans_code', 'email'])
                        ->map(function ($plan) {
                            return [
                                'id' => $plan->id,
                                'name' => $plan->name,
                                'email' => $plan->email,
                                'ans_code' => $plan->ans_code
                            ];
                        });
                    break;
                    
                case 'clinic':
                    $results = Clinic::where('name', 'like', "%{$query}%")
                        ->orWhere('cnpj', 'like', "%{$query}%")
                        ->limit(10)
                        ->get(['id', 'name', 'email', 'cnpj'])
                        ->map(function ($clinic) {
                            return [
                                'id' => $clinic->id,
                                'name' => $clinic->name,
                                'email' => $clinic->email,
                                'cnpj' => $clinic->cnpj
                            ];
                        });
                    break;
                    
                case 'professional':
                    $results = Professional::where('name', 'like', "%{$query}%")
                        ->orWhere('crm', 'like', "%{$query}%")
                        ->orWhere('cpf', 'like', "%{$query}%")
                        ->limit(10)
                        ->get(['id', 'name', 'email', 'crm', 'cpf'])
                        ->map(function ($professional) {
                            return [
                                'id' => $professional->id,
                                'name' => $professional->name,
                                'email' => $professional->email,
                                'crm' => $professional->crm,
                                'cpf' => $professional->cpf
                            ];
                        });
                    break;
                    
                default:
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid entity type',
                        'data' => []
                    ]);
            }
            
            return response()->json([
                'status' => 'success',
                'data' => $results
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while searching: ' . $e->getMessage(),
                'data' => []
            ]);
        }
    }
    
    /**
     * Get template data for an entity
     *
     * @param Request $request
     * @param string $type 'health_plan', 'clinic', or 'professional'
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTemplateData($type, $id)
    {
        try {
            $templateData = [];
            
            switch ($type) {
                case 'health_plan':
                    $entity = HealthPlan::findOrFail($id);
                    $templateData = [
                        'entity_name' => $entity->name,
                        'entity_email' => $entity->email,
                        'entity_phone' => $entity->phone,
                        'entity_address' => $entity->address,
                        'entity_city' => $entity->city,
                        'entity_state' => $entity->state,
                        'entity_zip' => $entity->zip,
                        'entity_ans_code' => $entity->ans_code,
                        'entity_cnpj' => $entity->cnpj,
                    ];
                    break;
                    
                case 'clinic':
                    $entity = Clinic::findOrFail($id);
                    $templateData = [
                        'entity_name' => $entity->name,
                        'entity_email' => $entity->email,
                        'entity_phone' => $entity->phone,
                        'entity_address' => $entity->address,
                        'entity_city' => $entity->city,
                        'entity_state' => $entity->state,
                        'entity_zip' => $entity->zip,
                        'entity_cnpj' => $entity->cnpj,
                        'entity_technical_director' => $entity->technical_director,
                    ];
                    break;
                    
                case 'professional':
                    $entity = Professional::findOrFail($id);
                    $templateData = [
                        'entity_name' => $entity->name,
                        'entity_email' => $entity->email,
                        'entity_phone' => $entity->phone,
                        'entity_address' => $entity->address,
                        'entity_city' => $entity->city,
                        'entity_state' => $entity->state,
                        'entity_zip' => $entity->zip,
                        'entity_crm' => $entity->crm,
                        'entity_specialty' => $entity->specialty,
                        'entity_cpf' => $entity->cpf,
                    ];
                    break;
                    
                default:
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid entity type',
                        'data' => ['template_data' => []]
                    ]);
            }
            
            // Add any additional data needed for templates
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'template_data' => $templateData
                ]
            ]);
            
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Entity not found',
                'data' => ['template_data' => []]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred: ' . $e->getMessage(),
                'data' => ['template_data' => []]
            ]);
        }
    }
} 