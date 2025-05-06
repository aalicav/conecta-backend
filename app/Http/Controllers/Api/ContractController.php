<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\PDF;
use App\Services\AutentiqueService;

class ContractController extends Controller
{
    protected $autentiqueService;
    
    public function __construct(AutentiqueService $autentiqueService)
    {
        $this->middleware('auth:api');
        $this->autentiqueService = $autentiqueService;
    }

    /**
     * Generate a contract from a template.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request)
    {
        try {
            $validated = $request->validate([
                'template_id' => 'required|exists:contract_templates,id',
                'contractable_id' => 'required|integer',
                'contractable_type' => 'required|string|in:App\\Models\\HealthPlan,App\\Models\\Clinic,App\\Models\\Professional',
                'start_date' => 'required|date',
                'end_date' => 'nullable|date|after:start_date',
                'template_data' => 'required|array'
            ]);
            
            DB::beginTransaction();
            
            // Get the template
            $template = ContractTemplate::findOrFail($validated['template_id']);
            
            // Process the template with data
            $content = $template->processContent($validated['template_data']);
            
            // Generate PDF
            $pdf = PDF::loadHTML($content);
            
            // Determine contractable type for directory structure
            $typeParts = explode('\\', $validated['contractable_type']);
            $entityType = strtolower(end($typeParts));
            
            // Save PDF to storage
            $filePath = 'contracts/' . $entityType . '/' . $validated['contractable_id'] . '/' . uniqid() . '.pdf';
            Storage::put($filePath, $pdf->output());
            
            // Create contract record
            $contract = Contract::create([
                'contractable_id' => $validated['contractable_id'],
                'contractable_type' => $validated['contractable_type'],
                'type' => $entityType,
                'template_id' => $validated['template_id'],
                'template_data' => $validated['template_data'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'pending',
                'file_path' => $filePath,
                'created_by' => Auth::id()
            ]);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract generated successfully',
                'data' => $contract
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to generate contract: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate contract',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a paginated list of contracts with optional filtering.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = Contract::with('contractable');

            if ($request->filled('type')) {
                $query->where('type', $request->input('type'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            if ($request->has('signed')) {
                $query->where('is_signed', $request->boolean('signed'));
            }
            if ($request->filled('search')) {
                $query->where('contract_number', 'like', '%' . $request->input('search') . '%');
            }

            $perPage = $request->input('per_page', 15);
            $contracts = $query->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'status' => 'success',
                'data' => $contracts
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list contracts: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to list contracts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Regenerate an existing contract with updated data.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function regenerate(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            // Ensure contract is not signed
            if ($contract->is_signed) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot regenerate a signed contract'
                ], 422);
            }
            
            $validated = $request->validate([
                'template_data' => 'sometimes|required|array',
                'start_date' => 'sometimes|date',
                'end_date' => 'nullable|date|after:start_date',
            ]);
            
            DB::beginTransaction();
            
            // Update contract data
            $contract->fill($validated);
            
            // Get template
            $template = ContractTemplate::findOrFail($contract->template_id);
            
            // Process template with updated data
            $content = $template->processContent($contract->template_data);
            
            // Generate new PDF
            $pdf = PDF::loadHTML($content);
            
            // Remove old file if it exists
            if (Storage::exists($contract->file_path)) {
                Storage::delete($contract->file_path);
            }
            
            // Save new PDF
            Storage::put($contract->file_path, $pdf->output());
            
            $contract->save();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract regenerated successfully',
                'data' => $contract
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to regenerate contract: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to regenerate contract',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Sign a contract digitally.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sign(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            if ($contract->is_signed) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contract has already been signed'
                ], 422);
            }
            
            $validated = $request->validate([
                'signature_token' => 'nullable|string'
            ]);
            
            DB::beginTransaction();
            
            // Sign the contract
            $contract->sign($request->ip(), $validated['signature_token'] ?? null);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract signed successfully',
                'data' => $contract
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to sign contract: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to sign contract',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Download a contract file.
     *
     * @param int $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function download($id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            if (!Storage::exists($contract->file_path)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contract file not found'
                ], 404);
            }
            
            return Storage::download(
                $contract->file_path,
                'Contract_' . $contract->contract_number . '.pdf',
                ['Content-Type' => 'application/pdf']
            );
        } catch (\Exception $e) {
            Log::error('Failed to download contract: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to download contract',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Preview a contract with provided data without generating a file.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview(Request $request)
    {
        try {
            $validated = $request->validate([
                'template_id' => 'required|exists:contract_templates,id',
                'template_data' => 'required|array',
                'negotiation_id' => 'nullable|exists:negotiations,id'
            ]);
            
            // Get the template
            $template = ContractTemplate::findOrFail($validated['template_id']);
            
            $negotiation = null;
            if (isset($validated['negotiation_id'])) {
                $negotiation = \App\Models\Negotiation::with('items.tuss')->find($validated['negotiation_id']);
            }
            
            // Process the template with data
            $content = $template->processContent($validated['template_data'], $negotiation);
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'content' => $content
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to preview contract: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to preview contract',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a contract for digital signature via Autentique.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendForSignature(Request $request, $id)
    {
        try {
            $contract = Contract::findOrFail($id);
            
            // Check if contract is already signed
            if ($contract->is_signed) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Contract has already been signed'
                ], 422);
            }
            
            // Validate signers data
            $validated = $request->validate([
                'signers' => 'required|array|min:1',
                'signers.*.email' => 'required|email',
                'signers.*.name' => 'required|string',
                'signers.*.cpf' => 'nullable|string',
                'signers.*.birthday' => 'nullable|date_format:Y-m-d',
            ]);
            
            $result = $this->autentiqueService->sendContractForSignature($contract, $validated['signers']);
            
            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['message']
                ], 422);
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract sent for signature successfully',
                'data' => $result['data']
            ]);
            
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to send contract for signature: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'contract_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send contract for signature',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Resend signature requests to specific signers.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendSignatures(Request $request)
    {
        try {
            $validated = $request->validate([
                'signature_ids' => 'required|array|min:1',
                'signature_ids.*' => 'required|string',
            ]);
            
            $result = $this->autentiqueService->resendSignatures($validated['signature_ids']);
            
            if (!$result['success']) {
                return response()->json([
                    'status' => 'error',
                    'message' => $result['message']
                ], 422);
            }
            
            return response()->json([
                'status' => 'success',
                'message' => 'Signature requests resent successfully',
                'data' => $result['data']
            ]);
            
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to resend signature requests: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to resend signature requests',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Handle webhook notifications from Autentique.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleAutentiqueWebhook(Request $request)
    {
        try {
            // No auth check for webhook endpoints
            $data = $request->all();
            
            Log::info('Received Autentique webhook', [
                'data' => $data
            ]);
            
            $this->autentiqueService->processWebhook($data);
            
            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to process Autentique webhook: ' . $e->getMessage(), [
                'exception' => $e,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process webhook',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get entity data for template auto-fill.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getEntityData(Request $request)
    {
        try {
            $validated = $request->validate([
                'entity_type' => 'required|string|in:health_plan,clinic,professional',
                'entity_id' => 'required|integer'
            ]);
            
            $entityType = $validated['entity_type'];
            $entityId = $validated['entity_id'];
            
            // Map entity type to model class
            $modelMap = [
                'health_plan' => 'App\\Models\\HealthPlan',
                'clinic' => 'App\\Models\\Clinic',
                'professional' => 'App\\Models\\Professional'
            ];
            
            $modelClass = $modelMap[$entityType];
            
            // Find the entity
            $entity = $modelClass::findOrFail($entityId);
            
            // Prepare template data based on entity type
            $templateData = [];
            
            switch ($entityType) {
                case 'health_plan':
                    $templateData = [
                        'health_plan.name' => $entity->name,
                        'health_plan.ans_code' => $entity->ans_code ?? '',
                        'health_plan.municipal_registration' => $entity->municipal_registration ?? '',
                        'health_plan.cnpj' => $entity->cnpj ?? '',
                        'health_plan.email' => $entity->email ?? '',
                        'health_plan.phone' => $entity->phone ?? '',
                        'health_plan.address' => $entity->address ?? '',
                    ];
                    break;
                
                case 'clinic':
                    $templateData = [
                        'clinic.name' => $entity->name,
                        'clinic.registration_number' => $entity->registration_number ?? '',
                        'clinic.email' => $entity->email ?? '',
                        'clinic.phone' => $entity->phone ?? '',
                        'clinic.address' => $entity->address ?? '',
                        'clinic.director' => $entity->director ?? '',
                    ];
                    break;
                
                case 'professional':
                    $templateData = [
                        'professional.name' => $entity->name,
                        'professional.email' => $entity->email ?? '',
                        'professional.phone' => $entity->phone ?? '',
                        'professional.specialization' => $entity->specialization ?? '',
                        'professional.license_number' => $entity->license_number ?? '',
                    ];
                    break;
            }
            
            // Add common template data
            $templateData['date'] = date('d/m/Y');
            $templateData['contract_number'] = 'DRAFT-' . uniqid();
            $templateData['start_date'] = date('d/m/Y');
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'entity' => $entity,
                    'template_data' => $templateData
                ]
            ]);
            
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to get entity data: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get entity data',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Search entities by name.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchEntities(Request $request)
    {
        try {
            $validated = $request->validate([
                'entity_type' => 'required|string|in:health_plan,clinic,professional',
                'query' => 'required|string|min:2'
            ]);
            
            $entityType = $validated['entity_type'];
            $searchQuery = $validated['query'];
            
            // Map entity type to model class
            $modelMap = [
                'health_plan' => 'App\\Models\\HealthPlan',
                'clinic' => 'App\\Models\\Clinic',
                'professional' => 'App\\Models\\Professional'
            ];
            
            $modelClass = $modelMap[$entityType];
            
            // Search entities by name
            $entities = $modelClass::where('name', 'like', "%{$searchQuery}%")
                ->where('is_active', true)
                ->limit(10)
                ->get(['id', 'name', 'email']);
            
            return response()->json([
                'status' => 'success',
                'data' => $entities
            ]);
            
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to search entities: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to search entities',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 