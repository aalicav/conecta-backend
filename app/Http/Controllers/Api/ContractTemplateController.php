<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\App;

class ContractTemplateController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api');
        // $this->middleware('permission:manage contracts')->except(['index', 'show']);
    }

    /**
     * Display a listing of contract templates.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = ContractTemplate::query();
            
            // Filter by entity type if specified
            if ($request->has('entity_type')) {
                $query->where('entity_type', $request->input('entity_type'));
            }
            
            // Filter by active status
            if ($request->has('active')) {
                $query->where('is_active', $request->boolean('active'));
            }
            
            // Sort options
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $query->orderBy($sortField, $sortDirection);
            
            $perPage = $request->input('per_page', 15);
            $templates = $query->paginate($perPage);
            
            return response()->json([
                'status' => 'success',
                'data' => $templates,
                'meta' => [
                    'total' => $templates->total(),
                    'per_page' => $templates->perPage(),
                    'current_page' => $templates->currentPage(),
                    'last_page' => $templates->lastPage(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch contract templates: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch contract templates',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created contract template.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'entity_type' => 'required|string|in:health_plan,clinic,professional',
                'content' => 'required|string',
                'placeholders' => 'nullable|array',
                'is_active' => 'boolean',
            ]);
            
            $validated['created_by'] = Auth::id();
            
            DB::beginTransaction();
            
            $template = ContractTemplate::create($validated);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract template created successfully',
                'data' => $template
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create contract template: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create contract template',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified contract template.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        try {
            $template = ContractTemplate::findOrFail($id);
            
            return response()->json([
                'status' => 'success',
                'data' => $template
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch contract template: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'template_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch contract template',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Update the specified contract template.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $template = ContractTemplate::findOrFail($id);
            
            $validated = $request->validate([
                'name' => 'sometimes|required|string|max:255',
                'entity_type' => 'sometimes|required|string|in:health_plan,clinic,professional',
                'content' => 'sometimes|required|string',
                'placeholders' => 'nullable|array',
                'is_active' => 'boolean',
            ]);
            
            $validated['updated_by'] = Auth::id();
            
            DB::beginTransaction();
            
            $template->update($validated);
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract template updated successfully',
                'data' => $template
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update contract template: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'template_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update contract template',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Remove the specified contract template.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        try {
            $template = ContractTemplate::findOrFail($id);
            
            // Check if template is used by any contracts
            $contractsCount = $template->contracts()->count();
            if ($contractsCount > 0) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Cannot delete template that is used by existing contracts'
                ], 422);
            }
            
            DB::beginTransaction();
            
            $template->delete();
            
            DB::commit();
            
            return response()->json([
                'status' => 'success',
                'message' => 'Contract template deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete contract template: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'template_id' => $id
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete contract template',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }

    /**
     * Preview a contract template with provided data.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function preview(Request $request, $id)
    {
        try {
            $template = ContractTemplate::findOrFail($id);
            
            $validated = $request->validate([
                'data' => 'required|array',
                'negotiation_id' => 'nullable|exists:negotiations,id'
            ]);
            
            $data = $validated['data'];
            $negotiation = null;
            
            if (isset($validated['negotiation_id'])) {
                $negotiation = \App\Models\Negotiation::with('items.tuss')->find($validated['negotiation_id']);
            }
            
            $processedContent = $template->processContent($data, $negotiation);
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'content' => $processedContent
                ]
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to preview contract template: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'template_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to preview contract template',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Get available placeholders by entity type.
     *
     * @param string $entityType
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPlaceholders($entityType)
    {
        try {
            if (!in_array($entityType, ['health_plan', 'clinic', 'professional'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid entity type'
                ], 422);
            }
            
            $commonPlaceholders = [
                'date' => 'Current date',
                'contract_number' => 'Generated contract number',
                'start_date' => 'Contract start date',
                'end_date' => 'Contract end date (if applicable)',
            ];
            
            $entityPlaceholders = [];
            
            switch ($entityType) {
                case 'health_plan':
                    $entityPlaceholders = [
                        'health_plan.name' => 'Health plan name',
                        'health_plan.ans_code' => 'ANS',
                        'health_plan.municipal_registration' => 'Municipal registration number',
                        'health_plan.cnpj' => 'CNPJ',
                        'health_plan.email' => 'Email address',
                        'health_plan.phone' => 'Phone number',
                        'health_plan.address' => 'Address',
                    ];
                    break;
                    
                case 'clinic':
                    $entityPlaceholders = [
                        'clinic.name' => 'Clinic name',
                        'clinic.registration_number' => 'Registration number',
                        'clinic.email' => 'Email address',
                        'clinic.phone' => 'Phone number',
                        'clinic.address' => 'Address',
                        'clinic.director' => 'Director name',
                    ];
                    break;
                    
                case 'professional':
                    $entityPlaceholders = [
                        'professional.name' => 'Professional name',
                        'professional.email' => 'Email address',
                        'professional.phone' => 'Phone number',
                        'professional.specialization' => 'Specialization',
                        'professional.license_number' => 'License number',
                    ];
                    break;
            }
            
            return response()->json([
                'status' => 'success',
                'data' => [
                    'common' => $commonPlaceholders,
                    'entity' => $entityPlaceholders
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get placeholders: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'entity_type' => $entityType
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get placeholders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export a contract template to PDF with provided data.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function exportPdf(Request $request, $id)
    {
        try {
            $template = ContractTemplate::findOrFail($id);
            
            $validated = $request->validate([
                'data' => 'required|array',
                'negotiation_id' => 'nullable|exists:negotiations,id'
            ]);
            
            $data = $validated['data'];
            $negotiation = null;
            
            if (isset($validated['negotiation_id'])) {
                $negotiation = \App\Models\Negotiation::with('items.tuss')->find($validated['negotiation_id']);
            }
            
            $processedContent = $template->processContent($data, $negotiation);
            
            // Generate PDF using a library like DomPDF
            $pdf = \Barryvdh\DomPdf\Facade\Pdf::loadHTML($processedContent);
            
            $filename = Str::slug($template->name) . '-' . date('Y-m-d') . '.pdf';
            
            return $pdf->download($filename);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to export contract template to PDF: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'template_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export contract template to PDF',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Export a contract template to DOCX with provided data.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function exportDocx(Request $request, $id)
    {
        try {
            $template = ContractTemplate::findOrFail($id);
            
            $validated = $request->validate([
                'data' => 'required|array',
                'negotiation_id' => 'nullable|exists:negotiations,id'
            ]);
            
            $data = $validated['data'];
            $negotiation = null;
            
            if (isset($validated['negotiation_id'])) {
                $negotiation = \App\Models\Negotiation::with('items.tuss')->find($validated['negotiation_id']);
            }
            
            $processedContent = $template->processContent($data, $negotiation);
            
            // Create a temporary HTML file
            $tempHtmlFile = storage_path('app/temp_' . uniqid() . '.html');
            file_put_contents($tempHtmlFile, $processedContent);
            
            // Create output file path
            $outputDocxFile = storage_path('app/contract_' . uniqid() . '.docx');
            
            // Use Pandoc to convert HTML to DOCX (you need to install Pandoc on your server)
            $command = "pandoc " . escapeshellarg($tempHtmlFile) . " -f html -t docx -o " . escapeshellarg($outputDocxFile);
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0) {
                throw new \Exception('Failed to convert HTML to DOCX: ' . implode("\n", $output));
            }
            
            // Return the DOCX file for download
            $filename = Str::slug($template->name) . '-' . date('Y-m-d') . '.docx';
            
            $response = response()->download($outputDocxFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);
            
            // Register a callback to delete the temporary files after sending the response
            $response->deleteFileAfterSend(true);
            
            // Also delete the temporary HTML file
            register_shutdown_function(function() use ($tempHtmlFile) {
                if (file_exists($tempHtmlFile)) {
                    unlink($tempHtmlFile);
                }
            });
            
            return $response;
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Failed to export contract template to DOCX: ' . $e->getMessage(), [
                'exception' => $e,
                'user_id' => Auth::id(),
                'template_id' => $id,
                'request_data' => $request->all()
            ]);
            
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to export contract template to DOCX',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
} 