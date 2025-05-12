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
use Illuminate\Support\Facades\Mail;
use App\Notifications\HealthPlanCreated;
use Illuminate\Support\Facades\Notification;

class HealthPlanController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(middleware: 'auth:sanctum');
        
        // Permissões para visualização
        $this->middleware('permission:view health plans|view health plan details')->only(['index', 'show']);
        
        // Permissões para criação
        $this->middleware('permission:create health plans')->only(['store']);
        
        // Permissões para edição
        $this->middleware('permission:edit health plans')->only(['update']);
        
        // Permissões para exclusão
        $this->middleware('permission:delete health plans')->only(['destroy']);
        
        // Permissões para aprovação
        $this->middleware('permission:approve health plans')->only(['approve']);
        
        // Permissões para documentos
        $this->middleware('permission:view health plan documents')->only(['uploadDocuments']);
        
        // Permissões para procedimentos
        $this->middleware('permission:view health plan procedures')->only(['getProcedures', 'updateProcedures']);
        
        // Permissões para contratos
        $this->middleware('permission:view health plan contracts')->only(['getContracts']);
        
        // Permissões para solicitações
        $this->middleware('permission:view health plan solicitations')->only(['getSolicitations']);
        
        // Permissões para dados financeiros
        $this->middleware('permission:view health plan financial data')->only(['getFinancialData']);
    }

    /**
     * Display a listing of health plans.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            $query = HealthPlan::with(['phones', 'approver', 'contract', 'user']);
            
            // If user has health plan role, only show their own plan
            if (Auth::user()->hasRole('plan_admin') || Auth::user()->hasRole('plan_admin')) {
                $healthPlanId = Auth::user()->entity_id;
                $query->where('id', $healthPlanId);
            }
            
            // Search by name or CNPJ if search parameter is provided
            if ($request->has('search') && $request->search) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', "%{$searchTerm}%")
                      ->orWhere('cnpj', 'like', "%{$searchTerm}%")
                      ->orWhere('municipal_registration', 'like', "%{$searchTerm}%")
                      ->orWhere('ans_code', 'like', "%{$searchTerm}%");
                });
            } else {
                // Individual filters if search parameter is not used
                
                // Filter by name if provided
                if ($request->has('name') && $request->name) {
                    $query->where('name', 'like', "%{$request->name}%");
                }
                
                // Filter by CNPJ if provided
                if ($request->has('cnpj') && $request->cnpj) {
                    $query->where('cnpj', 'like', "%{$request->cnpj}%");
                }

                // Filter by municipal registration if provided
                if ($request->has('municipal_registration') && $request->municipal_registration) {
                    $query->where('municipal_registration', 'like', "%{$request->municipal_registration}%");
                }

                // Filter by ANS code if provided
                if ($request->has('ans_code') && $request->ans_code) {
                    $query->where('ans_code', 'like', "%{$request->ans_code}%");
                }
            }
            
            // Filter by status if provided
            if ($request->has('status') && $request->status) {
                if (is_array($request->status)) {
                    $query->whereIn('status', $request->status);
                } else {
                    $query->where('status', $request->status);
                }
            }

            // Filter by city if provided
            if ($request->has('city') && $request->city) {
                $query->where('city', 'like', "%{$request->city}%");
            }

            // Filter by state if provided
            if ($request->has('state') && $request->state) {
                $query->where('state', $request->state);
            }
            
            // Filter by contract status if provided
            if ($request->has('has_signed_contract')) {
                $hasContract = filter_var($request->has_signed_contract, FILTER_VALIDATE_BOOLEAN);
                $query->where('has_signed_contract', $hasContract);
            }
            
            // Filter by parent_id or parent-only status
            if ($request->has('parent_id') && $request->parent_id) {
                $query->where('parent_id', $request->parent_id);
            } elseif ($request->has('is_parent')) {
                $isParent = filter_var($request->is_parent, FILTER_VALIDATE_BOOLEAN);
                if ($isParent) {
                    $query->whereNull('parent_id');
                } else {
                    $query->whereNotNull('parent_id');
                }
            }

            // Filter by date range if provided
            if ($request->has('date_start') && $request->date_start) {
                $query->whereDate('created_at', '>=', $request->date_start);
            }
            if ($request->has('date_end') && $request->date_end) {
                $query->whereDate('created_at', '<=', $request->date_end);
            }
            
            // Apply sorting
            $sortField = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            
            // Validate sort field to prevent SQL injection
            $allowedSortFields = [
                'name', 'cnpj', 'municipal_registration', 'ans_code', 
                'city', 'state', 'status', 'created_at'
            ];
            
            if (in_array($sortField, $allowedSortFields)) {
                $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Get paginated results
            $perPage = $request->input('per_page', 15);
            $healthPlans = $query->paginate($perPage);
            
            // Add logo URLs and format data
            $healthPlans->getCollection()->transform(function ($healthPlan) {
                if ($healthPlan->logo) {
                    $healthPlan->logo_url = Storage::disk('public')->url($healthPlan->logo);
                }
                return $healthPlan;
            });
            
            return HealthPlanResource::collection($healthPlans);
            
        } catch (\Exception $e) {
            Log::error('Error fetching health plans: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch health plans',
                'error' => $e->getMessage()
            ], 500);
        }
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
                'password' => 'sometimes|string|min:8',
                'ans_code' => 'nullable|string|max:20',
                'description' => 'nullable|string',
                // Legal Representative
                'legal_representative_name' => 'required|string|max:255',
                'legal_representative_cpf' => 'required|string|max:14',
                'legal_representative_position' => 'required|string|max:255',
                'legal_representative_email' => 'required|email|unique:users,email',
                'legal_representative_password' => 'required|string|min:8',
                // Operational Representative
                'operational_representative_name' => 'required|string|max:255',
                'operational_representative_cpf' => 'required|string|max:14',
                'operational_representative_position' => 'required|string|max:255',
                'operational_representative_email' => 'required|email|unique:users,email',
                'operational_representative_password' => 'required|string|min:8',
                // Address
                'address' => 'required|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:2',
                'postal_code' => 'required|string|max:10',
                'logo' => 'nullable|image|max:2048',
                // Contacts
                'phones' => 'sometimes|array',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
                // Documents
                'documents' => 'sometimes|array',
                'documents.*.file' => 'sometimes|file|max:10240',
                'documents.*.type' => 'required|string|in:contract,ans_certificate,authorization,financial,legal,identification,agreement,technical,other',
                'documents.*.description' => 'required|string|max:255',
                'documents.*.reference_date' => 'nullable|date',
                'documents.*.expiration_date' => 'nullable|date|after:reference_date',
                'documents.*.contract_expiration_alert_days' => 'nullable|integer|min:1|max:365',
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
            $healthPlan = new HealthPlan($request->except('logo', 'phones', 'documents', 'procedures', 'auto_approve', 'send_welcome_email'));
            $healthPlan->logo = $logoPath;
            $healthPlan->user_id = Auth::id();
            
            // Add operational representative info
            $healthPlan->operational_representative_name = $request->operational_representative_name;
            $healthPlan->operational_representative_cpf = $request->operational_representative_cpf;
            $healthPlan->operational_representative_position = $request->operational_representative_position;
            $healthPlan->operational_representative_email = $request->operational_representative_email;
            $healthPlan->operational_representative_phone = $request->operational_representative_phone;
            
            $healthPlan->save();

            // Generate a random password for the new user if not provided
            $plainPassword = $request->input('password', Str::random(10));
            
            // Create main health plan admin user
            $admin = User::factory()->create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($plainPassword),
                'profile_photo' => $logoPath,
                'entity_id' => $healthPlan->id,
                'entity_type' => 'App\\Models\\HealthPlan',
            ]);
            $admin->assignRole('plan_admin');

            // Create legal representative user
            $legalRep = User::factory()->create([
                'name' => $request->legal_representative_name,
                'email' => $request->legal_representative_email,
                'password' => Hash::make($request->legal_representative_password),
                'entity_id' => $healthPlan->id,
                'entity_type' => 'App\\Models\\HealthPlan',
            ]);
            $legalRep->assignRole('legal_representative');

            // Send welcome email
            $legalRep->notify(new \App\Notifications\WelcomeNotification($legalRep, $healthPlan, false));

            // Create operational representative user
            $opRep = User::factory()->create([
                'name' => $request->operational_representative_name,
                'email' => $request->operational_representative_email,
                'password' => Hash::make($request->operational_representative_password),
                'entity_id' => $healthPlan->id,
                'entity_type' => 'App\\Models\\HealthPlan',
            ]);
            $opRep->assignRole('operational_representative');

            // Send welcome email
            $opRep->notify(new \App\Notifications\WelcomeNotification($opRep, $healthPlan, false));

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
            if ($request->has('documents') && is_array($request->documents)) {
                foreach ($request->documents as $documentData) {
                    if (!isset($documentData['file']) || !$documentData['file']->isValid()) {
                        continue;
                    }

                    $documentFile = $documentData['file'];
                    
                    // Get file extension and check allowed types
                    $extension = $documentFile->getClientOriginalExtension();
                    $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'md', 'txt'];
                    if (!in_array(strtolower($extension), $allowedTypes)) {
                        continue;
                    }

                    try {
                        // Create directory if it doesn't exist
                        $directory = 'health_plans/documents/' . $healthPlan->id;
                        Storage::disk('public')->makeDirectory($directory);
                        
                        // Store file with timestamp and original name
                        $fileName = time() . '_' . $documentFile->getClientOriginalName();
                        $filePath = $documentFile->storeAs($directory, $fileName, 'public');
                        
                        if ($filePath) {
                            // Create document record
                            $document = $healthPlan->documents()->create([
                                'type' => $documentData['type'],
                                'description' => $documentData['description'],
                                'file_path' => $filePath,
                                'file_name' => $documentFile->getClientOriginalName(),
                                'file_type' => $documentFile->getClientMimeType(),
                                'file_size' => $documentFile->getSize(),
                                'reference_date' => $documentData['reference_date'] ?? null,
                                'expiration_date' => $documentData['expiration_date'] ?? null,
                                'contract_expiration_alert_days' => $documentData['contract_expiration_alert_days'] ?? 90, // Default to 90 days
                                'uploaded_by' => Auth::id(),
                                'user_id' => $healthPlan->user_id,
                            ]);

                            // If this is a contract document, schedule expiration alerts
                            if ($document->type === 'contract' && $document->expiration_date) {
                                $this->scheduleContractExpirationAlerts($document);
                            }

                            $uploadedDocuments[] = $document;
                        }
                    } catch (\Exception $e) {
                        Log::error('Error uploading document: ' . $e->getMessage());
                        continue;
                    }
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
                if ($request->auto_approve === "true" || $request->auto_approve === true) {
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
                        if ($request->auto_approve === "true" || $request->auto_approve === true) {
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
                    Log::warning('Failed to create initial negotiation for health plan: ' . $healthPlan->id);
                    Log::warning(json_encode($negotiationResult->getData()));
                }
            }

            DB::commit();

            // Send notifications
            try {
                // Notify admins
                $admins = User::role('admin')->get();
                Notification::send($admins, new HealthPlanCreated($healthPlan));

                // Notify the health plan user
                if ($admin) {
                    $admin->notify(new HealthPlanCreated($healthPlan));
                }

                // Log the notification
                Log::info('Health plan creation notifications sent', [
                    'health_plan_id' => $healthPlan->id,
                    'admin_count' => $admins->count(),
                    'user_notified' => $admin ? $admin->id : null
                ]);
            } catch (\Exception $e) {
                // Log notification error but don't fail the request
                Log::error('Failed to send health plan creation notifications', [
                    'health_plan_id' => $healthPlan->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Add audit log
            $healthPlan->audit()->create([
                'event' => 'created',
                'user_id' => Auth::id(),
                'user_type' => get_class(Auth::user()),
                'old_values' => [],
                'new_values' => $healthPlan->toArray(),
                'url' => request()->fullUrl(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'tags' => 'health_plan_creation',
                'custom_message' => 'Plano de saúde criado: ' . $healthPlan->name
            ]);

            // Load relationships for response
            $healthPlan->load(['phones', 'user', 'documents']);

            return response()->json([
                'success' => true,
                'message' => 'Health plan created successfully',
                'data' => new HealthPlanResource($healthPlan),
                'documents_count' => isset($uploadedDocuments) ? count($uploadedDocuments) : 0
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log detailed error
            Log::error('Error creating health plan', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['password', 'documents']),
                'user_id' => Auth::id()
            ]);

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
            // Check if user is restricted to viewing only their own health plan
            if ((Auth::user()->hasRole('health_plan') || Auth::user()->hasRole('plan_admin')) 
                && Auth::user()->entity_id != $health_plan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this health plan'
                ], 403);
            }
            
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
                'email' => 'sometimes|email|unique:users,email,' . ($health_plan->user ? $health_plan->user->id : ''),
                'password' => 'sometimes|string|min:8',
                'documents' => 'sometimes|array',
                'documents.*.file' => 'sometimes|file|max:10240',
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

            // Update health plan fields
            $health_plan->fill($request->only([
                'name', 'cnpj', 'municipal_registration', 'ans_code', 'description',
                'legal_representative_name', 'legal_representative_cpf', 'legal_representative_position',
                'address', 'city', 'state', 'postal_code'
            ]));
            
            $health_plan->save();

            // Update associated user email and password if provided
            if ($health_plan->user) {
                $userUpdates = [];
                
                if ($request->has('email')) {
                    $userUpdates['email'] = $request->input('email');
                }
                
                if ($request->has('password')) {
                    $userUpdates['password'] = Hash::make($request->input('password'));
                }
                
                if (!empty($userUpdates)) {
                    $health_plan->user->update($userUpdates);
                }
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

            // Handle document uploads if provided
            if ($request->has('documents') && is_array($request->documents)) {
                foreach ($request->documents as $documentData) {
                    if (!isset($documentData['file']) || !$documentData['file']->isValid()) {
                        continue;
                    }

                    $documentFile = $documentData['file'];
                    
                    // Get file extension and check allowed types
                    $extension = $documentFile->getClientOriginalExtension();
                    $allowedTypes = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'md', 'txt'];
                    if (!in_array(strtolower($extension), $allowedTypes)) {
                        continue;
                    }

                    try {
                        // Create directory if it doesn't exist
                        $directory = 'health_plans/documents/' . $health_plan->id;
                        Storage::disk('public')->makeDirectory($directory);
                        
                        // Store file with timestamp and original name
                        $fileName = time() . '_' . $documentFile->getClientOriginalName();
                        $filePath = $documentFile->storeAs($directory, $fileName, 'public');
                        
                        if ($filePath) {
                            // Create document record
                            $document = $health_plan->documents()->create([
                                'type' => $documentData['type'],
                                'description' => $documentData['description'],
                                'file_path' => $filePath,
                                'file_name' => $documentFile->getClientOriginalName(),
                                'file_type' => $documentFile->getClientMimeType(),
                                'file_size' => $documentFile->getSize(),
                                'reference_date' => $documentData['reference_date'] ?? null,
                                'expiration_date' => $documentData['expiration_date'] ?? null,
                                'uploaded_by' => Auth::id(),
                                'user_id' => $health_plan->user_id,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::error('Error uploading document: ' . $e->getMessage());
                        continue;
                    }
                }
            }

            DB::commit();

            // Add audit log for update
            $health_plan->audit()->create([
                'event' => 'updated',
                'user_id' => Auth::id(),
                'user_type' => get_class(Auth::user()),
                'old_values' => [],
                'new_values' => $health_plan->toArray(),
                'url' => request()->fullUrl(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'tags' => 'health_plan_update',
                'custom_message' => 'Plano de saúde atualizado: ' . $health_plan->name
            ]);

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
            
            // Log detailed error
            Log::error('Error updating health plan', [
                'health_plan_id' => $health_plan->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['password', 'documents']),
                'user_id' => Auth::id()
            ]);

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

    /**
     * Dashboard methods
     */

    /**
     * Get dashboard statistics for health plans
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDashboardStats(Request $request): JsonResponse
    {
        try {
            // Determine time range
            $range = $request->input('range', 'month');
            $startDate = null;
            
            switch ($range) {
                case 'week':
                    $startDate = now()->subWeek();
                    break;
                case 'month':
                    $startDate = now()->subMonth();
                    break;
                case 'quarter':
                    $startDate = now()->subMonths(3);
                    break;
                case 'year':
                    $startDate = now()->subYear();
                    break;
                default:
                    $startDate = now()->subMonth();
            }

            // Contagem de planos por status
            $totalPlans = HealthPlan::count();
            $approvedPlans = HealthPlan::where('status', 'approved')->count();
            $pendingPlans = HealthPlan::where('status', 'pending')->count();
            $rejectedPlans = HealthPlan::where('status', 'rejected')->count();
            
            // Contagem de planos com e sem contrato
            $plansWithContract = HealthPlan::where('has_signed_contract', true)->count();
            $plansWithoutContract = HealthPlan::where('has_signed_contract', false)->orWhereNull('has_signed_contract')->count();
            
            // Contagem de procedimentos
            $totalProcedures = DB::table('health_plan_procedures')
                ->where('is_active', true)
                ->count();
            
            // Estatísticas de solicitações e consultas
            $totalSolicitations = DB::table('solicitations')
                ->whereNotNull('health_plan_id')
                ->when($startDate, function ($query) use ($startDate) {
                    return $query->where('created_at', '>=', $startDate);
                })
                ->count();
                
            $totalAppointments = DB::table('appointments')
                ->whereIn('solicitation_id', function ($query) {
                    $query->select('id')->from('solicitations')->whereNotNull('health_plan_id');
                })
                ->when($startDate, function ($query) use ($startDate) {
                    return $query->where('created_at', '>=', $startDate);
                })
                ->count();
            
            // Estatísticas financeiras
            $totalRevenue = DB::table('payments')
                ->where('status', 'paid')
                ->whereIn('entity_type', ['App\\Models\\HealthPlan'])
                ->when($startDate, function ($query) use ($startDate) {
                    return $query->where('created_at', '>=', $startDate);
                })
                ->sum('amount');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'total_plans' => $totalPlans,
                    'approved_plans' => $approvedPlans,
                    'pending_plans' => $pendingPlans,
                    'rejected_plans' => $rejectedPlans,
                    'has_contract' => $plansWithContract,
                    'missing_contract' => $plansWithoutContract,
                    'total_procedures' => $totalProcedures,
                    'total_solicitations' => $totalSolicitations,
                    'total_appointments' => $totalAppointments,
                    'total_revenue' => $totalRevenue,
                    'time_range' => $range
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting dashboard stats: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get dashboard statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get procedure statistics for dashboard
     *
     * @return JsonResponse
     */
    public function getDashboardProcedures(): JsonResponse
    {
        try {
            // Obter as estatísticas de procedimentos mais usados e suas faixas de preço
            $procedures = DB::table('health_plan_procedures as hpp')
                ->join('tuss_procedures as tp', 'hpp.tuss_procedure_id', '=', 'tp.id')
                ->where('hpp.is_active', true)
                ->select(
                    'tp.id as procedure_id',
                    'tp.name as procedure_name',
                    'tp.code as procedure_code',
                    DB::raw('AVG(hpp.price) as avg_price'),
                    DB::raw('MIN(hpp.price) as min_price'),
                    DB::raw('MAX(hpp.price) as max_price'),
                    DB::raw('COUNT(DISTINCT hpp.health_plan_id) as plans_count')
                )
                ->groupBy('tp.id', 'tp.name', 'tp.code')
                ->orderByDesc('plans_count')
                ->limit(10)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $procedures
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting procedure statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get procedure statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get financial data for dashboard
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getDashboardFinancial(Request $request): JsonResponse
    {
        try {
            // Determine time range
            $range = $request->input('range', 'month');
            $startDate = null;
            $interval = 'month'; // Default grouping interval
            
            switch ($range) {
                case 'week':
                    $startDate = now()->subWeek();
                    $interval = 'day';
                    break;
                case 'month':
                    $startDate = now()->subMonth();
                    $interval = 'day';
                    break;
                case 'quarter':
                    $startDate = now()->subMonths(3);
                    $interval = 'week';
                    break;
                case 'year':
                    $startDate = now()->subYear();
                    $interval = 'month';
                    break;
                default:
                    $startDate = now()->subMonth();
                    $interval = 'day';
            }

            // Agrupar por intervalo usando SQL específico do banco
            $dateFormat = '%Y-%m-%d'; // Formato padrão para diário
            
            if ($interval === 'week') {
                $dateFormat = '%Y-%u'; // Ano-Semana
            } elseif ($interval === 'month') {
                $dateFormat = '%Y-%m'; // Ano-Mês
            }
            
            // Obter dados agrupados pelo intervalo definido
            $financialData = DB::table('payments')
                ->where('status', 'paid')
                ->whereIn('entity_type', ['App\\Models\\HealthPlan'])
                ->where('created_at', '>=', $startDate)
                ->select(
                    DB::raw("DATE_FORMAT(created_at, '$dateFormat') as period"),
                    DB::raw("SUM(amount) as revenue"),
                    DB::raw("COUNT(*) as payments")
                )
                ->groupBy('period')
                ->orderBy('period')
                ->get();
            
            // Formatar para ficar mais amigável para o frontend
            $formattedData = $financialData->map(function ($item) use ($interval) {
                $periodLabel = $item->period;
                
                if ($interval === 'day') {
                    // Já está no formato correto YYYY-MM-DD
                } elseif ($interval === 'week') {
                    // Converter YYYY-WW para uma descrição da semana
                    $parts = explode('-', $item->period);
                    $year = $parts[0];
                    $week = $parts[1];
                    $periodLabel = "Semana $week, $year";
                } elseif ($interval === 'month') {
                    // Converter YYYY-MM para nome do mês
                    $parts = explode('-', $item->period);
                    $year = $parts[0];
                    $month = $parts[1];
                    $monthNames = [
                        '01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março',
                        '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
                        '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro',
                        '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'
                    ];
                    $periodLabel = $monthNames[$month] . " $year";
                }
                
                return [
                    'period' => $item->period,
                    'label' => $periodLabel,
                    'revenue' => $item->revenue,
                    'payments' => $item->payments
                ];
            });
            
            return response()->json([
                'success' => true,
                'data' => $formattedData,
                'meta' => [
                    'range' => $range,
                    'interval' => $interval,
                    'start_date' => $startDate->format('Y-m-d')
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting financial data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get financial data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent health plans for dashboard
     *
     * @return JsonResponse
     */
    public function getRecentPlans(): JsonResponse
    {
        try {
            // Buscar os planos mais recentes com contagem de procedimentos
            $recentPlans = HealthPlan::select(
                    'health_plans.id',
                    'health_plans.name',
                    'health_plans.status',
                    'health_plans.created_at',
                    DB::raw('(SELECT COUNT(*) FROM health_plan_procedures WHERE health_plan_procedures.health_plan_id = health_plans.id AND health_plan_procedures.is_active = 1) as procedures_count')
                )
                ->orderBy('health_plans.created_at', 'desc')
                ->limit(10)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $recentPlans
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting recent plans: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get recent plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent solicitations for dashboard
     *
     * @return JsonResponse
     */
    public function getRecentSolicitations(): JsonResponse
    {
        try {
            // Buscar solicitações recentes relacionadas a planos de saúde
            $recentSolicitations = DB::table('solicitations as s')
                ->join('health_plans as hp', 's.health_plan_id', '=', 'hp.id')
                ->join('patients as p', 's.patient_id', '=', 'p.id')
                ->join('tuss_procedures as tp', 's.procedure_id', '=', 'tp.id')
                ->select(
                    's.id',
                    'hp.name as health_plan_name',
                    'p.name as patient_name',
                    'tp.name as procedure_name',
                    's.status',
                    's.created_at'
                )
                ->whereNotNull('s.health_plan_id')
                ->orderBy('s.created_at', 'desc')
                ->limit(10)
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $recentSolicitations
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting recent solicitations: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get recent solicitations',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Schedule contract expiration alerts
     *
     * @param Document $document
     * @return void
     */
    private function scheduleContractExpirationAlerts(Document $document): void
    {
        try {
            $alertDays = $document->contract_expiration_alert_days ?? 90;
            $expirationDate = \Carbon\Carbon::parse($document->expiration_date);
            $alertDate = $expirationDate->subDays($alertDays);

            // Schedule the initial alert
            \App\Jobs\ContractExpirationAlert::dispatch($document)
                ->delay($alertDate);

            // Schedule recurring alerts if contract is not renewed
            $recurringAlert = new \App\Jobs\RecurringContractExpirationAlert($document);
            $recurringAlert->dispatch($document)
                ->delay($expirationDate);

        } catch (\Exception $e) {
            Log::error('Error scheduling contract expiration alerts: ' . $e->getMessage());
        }
    }
} 