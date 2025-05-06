<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\HealthPlanResource;
use App\Models\HealthPlan;
use App\Models\Document;
use App\Http\Controllers\Api\NegotiationController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Str;

class HealthPlanController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(middleware: 'auth:sanctum');
        $this->middleware('permission:view health plans')->only(['index', 'show']);
        $this->middleware('permission:create health plans')->only(['store']);
        $this->middleware('permission:edit health plans')->only(['update']);
        $this->middleware('permission:delete health plans')->only(['destroy']);
        $this->middleware('permission:approve health plans')->only(['approve']);
    }

    /**
     * Display a listing of health plans.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = HealthPlan::with(['phones', 'approver', 'contract']);
        
        // Search by name or CNPJ if search parameter is provided
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                  ->orWhere('cnpj', 'like', "%{$searchTerm}%");
            });
        } else {
            // Fallback to individual filters if search parameter is not used
            
            // Filter by name if provided
            if ($request->has('name')) {
                $query->where('name', 'like', "%{$request->name}%");
            }
            
            // Filter by CNPJ if provided
            if ($request->has('cnpj')) {
                $query->where('cnpj', 'like', "%{$request->cnpj}%");
            }

            // Filter by municipal registration if provided
            if ($request->has('municipal_registration')) {
                $query->where('municipal_registration', 'like', "%{$request->municipal_registration}%");
            }
        }
        
        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by ANS code if provided
        if ($request->has('ans_code')) {
            $query->where('ans_code', 'like', "%{$request->ans_code}%");
        }
        
        // Filter by contract status if provided
        if ($request->has('has_signed_contract')) {
            $query->where('has_signed_contract', $request->has_signed_contract == 'true' ? true : false);
        }
        
        // Filter by parent_id or parent-only status
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->parent_id);
        } elseif ($request->has('is_parent')) {
            if ($request->is_parent === 'true' || $request->is_parent === '1') {
                $query->whereNull('parent_id');
            } elseif ($request->is_parent === 'false' || $request->is_parent === '0') {
                $query->whereNotNull('parent_id');
            }
        }
        
        // Apply sorting
        $sortField = $request->sort_by ?? 'created_at';
        $sortDirection = $request->sort_direction ?? 'desc';
        $query->orderBy($sortField, $sortDirection);
        
        $healthPlans = $query->paginate($request->per_page ?? 15);
        
        return HealthPlanResource::collection($healthPlans);
    }

    /**
     * Store a newly created health plan.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'cnpj' => 'required|string|max:18|unique:health_plans,cnpj',
                'municipal_registration' => 'nullable|string|max:20',
                'email' => 'required|email|unique:users,email',
                'ans_code' => 'nullable|string|max:20',
                'description' => 'nullable|string',
                'legal_representative_name' => 'required|string|max:255',
                'legal_representative_cpf' => 'required|string|max:14',
                'legal_representative_position' => 'required|string|max:255',
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:2',
                'postal_code' => 'required|string|max:10',
                'logo' => 'nullable|image|max:2048',
                'phones' => 'sometimes|array',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
                // Document validation
                'documents' => 'sometimes|array',
                'documents.*.file' => 'required|file|max:10240', // 10MB max
                'documents.*.type' => 'required|string|in:contract,ans_certificate,authorization,financial,legal,identification,agreement,technical,other',
                'documents.*.description' => 'required|string|max:255',
                'documents.*.reference_date' => 'nullable|date',
                'documents.*.expiration_date' => 'nullable|date|after:reference_date',
                // Procedures validation for negotiation
                'procedures' => 'sometimes|array',
                'procedures.*.tuss_id' => 'required|exists:tuss_procedures,id',
                'procedures.*.proposed_value' => 'required|numeric|min:0',
                'procedures.*.status' => 'sometimes|nullable|string',
                'procedures.*.notes' => 'nullable|string',
                'auto_approve' => 'sometimes|nullable|string|in:true,false,1,0',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle logo upload if provided
            $logoPath = null;
            if ($request->hasFile('logo')) {
                $logoPath = $request->file('logo')->store('health_plans/logos', 'public');
            }

            // Create health plan
            $healthPlan = new HealthPlan($request->except('logo', 'phones', 'documents', 'procedures', 'auto_approve'));
            $healthPlan->logo = $logoPath;
            $healthPlan->user_id = Auth::id();

            $user = User::factory()->create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make(Str::random(10)),
                'profile_photo' => $logoPath,
                'entity_id' => $healthPlan->id,
                'entity_type' => 'App\\Models\\HealthPlan',
            ]);
    
            $user->assignRole('plan_admin');
            
            // Set auto approval status if requested
            // Check if auto_approve is true (can be "true" string, "1", or true boolean)
            $autoApprove = $request->auto_approve;
            if ($autoApprove === "true" || $autoApprove === "1" || $autoApprove === true) {
                // Check if user has permission to approve health plans
                if (!Auth::user()->can('approve health plans')) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'You do not have permission to auto-approve health plans'
                    ], 403);
                }
                
                $healthPlan->status = 'approved';
                $healthPlan->approved_at = now();
                $healthPlan->approved_by = Auth::id();
                $healthPlan->has_signed_contract = true;
            }
            
            $healthPlan->save();

            // Add phones if provided
            if ($request->has('phones') && is_array($request->phones)) {
                foreach ($request->phones as $phoneData) {
                    $healthPlan->phones()->create([
                        'number' => $phoneData['number'],
                        'type' => $phoneData['type'],
                    ]);
                }
            }

            // Process documents if provided
            $uploadedDocuments = [];
            if ($request->hasFile('documents')) {
                foreach ($request->file('documents') as $index => $documentFile) {
                    $fileData = $request->input('documents')[$index];
                    
                    // Get file extension and check allowed types
                    $extension = $documentFile->getClientOriginalExtension();
                    $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'md', 'txt'];
                    if (!in_array(strtolower($extension), $allowedTypes)) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => 'Invalid file type. Allowed types: ' . implode(', ', $allowedTypes)
                        ], 422);
                    }
                    
                    // Store file
                    $filePath = $documentFile->store('health_plans/documents/' . $healthPlan->id, 'public');
                    
                    // Create document record
                    $document = $healthPlan->documents()->create([
                        'type' => $fileData['type'],
                        'description' => $fileData['description'],
                        'file_path' => $filePath,
                        'file_name' => $documentFile->getClientOriginalName(),
                        'file_type' => $documentFile->getClientMimeType(),
                        'file_size' => $documentFile->getSize(),
                        'reference_date' => $fileData['reference_date'] ?? null,
                        'expiration_date' => $fileData['expiration_date'] ?? null,
                        'uploaded_by' => Auth::id(),
                        'user_id' => $healthPlan->user_id,
                    ]);

                    $uploadedDocuments[] = $document;
                }
            }

            // Create negotiation with procedures if provided
            if ($request->has('procedures') && is_array($request->procedures) && count($request->procedures) > 0) {
                $negotiationController = new NegotiationController(app(\App\Services\NotificationService::class));
                
                // Prepare data for negotiation
                $negotiationData = [
                    'entity_type' => 'App\\Models\\HealthPlan',
                    'entity_id' => $healthPlan->id,
                    'title' => 'Negociação inicial - ' . $healthPlan->name,
                    'description' => 'Negociação de preços inicial para o plano de saúde ' . $healthPlan->name,
                    'start_date' => now()->format('Y-m-d'),
                    'end_date' => now()->addMonths(3)->format('Y-m-d'),
                    'items' => []
                ];
                
                // Set auto approved status if requested
                if ($autoApprove === "true" || $autoApprove === "1" || $autoApprove === true) {
                    $negotiationData['status'] = 'approved';
                }
                
                // Add items to negotiation
                foreach ($request->procedures as $procedure) {
                    if (isset($procedure['tuss_id']) && isset($procedure['proposed_value'])) {
                        $item = [
                            'tuss_id' => $procedure['tuss_id'],
                            'proposed_value' => $procedure['proposed_value'],
                            'notes' => $procedure['notes'] ?? null
                        ];
                        
                        // If auto-approve, set the status and approved value
                        if ($autoApprove === "true" || $autoApprove === "1" || $autoApprove === true) {
                            $item['status'] = 'approved';
                            $item['approved_value'] = $procedure['proposed_value'];
                        }
                        
                        $negotiationData['items'][] = $item;
                    }
                }
                
                // Create the negotiation
                $negotiationRequest = new Request($negotiationData);
                $negotiationResult = $negotiationController->store($negotiationRequest);
                
                // Check if negotiation was created successfully
                if ($negotiationResult->getStatusCode() !== 201) {
                    // Log the error but don't fail the health plan creation
                    Log::warning('Failed to create initial negotiation for health plan: ' . $healthPlan->id);
                    Log::warning(json_encode($negotiationResult->getData()));
                }
            }

            DB::commit();

            // Load relationships
            $healthPlan->load(['phones', 'user', 'documents']);

            return response()->json([
                'success' => true,
                'message' => 'Health plan created successfully',
                'data' => new HealthPlanResource($healthPlan),
                'documents_count' => count($uploadedDocuments)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating health plan: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create health plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified health plan.
     *
     * @param HealthPlan $health_plan
     * @return HealthPlanResource|JsonResponse
     */
    public function show(HealthPlan $health_plan)
    {
        try {
            // Load all necessary relationships for editing
            $health_plan->load([
                'phones', 
                'documents', 
                'approver',
                'user',
                'contract', 
                'pricingContracts.procedure', // Explicitly load procedures relationship
                'parent',
                'children'
            ]);

            // Add logo URL for frontend display
            if ($health_plan->logo) {
                $health_plan->logo_url = Storage::disk('public')->url($health_plan->logo);
            }

            // Return resource with success wrapper for consistent API responses
            return response()->json([
                'success' => true,
                'message' => 'Health plan retrieved successfully',
                'data' => new HealthPlanResource($health_plan)
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving health plan: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve health plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified health plan.
     *
     * @param Request $request
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function update(Request $request, HealthPlan $health_plan): JsonResponse
    {
        try {
            Log::info('Updating health plan ID: ' . $health_plan->id);
            Log::debug('Update request data:', $request->all());
            
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'cnpj' => 'sometimes|string|max:18|unique:health_plans,cnpj,' . $health_plan->id,
                'municipal_registration' => 'nullable|string|max:20',
                'ans_code' => 'nullable|string|max:20',
                'description' => 'nullable|string',
                'legal_representative_name' => 'sometimes|string|max:255',
                'legal_representative_cpf' => 'sometimes|string|max:14',
                'legal_representative_position' => 'sometimes|string|max:255',
                'address' => 'sometimes|string|max:255',
                'city' => 'sometimes|string|max:100',
                'state' => 'sometimes|string|max:2',
                'postal_code' => 'sometimes|string|max:10',
                'logo' => 'nullable|image|max:2048',
                'phones' => 'sometimes|array',
                'phones.*.id' => 'sometimes|exists:phones,id',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
                'email' => 'sometimes|email|unique:users,email,' . ($health_plan->user ? $health_plan->user->id : '')
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle logo upload if provided
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($health_plan->logo) {
                    Storage::disk('public')->delete($health_plan->logo);
                }
                $logoPath = $request->file('logo')->store('health_plans/logos', 'public');
                $health_plan->logo = $logoPath;
                
                // Update user profile photo if associated user exists
                if ($health_plan->user) {
                    $health_plan->user->profile_photo = $logoPath;
                    $health_plan->user->save();
                }
            }

            // Update health plan - specify individual fields to prevent mass assignment issues
            $health_plan->name = $request->input('name', $health_plan->name);
            $health_plan->cnpj = $request->input('cnpj', $health_plan->cnpj);
            $health_plan->municipal_registration = $request->input('municipal_registration', $health_plan->municipal_registration);
            $health_plan->ans_code = $request->input('ans_code', $health_plan->ans_code);
            $health_plan->description = $request->input('description', $health_plan->description);
            $health_plan->legal_representative_name = $request->input('legal_representative_name', $health_plan->legal_representative_name);
            $health_plan->legal_representative_cpf = $request->input('legal_representative_cpf', $health_plan->legal_representative_cpf);
            $health_plan->legal_representative_position = $request->input('legal_representative_position', $health_plan->legal_representative_position);
            $health_plan->address = $request->input('address', $health_plan->address);
            $health_plan->city = $request->input('city', $health_plan->city);
            $health_plan->state = $request->input('state', $health_plan->state);
            $health_plan->postal_code = $request->input('postal_code', $health_plan->postal_code);
            
            $health_plan->save();

            // Update associated user email if provided
            if ($request->has('email') && $health_plan->user) {
                $health_plan->user->email = $request->input('email');
                $health_plan->user->save();
            }

            // Update phones if provided
            if ($request->has('phones') && is_array($request->phones)) {
                // Get existing phone IDs
                $existingPhoneIds = $health_plan->phones->pluck('id')->toArray();
                $newPhoneIds = [];

                foreach ($request->phones as $phoneData) {
                    if (isset($phoneData['id'])) {
                        // Update existing phone
                        $phone = $health_plan->phones()->find($phoneData['id']);
                        if ($phone) {
                            $phone->update([
                                'number' => $phoneData['number'],
                                'type' => $phoneData['type'],
                            ]);
                            $newPhoneIds[] = $phone->id;
                        }
                    } else {
                        // Create new phone
                        $phone = $health_plan->phones()->create([
                            'number' => $phoneData['number'],
                            'type' => $phoneData['type'],
                        ]);
                        $newPhoneIds[] = $phone->id;
                    }
                }

                // Delete phones that were not included in the request
                $phonesToDelete = array_diff($existingPhoneIds, $newPhoneIds);
                if (!empty($phonesToDelete)) {
                    $health_plan->phones()->whereIn('id', $phonesToDelete)->delete();
                }
            }

            DB::commit();

            // Load relationships
            $health_plan->load(['phones', 'documents', 'approver', 'user', 'pricingContracts.procedure']);
            
            // Add logo URL for frontend display
            if ($health_plan->logo) {
                $health_plan->logo_url = Storage::disk('public')->url($health_plan->logo);
            }

            return response()->json([
                'success' => true,
                'message' => 'Health plan updated successfully',
                'data' => new HealthPlanResource($health_plan)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating health plan: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update health plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified health plan.
     *
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function destroy(HealthPlan $health_plan): JsonResponse
    {
        try {
            // Check if health plan has related data
            if ($health_plan->patients()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete health plan with associated patients'
                ], 422);
            }

            if ($health_plan->solicitations()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete health plan with associated solicitations'
                ], 422);
            }

            DB::beginTransaction();

            // Delete phones
            $health_plan->phones()->delete();

            // Delete documents
            foreach ($health_plan->documents as $document) {
                if ($document->file_path) {
                    Storage::disk('public')->delete($document->file_path);
                }
                $document->delete();
            }

            // Delete health plan
            $health_plan->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Health plan deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting health plan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete health plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a health plan.
     *
     * @param Request $request
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function approve(Request $request, HealthPlan $health_plan): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:approved,rejected',
                'rejection_reason' => 'required_if:status,rejected|nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Only allow pending health plans to be approved/rejected
            if ($health_plan->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending health plans can be approved or rejected'
                ], 422);
            }

            // Update status
            $health_plan->status = $request->status;
            
            if ($request->status === 'approved') {
                $health_plan->approved_at = now();
                $health_plan->approved_by = Auth::id();
            } else {
                // Store rejection reason as a note
                $health_plan->documents()->create([
                    'type' => 'rejection_note',
                    'description' => $request->rejection_reason,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            $health_plan->save();

            // Load relationships
            $health_plan->load(['phones', 'documents', 'approver']);

            return response()->json([
                'success' => true,
                'message' => 'Health plan ' . ($request->status === 'approved' ? 'approved' : 'rejected') . ' successfully',
                'data' => new HealthPlanResource($health_plan)
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving health plan: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve health plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload documents for a health plan.
     *
     * @param Request $request
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function uploadDocuments(Request $request, HealthPlan $health_plan): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array',
                'documents.*.file' => 'required|file|max:10240', // 10MB max
                'documents.*.type' => 'required|string|in:contract,ans_certificate,authorization,financial,legal,identification,agreement,technical,other',
                'documents.*.description' => 'required|string|max:255',
                'documents.*.reference_date' => 'nullable|date',
                'documents.*.expiration_date' => 'nullable|date|after:reference_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Determine the user association
            $userId = $health_plan->user_id;
            

            $uploadedDocuments = [];

            DB::beginTransaction();

            foreach ($request->file('documents') as $index => $documentFile) {
                $fileData = $request->input('documents')[$index];
                
                // Get file extension
                $extension = $documentFile->getClientOriginalExtension();
                
                // Validate file type (allowed: pdf, doc, docx, jpg, jpeg, png)
                $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                if (!in_array(strtolower($extension), $allowedTypes)) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid file type. Allowed types: ' . implode(', ', $allowedTypes)
                    ], 422);
                }
                
                // Store file
                $filePath = $documentFile->store('health_plans/documents/' . $health_plan->id, 'public');
                
                // Create document record
                $document = $health_plan->documents()->create([
                    'type' => $fileData['type'],
                    'description' => $fileData['description'],
                    'file_path' => $filePath,
                    'file_name' => $documentFile->getClientOriginalName(),
                    'file_type' => $documentFile->getClientMimeType(),
                    'file_size' => $documentFile->getSize(),
                    'reference_date' => $fileData['reference_date'] ?? null,
                    'expiration_date' => $fileData['expiration_date'] ?? null,
                    'uploaded_by' => Auth::id(),
                    'user_id' => $userId, // Associate document with the user
                ]);

                $uploadedDocuments[] = $document;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Documents uploaded successfully',
                'count' => count($uploadedDocuments),
                'data' => $uploadedDocuments,
                'health_plan' => [
                    'id' => $health_plan->id,
                    'name' => $health_plan->name,
                    'user_id' => $health_plan->user_id
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error uploading health plan documents: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update procedures for the health plan.
     *
     * @param Request $request
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function updateProcedures(Request $request, HealthPlan $health_plan): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'procedures' => 'required|array',
                'procedures.*.tuss_id' => 'required|exists:tuss_procedures,id',
                'procedures.*.value' => 'required|numeric|min:0',
                'procedures.*.notes' => 'nullable|string',
                'procedures.*.start_date' => 'nullable|date',
                'skip_contract_update' => 'sometimes|boolean',
                'delete_missing' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Get current procedures
            $currentProcedureIds = $health_plan->pricingContracts()
                ->where('is_active', true)
                ->pluck('tuss_procedure_id')
                ->toArray();
            
            // Track procedures being updated
            $updatedProcedureIds = [];
            $updatedProcedures = [];
            
            // Update or create pricing contracts for each procedure
            foreach ($request->procedures as $procedureData) {
                $tussId = $procedureData['tuss_id'];
                $updatedProcedureIds[] = $tussId;
                
                $pricingContract = $health_plan->pricingContracts()
                    ->where('tuss_procedure_id', $tussId)
                    ->first();

                if ($pricingContract) {
                    // Update existing pricing contract
                    $pricingContract->update([
                        'price' => $procedureData['value'],
                        'notes' => $procedureData['notes'] ?? null,
                    ]);
                } else {
                    // Create new pricing contract
                    $pricingContract = $health_plan->pricingContracts()->create([
                        'tuss_procedure_id' => $tussId,
                        'price' => $procedureData['value'],
                        'notes' => $procedureData['notes'] ?? null,
                        'is_active' => true,
                        'start_date' => $procedureData['start_date'] ?? now(),
                        'created_by' => Auth::id(),
                    ]);
                }

                $updatedProcedures[] = $pricingContract;
            }
            
            // Handle deletion of procedures not in the update list
            $deletedProcedureIds = [];
            if ($request->delete_missing ?? true) {
                $proceduresToDelete = array_diff($currentProcedureIds, $updatedProcedureIds);
                
                if (!empty($proceduresToDelete)) {
                    // Soft delete by setting is_active to false
                    $health_plan->pricingContracts()
                        ->whereIn('tuss_procedure_id', $proceduresToDelete)
                        ->update(['is_active' => false]);
                    
                    $deletedProcedureIds = $proceduresToDelete;
                }
            }

            // If skip_contract_update is not true, create a new negotiation
            if (!($request->skip_contract_update ?? false)) {
                $negotiationController = new NegotiationController(app(\App\Services\NotificationService::class));
                
                // Buscar um modelo de contrato ativo para planos de saúde
                $contractTemplate = \App\Models\ContractTemplate::where('entity_type', 'health_plan')
                    ->where('is_active', true)
                    ->first();
                
                if (!$contractTemplate) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'No active contract template found for health plans. Please create one first.'
                    ], 422);
                }
                
                $negotiationData = [
                    'entity_type' => 'App\\Models\\HealthPlan',
                    'entity_id' => $health_plan->id,
                    'title' => 'Atualização de valores - ' . $health_plan->name,
                    'description' => 'Atualização de valores dos procedimentos para o plano ' . $health_plan->name,
                    'start_date' => now()->format('Y-m-d'),
                    'end_date' => now()->addMonths(3)->format('Y-m-d'),
                    'status' => 'draft',
                    'contract_template_id' => $contractTemplate->id,
                    'items' => array_map(function($procedure) {
                        return [
                            'tuss_id' => $procedure['tuss_id'],
                            'proposed_value' => $procedure['value'],
                            'notes' => $procedure['notes'] ?? null,
                        ];
                    }, $request->procedures)
                ];

                // Create the negotiation
                $negotiationRequest = new Request($negotiationData);
                $negotiationResult = $negotiationController->store($negotiationRequest);

                if ($negotiationResult->getStatusCode() !== 201) {
                    Log::warning('Failed to create negotiation for procedure updates: ' . $health_plan->id);
                    Log::warning(json_encode($negotiationResult->getData()));
                }
            }

            DB::commit();

            // Load updated pricing contracts
            $health_plan->load(['pricingContracts.procedure']);

            return response()->json([
                'success' => true,
                'message' => 'Procedures updated successfully',
                'data' => [
                    'health_plan' => new HealthPlanResource($health_plan),
                    'updated_procedures_count' => count($updatedProcedures),
                    'deleted_procedures_count' => count($deletedProcedureIds)
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating health plan procedures: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update procedures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the pricing contracts (negotiated procedures) for the health plan.
     *
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function getProcedures(HealthPlan $health_plan): JsonResponse
    {
        try {
            // Carregar os contratos de preço com os procedimentos relacionados
            $pricingContracts = $health_plan->pricingContracts()
                ->with('procedure')
                ->where('is_active', true)
                ->get();

            // Formatar os dados para a resposta
            $formattedProcedures = $pricingContracts->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'tuss_procedure_id' => $contract->tuss_procedure_id,
                    'price' => $contract->price,
                    'notes' => $contract->notes,
                    'is_active' => $contract->is_active,
                    'created_at' => $contract->created_at,
                    'updated_at' => $contract->updated_at,
                    'procedure' => [
                        'id' => $contract->procedure->id,
                        'code' => $contract->procedure->code,
                        'name' => $contract->procedure->name,
                        'description' => $contract->procedure->description,
                        'category' => $contract->procedure->category,
                        'is_active' => $contract->procedure->is_active,
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Negotiated procedures retrieved successfully',
                'data' => $formattedProcedures,
                'health_plan' => [
                    'id' => $health_plan->id,
                    'name' => $health_plan->name
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving health plan procedures: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve procedures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Set parent-child relationship for a health plan.
     *
     * @param Request $request
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function setParent(Request $request, HealthPlan $health_plan): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'parent_id' => 'required|exists:health_plans,id',
                'parent_relation_type' => 'required|string|in:subsidiary,franchise,branch,partner,other',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Check if the parent_id is not the same as the health plan id
            if ($request->parent_id == $health_plan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A health plan cannot be its own parent'
                ], 422);
            }

            // Check for circular references
            $potentialParent = HealthPlan::findOrFail($request->parent_id);
            if ($potentialParent->parent_id == $health_plan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot create circular parent-child relationship'
                ], 422);
            }

            // Check if the potential parent is not already a descendant of this health plan
            $descendants = $health_plan->getAllDescendants()->pluck('id')->toArray();
            if (in_array($request->parent_id, $descendants)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot set a descendant as parent'
                ], 422);
            }

            // Set parent relationship
            $health_plan->parent_id = $request->parent_id;
            $health_plan->parent_relation_type = $request->parent_relation_type;
            $health_plan->save();

            // Load relationships
            $health_plan->load(['parent', 'children']);

            return response()->json([
                'success' => true,
                'message' => 'Parent relationship set successfully',
                'data' => new HealthPlanResource($health_plan)
            ]);
        } catch (\Exception $e) {
            Log::error('Error setting parent relationship: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to set parent relationship',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove parent-child relationship for a health plan.
     *
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function removeParent(HealthPlan $health_plan): JsonResponse
    {
        try {
            // Check if health plan has a parent
            if (!$health_plan->hasParent()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Health plan does not have a parent'
                ], 422);
            }

            // Store the old parent info for the response
            $oldParent = $health_plan->parent;

            // Remove parent relationship
            $health_plan->parent_id = null;
            $health_plan->parent_relation_type = null;
            $health_plan->save();

            return response()->json([
                'success' => true,
                'message' => 'Parent relationship removed successfully',
                'data' => [
                    'health_plan' => new HealthPlanResource($health_plan),
                    'old_parent' => [
                        'id' => $oldParent->id,
                        'name' => $oldParent->name
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing parent relationship: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove parent relationship',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the children of a health plan.
     *
     * @param HealthPlan $health_plan
     * @return JsonResponse
     */
    public function getChildren(HealthPlan $health_plan): JsonResponse
    {
        try {
            // Load children with their basic info
            $children = $health_plan->children()->with(['phones'])->get();

            return response()->json([
                'success' => true,
                'message' => 'Children retrieved successfully',
                'data' => [
                    'health_plan' => [
                        'id' => $health_plan->id,
                        'name' => $health_plan->name
                    ],
                    'children' => HealthPlanResource::collection($children),
                    'children_count' => $children->count()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving children: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve children',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 