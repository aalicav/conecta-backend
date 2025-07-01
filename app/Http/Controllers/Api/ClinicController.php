<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClinicResource;
use App\Models\Clinic;
use App\Models\Document;
use App\Models\Address;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Notifications\DocumentAnalysisRequired;
use App\Notifications\NewClinicRegistered;
use Illuminate\Support\Facades\Notification;

class ClinicController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:view clinics')->only(['index', 'show', 'branches']);
        $this->middleware('permission:create clinics')->only(['store']);
        $this->middleware('permission:edit clinics')->only(['update', 'updateStatus']);
        $this->middleware('permission:delete clinics')->only(['destroy']);
        $this->middleware('permission:approve clinics')->only(['approve']);
    }

    /**
     * Display a listing of clinics.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        try {
            $query = Clinic::with([
                'phones', 
                'approver', 
                'contract', 
                'parentClinic',
                'addresses',
                'documents',
                'pricingContracts',
                'professionals' => function($query) {
                    $query->where('is_active', true)->take(10);
                }
            ]);
            
            // Only show root clinics (not branches)
            if ($request->has('only_roots') && $request->only_roots === 'true') {
                $query->whereNull('parent_clinic_id');
            }
            
            // Only show branch clinics
            if ($request->has('only_branches') && $request->only_branches === 'true') {
                $query->whereNotNull('parent_clinic_id');
            }
            
            // Filter by status if provided
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Filter by name if provided
            if ($request->has('name')) {
                $query->where('name', 'like', "%{$request->name}%");
            }
            
            // Filter by CNPJ if provided
            if ($request->has('cnpj')) {
                $query->where('cnpj', 'like', "%{$request->cnpj}%");
            }
            
            // Filter by CNES if provided
            if ($request->has('cnes')) {
                $query->where('cnes', 'like', "%{$request->cnes}%");
            }
            
            // Filter by city if provided
            if ($request->has('city')) {
                $query->where('city', 'like', "%{$request->city}%");
            }
            
            // Filter by state if provided
            if ($request->has('state')) {
                $query->where('state', $request->state);
            }
            
            // Filter by active status
            if ($request->has('is_active')) {
                $isActive = $request->is_active === 'true' ? true : false;
                $query->where('is_active', $isActive);
            }
            
            // Filter by contract status
            if ($request->has('has_signed_contract')) {
                $hasSignedContract = $request->has_signed_contract === 'true' ? true : false;
                $query->where('has_signed_contract', $hasSignedContract);
            }
            
            // Filter by nearby location if provided
            if ($request->has('latitude') && $request->has('longitude')) {
                $distance = $request->get('distance', 10); // Default to 10km
                $query->nearby($request->latitude, $request->longitude, $distance);
            }
            
            // Apply sorting
            $sortField = $request->sort_by ?? 'created_at';
            $sortDirection = $request->sort_direction ?? 'desc';
            $query->orderBy($sortField, $sortDirection);
            
            // Add counts if requested
            if ($request->has('with_counts') && $request->with_counts === 'true') {
                $query->withCount(['professionals', 'appointments', 'branches']);
            }
            
            $clinics = $query->paginate($request->per_page ?? 15);
            
            return ClinicResource::collection($clinics);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinics: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Store a newly created clinic.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'cnpj' => 'required|string|max:18|unique:clinics,cnpj',
                'description' => 'nullable|string',
                'cnes' => 'nullable|string|max:20',
                'parent_clinic_id' => 'nullable|exists:clinics,id',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:2',
                'postal_code' => 'nullable|string|max:10',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'addresses' => 'sometimes|array',
                'addresses.*.street' => 'required|string|max:255',
                'addresses.*.number' => 'nullable|string|max:20',
                'addresses.*.complement' => 'nullable|string|max:100',
                'addresses.*.neighborhood' => 'nullable|string|max:100',
                'addresses.*.city' => 'required|string|max:100',
                'addresses.*.state' => 'required|string|max:2',
                'addresses.*.postal_code' => 'required|string|max:10',
                'addresses.*.latitude' => 'nullable|numeric',
                'addresses.*.longitude' => 'nullable|numeric',
                'addresses.*.is_primary' => 'sometimes|boolean',
                'logo' => 'nullable|image|max:2048',
                'phones' => 'sometimes|array',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
                'phones.*.is_whatsapp' => 'sometimes|boolean',
                'phones.*.is_primary' => 'sometimes|boolean',
                'email' => 'required|email|unique:users,email',
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
                $logoPath = $request->file('logo')->store('clinics/logos', 'public');
            }

            // Create clinic
            $clinic = new Clinic($request->except('logo', 'phones', 'addresses'));
            $clinic->logo = $logoPath;
            $clinic->save();

            // Add phones if provided
            if ($request->has('phones') && is_array($request->phones)) {
                foreach ($request->phones as $phoneData) {
                    $clinic->phones()->create([
                        'number' => $phoneData['number'],
                        'type' => $phoneData['type'],
                        'is_whatsapp' => $phoneData['is_whatsapp'] ?? false,
                        'is_primary' => $phoneData['is_primary'] ?? false,
                    ]);
                }
            }

            // Add addresses if provided
            if ($request->has('addresses') && is_array($request->addresses)) {
                foreach ($request->addresses as $addressData) {
                    $clinic->addresses()->create([
                        'street' => $addressData['street'],
                        'number' => $addressData['number'] ?? null,
                        'complement' => $addressData['complement'] ?? null,
                        'neighborhood' => $addressData['neighborhood'] ?? null,
                        'city' => $addressData['city'],
                        'state' => $addressData['state'],
                        'postal_code' => $addressData['postal_code'],
                        'latitude' => $addressData['latitude'] ?? null,
                        'longitude' => $addressData['longitude'] ?? null,
                        'is_primary' => $addressData['is_primary'] ?? false,
                    ]);
                }
            }

            // Create user account and send welcome email
            $plainPassword = Str::random(10);
            $clinicUser = User::create([
                'name' => $clinic->name,
                'email' => $request->email,
                'password' => bcrypt($plainPassword),
                'entity_id' => $clinic->id,
                'entity_type' => Clinic::class,
                'is_active' => false,
            ]);

            $clinicUser->assignRole('clinic_admin');
        

            // Send welcome email with password
            if ($clinicUser) {
                // Get company data from config
                $companyName = config('app.name');
                $companyAddress = config('app.address', 'Address not available');
                $companyCity = config('app.city', 'City not available');
                $companyState = config('app.state', 'State not available');
                $supportEmail = config('app.support_email', 'support@example.com');
                $supportPhone = config('app.support_phone', '(00) 0000-0000');
                $socialMedia = [
                    'Facebook' => 'https://facebook.com/' . config('app.social.facebook', ''),
                    'Instagram' => 'https://instagram.com/' . config('app.social.instagram', ''),
                ];
                
                // Send welcome email
                Mail::send('emails.welcome_user', [
                    'user' => $clinicUser,
                    'password' => $plainPassword,
                    'loginUrl' => config('app.frontend_url') . '/login',
                    'companyName' => $companyName,
                    'companyAddress' => $companyAddress,
                    'companyCity' => $companyCity,
                    'companyState' => $companyState,
                    'supportEmail' => $supportEmail,
                    'supportPhone' => $supportPhone,
                    'socialMedia' => $socialMedia,
                    'entityType' => 'Clínica',
                    'clinic' => $clinic
                ], function ($message) use ($clinicUser) {
                    $message->to($clinicUser->email, $clinicUser->name)
                            ->subject('Bem-vindo ao ' . config('app.name') . ' - Detalhes da sua conta');
                });
            }

            DB::commit();

            // Load relationships
            $clinic->load(['phones', 'addresses']);

            return response()->json([
                'success' => true,
                'message' => 'Clinic created successfully',
                'data' => new ClinicResource($clinic)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating clinic: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create clinic',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified clinic.
     *
     * @param Clinic $clinic
     * @return ClinicResource|JsonResponse
     */
    public function show(Clinic $clinic)
    {
        try {
            // Load relationships
            $clinic->load([
                'phones', 
                'documents', 
                'approver', 
                'user',
                'contract', 
                'pricingContracts',
                'addresses',
                'professionals' => function($query) {
                    $query->where('is_active', true)->take(10);
                }
            ]);

            // Load counts
            $clinic->loadCount(['appointments']);

            return new ClinicResource($clinic);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinic: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update the specified clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function update(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'cnpj' => 'sometimes|string|max:18|unique:clinics,cnpj,' . $clinic->id,
                'description' => 'nullable|string',
                'cnes' => 'nullable|string|max:20',
                'technical_director' => 'sometimes|string|max:255',
                'technical_director_document' => 'sometimes|string|max:20',
                'technical_director_professional_id' => 'sometimes|string|max:20',
                'parent_clinic_id' => 'nullable|exists:clinics,id',
                'address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:2',
                'postal_code' => 'nullable|string|max:10',
                'latitude' => 'nullable|numeric',
                'longitude' => 'nullable|numeric',
                'addresses' => 'sometimes|array',
                'addresses.*.id' => 'nullable|exists:addresses,id',
                'addresses.*.street' => 'required|string|max:255',
                'addresses.*.number' => 'nullable|string|max:20',
                'addresses.*.complement' => 'nullable|string|max:100',
                'addresses.*.neighborhood' => 'nullable|string|max:100',
                'addresses.*.city' => 'required|string|max:100',
                'addresses.*.state' => 'required|string|max:2',
                'addresses.*.postal_code' => 'required|string|max:10',
                'addresses.*.latitude' => 'nullable|numeric',
                'addresses.*.longitude' => 'nullable|numeric',
                'addresses.*.is_primary' => 'sometimes|boolean',
                'logo' => 'nullable|image|max:2048',
                'is_active' => 'sometimes|boolean',
                'phones' => 'sometimes|array',
                'phones.*.id' => 'sometimes|exists:phones,id',
                'phones.*.number' => 'required|string|max:20',
                'phones.*.type' => 'required|string|in:mobile,landline,whatsapp,fax',
                'phones.*.is_whatsapp' => 'sometimes|boolean',
                'phones.*.is_primary' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Prevent setting parent_clinic_id to itself
            if ($request->has('parent_clinic_id') && $request->parent_clinic_id == $clinic->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'A clinic cannot be its own parent'
                ], 422);
            }

            DB::beginTransaction();

            // Handle logo upload if provided
            if ($request->hasFile('logo')) {
                // Delete old logo if exists
                if ($clinic->logo) {
                    Storage::disk('public')->delete($clinic->logo);
                }
                $logoPath = $request->file('logo')->store('clinics/logos', 'public');
                $clinic->logo = $logoPath;
            }

            // Update clinic
            $clinic->fill($request->except('logo', 'phones', 'addresses'));
            $clinic->save();

            // Update phones if provided
            if ($request->has('phones') && is_array($request->phones)) {
                // Get existing phone IDs
                $existingPhoneIds = $clinic->phones->pluck('id')->toArray();
                $newPhoneIds = [];

                foreach ($request->phones as $phoneData) {
                    if (isset($phoneData['id'])) {
                        // Update existing phone
                        $phone = $clinic->phones()->find($phoneData['id']);
                        if ($phone) {
                            $phone->update([
                                'number' => $phoneData['number'],
                                'type' => $phoneData['type'],
                                'is_whatsapp' => $phoneData['is_whatsapp'] ?? $phone->is_whatsapp,
                                'is_primary' => $phoneData['is_primary'] ?? $phone->is_primary,
                            ]);
                            $newPhoneIds[] = $phone->id;
                        }
                    } else {
                        // Create new phone
                        $phone = $clinic->phones()->create([
                            'number' => $phoneData['number'],
                            'type' => $phoneData['type'],
                            'is_whatsapp' => $phoneData['is_whatsapp'] ?? false,
                            'is_primary' => $phoneData['is_primary'] ?? false,
                        ]);
                        $newPhoneIds[] = $phone->id;
                    }
                }

                // Delete phones that were not included in the request
                $phonesToDelete = array_diff($existingPhoneIds, $newPhoneIds);
                if (!empty($phonesToDelete)) {
                    $clinic->phones()->whereIn('id', $phonesToDelete)->delete();
                }
            }

            // Update addresses if provided
            if ($request->has('addresses') && is_array($request->addresses)) {
                // Get existing address IDs
                $existingAddressIds = $clinic->addresses->pluck('id')->toArray();
                $updatedAddressIds = collect($request->addresses)->pluck('id')->filter()->toArray();
                
                // Delete addresses that are not in the updated list
                $addressIdsToDelete = array_diff($existingAddressIds, $updatedAddressIds);
                if (!empty($addressIdsToDelete)) {
                    Address::whereIn('id', $addressIdsToDelete)->delete();
                }
                
                // Update or create addresses
                foreach ($request->addresses as $addressData) {
                    if (isset($addressData['id'])) {
                        Address::where('id', $addressData['id'])->update([
                            'street' => $addressData['street'],
                            'number' => $addressData['number'] ?? null,
                            'complement' => $addressData['complement'] ?? null,
                            'neighborhood' => $addressData['neighborhood'] ?? null,
                            'city' => $addressData['city'],
                            'state' => $addressData['state'],
                            'postal_code' => $addressData['postal_code'],
                            'latitude' => $addressData['latitude'] ?? null,
                            'longitude' => $addressData['longitude'] ?? null,
                            'is_primary' => $addressData['is_primary'] ?? false,
                        ]);
                    } else {
                        $clinic->addresses()->create([
                            'street' => $addressData['street'],
                            'number' => $addressData['number'] ?? null,
                            'complement' => $addressData['complement'] ?? null,
                            'neighborhood' => $addressData['neighborhood'] ?? null,
                            'city' => $addressData['city'],
                            'state' => $addressData['state'],
                            'postal_code' => $addressData['postal_code'],
                            'latitude' => $addressData['latitude'] ?? null,
                            'longitude' => $addressData['longitude'] ?? null,
                            'is_primary' => $addressData['is_primary'] ?? false,
                        ]);
                    }
                }
            }

            DB::commit();

            // Load relationships
            $clinic->load(['phones', 'documents', 'approver', 'parentClinic', 'addresses']);

            return response()->json([
                'success' => true,
                'message' => 'Clinic updated successfully',
                'data' => new ClinicResource($clinic)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating clinic: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update clinic',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the clinic's status.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function updateStatus(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'is_active' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Ensure the clinic can be activated/deactivated
            if ($request->is_active && !$clinic->has_signed_contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clinic cannot be activated without a signed contract'
                ], 422);
            }

            // Update status
            $clinic->is_active = $request->is_active;
            $clinic->save();

            return response()->json([
                'success' => true,
                'message' => 'Clinic ' . ($request->is_active ? 'activated' : 'deactivated') . ' successfully',
                'data' => new ClinicResource($clinic)
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating clinic status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update clinic status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified clinic.
     *
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function destroy(Clinic $clinic): JsonResponse
    {
        try {
            // Check if clinic has branches
            if ($clinic->branches()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete clinic with associated branches'
                ], 422);
            }

            // Check if clinic has professionals
            if ($clinic->professionals()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete clinic with associated professionals'
                ], 422);
            }

            // Check if clinic has appointments
            if ($clinic->appointments()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete clinic with associated appointments'
                ], 422);
            }

            DB::beginTransaction();

            // Delete phones
            $clinic->phones()->delete();

            // Delete addresses
            $clinic->addresses()->delete();

            // Delete documents
            foreach ($clinic->documents as $document) {
                if ($document->file_path) {
                    Storage::disk('public')->delete($document->file_path);
                }
                $document->delete();
            }

            // Delete logo if exists
            if ($clinic->logo) {
                Storage::disk('public')->delete($clinic->logo);
            }

            // Delete clinic
            $clinic->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Clinic deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting clinic: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete clinic',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function approve(Request $request, Clinic $clinic): JsonResponse
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

            // Only allow pending clinics to be approved/rejected
            if ($clinic->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending clinics can be approved or rejected'
                ], 422);
            }

            // Update status
            $clinic->status = $request->status;
            
            if ($request->status === 'approved') {
                $clinic->approved_at = now();
                $clinic->approved_by = Auth::id();
            } else {
                // Store rejection reason as a note
                $clinic->documents()->create([
                    'type' => 'rejection_note',
                    'description' => $request->rejection_reason,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            $clinic->save();

            // Load relationships
            $clinic->load(['phones', 'documents', 'approver']);

            return response()->json([
                'success' => true,
                'message' => 'Clinic ' . ($request->status === 'approved' ? 'approved' : 'rejected') . ' successfully',
                'data' => new ClinicResource($clinic)
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving clinic: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve clinic',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload documents for a clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function uploadDocuments(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array',
                'documents.*.file' => 'required|file|max:10240', // 10MB max
                'documents.*.type' => 'required|string|in:license,contract,technical_certificate,other',
                'documents.*.description' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uploadedDocuments = [];

            DB::beginTransaction();

            foreach ($request->file('documents') as $index => $documentFile) {
                $fileData = $request->input('documents')[$index];
                
                // Store file
                $filePath = $documentFile->store('clinics/documents/' . $clinic->id, 'public');
                
                // Create document record
                $document = $clinic->documents()->create([
                    'type' => $fileData['type'],
                    'description' => $fileData['description'],
                    'file_path' => $filePath,
                    'file_name' => $documentFile->getClientOriginalName(),
                    'file_type' => $documentFile->getClientMimeType(),
                    'file_size' => $documentFile->getSize(),
                    'uploaded_by' => Auth::id(),
                ]);

                $uploadedDocuments[] = $document;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Documents uploaded successfully',
                'data' => $uploadedDocuments
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error uploading clinic documents: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get branches of a clinic.
     *
     * @param Clinic $clinic
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function branches(Clinic $clinic)
    {
        try {
            $branches = $clinic->branches()
                ->with(['phones', 'approver'])
                ->paginate(15);

            return ClinicResource::collection($branches);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinic branches: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve clinic branches',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update procedures for the clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function updateProcedures(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'procedures' => 'required|array',
                'procedures.*.tuss_id' => 'required|exists:tuss_procedures,id',
                'procedures.*.value' => 'required|numeric|min:0',
                'procedures.*.notes' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Create specialty negotiation through proper channel
            $specialtyNegotiationController = new SpecialtyNegotiationController(
                app(\App\Services\NotificationService::class)
            );
            
            // Prepare negotiation data
            $negotiationData = [
                'entity_type' => 'clinic',
                'entity_id' => $clinic->id,
                'title' => 'Negociação de Especialidades - ' . $clinic->name,
                'description' => 'Atualização de valores para procedimentos da clínica ' . $clinic->name,
                'items' => array_map(function($p) {
                    return [
                        'tuss_id' => $p['tuss_id'],
                        'proposed_value' => $p['value'],
                        'notes' => $p['notes'] ?? null,
                    ];
                }, $request->procedures)
            ];

            $negReq = new Request($negotiationData);
            $response = $specialtyNegotiationController->store($negReq);
            $negotiation = json_decode($response->getContent(), true);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Procedimentos enviados para negociação com sucesso',
                'data' => [
                    'clinic' => new ClinicResource($clinic),
                    'negotiation' => $negotiation['data'] ?? null
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating clinic procedures: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update procedures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the pricing contracts (negotiated procedures) for the clinic.
     *
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function getProcedures(Clinic $clinic): JsonResponse
    {
        try {
            $contracts = $clinic->pricingContracts()->with('procedure')->where('is_active', true)->get();
            $formatted = $contracts->map(function($c) {
                return [
                    'id' => $c->id,
                    'tuss_procedure_id' => $c->tuss_procedure_id,
                    'price' => $c->price,
                    'notes' => $c->notes,
                    'is_active' => $c->is_active,
                    'procedure' => [
                        'id' => $c->procedure->id,
                        'code' => $c->procedure->code,
                        'name' => $c->procedure->name,
                        'description' => $c->procedure->description,
                    ]
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Procedures retrieved successfully',
                'data' => $formatted,
                'clinic' => [
                    'id' => $clinic->id,
                    'name' => $clinic->name
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinic procedures: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve procedures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get professionals associated with the clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function professionals(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            $query = $clinic->professionals();

            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('professional_type')) {
                $query->where('professional_type', $request->professional_type);
            }

            if ($request->has('specialty')) {
                $query->where('specialty', 'like', '%' . $request->specialty . '%');
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('cpf', 'like', "%{$search}%")
                      ->orWhere('council_number', 'like', "%{$search}%");
                });
            }

            // Apply sorting
            $sort = $request->sort ?? 'created_at';
            $direction = $request->direction ?? 'desc';
            $query->orderBy($sort, $direction);

            // Load relationships if requested
            if ($request->has('with')) {
                $relations = explode(',', $request->with);
                $allowedRelations = ['phones', 'documents', 'approver', 'user'];
                $validRelations = array_intersect($allowedRelations, $relations);
                
                if (!empty($validRelations)) {
                    $query->with($validRelations);
                }
            }

            // Pagination
            $perPage = $request->per_page ?? 15;
            $professionals = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Clinic professionals retrieved successfully',
                'data' => [
                    'professionals' => $professionals,
                    'clinic' => [
                        'id' => $clinic->id,
                        'name' => $clinic->name
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinic professionals: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve clinic professionals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Associate professionals with the clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function associateProfessionals(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'professional_ids' => 'required|array',
                'professional_ids.*' => 'required|exists:professionals,id',
                'contract_template_id' => 'nullable|exists:contract_templates,id',
                'contract_details' => 'nullable|array',
                'contract_terms' => 'nullable|string',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $professional_ids = $request->professional_ids;
            $associated = [];
            $alreadyAssociated = [];
            $failed = [];

            foreach ($professional_ids as $id) {
                try {
                    $professional = \App\Models\Professional::findOrFail($id);

                    // Check if already associated
                    if ($professional->clinic_id === $clinic->id) {
                        $alreadyAssociated[] = $id;
                        continue;
                    }

                    // Update the professional to associate with the clinic
                    $professional->clinic_id = $clinic->id;
                    $professional->save();

                    // Create contract if template provided
                    if ($request->has('contract_template_id')) {
                        $contractTemplateId = $request->contract_template_id;
                        $contractController = new \App\Http\Controllers\Api\ContractController();
                        
                        $contractRequest = new Request([
                            'contract_template_id' => $contractTemplateId,
                            'entity_id' => $professional->id,
                            'entity_type' => 'App\\Models\\Professional',
                            'title' => 'Contrato Clínica-Profissional: ' . $clinic->name . ' - ' . $professional->name,
                            'description' => 'Contrato de prestação de serviços profissionais',
                            'terms' => $request->contract_terms ?? null,
                            'details' => $request->contract_details ?? [],
                            'start_date' => $request->start_date ?? now()->format('Y-m-d'),
                            'end_date' => $request->end_date ?? null,
                        ]);
                        
                        $response = $contractController->generate($contractRequest);
                        
                        if ($response->getStatusCode() !== 201) {
                            Log::warning('Failed to generate contract for professional: ' . $professional->id);
                        }
                    }

                    $associated[] = $id;
                } catch (\Exception $e) {
                    Log::error("Error associating professional {$id} with clinic: " . $e->getMessage());
                    $failed[$id] = $e->getMessage();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Professionals associated with clinic successfully',
                'data' => [
                    'clinic' => [
                        'id' => $clinic->id,
                        'name' => $clinic->name
                    ],
                    'associated' => $associated,
                    'already_associated' => $alreadyAssociated,
                    'failed' => $failed
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error associating professionals with clinic: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to associate professionals with clinic',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disassociate professionals from the clinic.
     *
     * @param Request $request
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function disassociateProfessionals(Request $request, Clinic $clinic): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'professional_ids' => 'required|array',
                'professional_ids.*' => 'required|exists:professionals,id',
                'reason' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $professional_ids = $request->professional_ids;
            $disassociated = [];
            $notAssociated = [];
            $failed = [];

            foreach ($professional_ids as $id) {
                try {
                    $professional = \App\Models\Professional::findOrFail($id);

                    // Check if associated with this clinic
                    if ($professional->clinic_id !== $clinic->id) {
                        $notAssociated[] = $id;
                        continue;
                    }

                    // Update the professional to remove association
                    $professional->clinic_id = null;
                    $professional->save();

                    // Create a note about the disassociation if reason provided
                    if ($request->has('reason')) {
                        $professional->documents()->create([
                            'type' => 'disassociation_note',
                            'description' => 'Disassociated from ' . $clinic->name . '. Reason: ' . $request->reason,
                            'uploaded_by' => Auth::id(),
                        ]);
                    }

                    $disassociated[] = $id;
                } catch (\Exception $e) {
                    Log::error("Error disassociating professional {$id} from clinic: " . $e->getMessage());
                    $failed[$id] = $e->getMessage();
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Professionals disassociated from clinic successfully',
                'data' => [
                    'clinic' => [
                        'id' => $clinic->id,
                        'name' => $clinic->name
                    ],
                    'disassociated' => $disassociated,
                    'not_associated' => $notAssociated,
                    'failed' => $failed
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error disassociating professionals from clinic: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to disassociate professionals from clinic',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics about professionals in the clinic.
     *
     * @param Clinic $clinic
     * @return JsonResponse
     */
    public function professionalStats(Clinic $clinic): JsonResponse
    {
        try {
            // Get count by status
            $statusCounts = $clinic->professionals()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();
                
            // Get count by type
            $typeCounts = $clinic->professionals()
                ->selectRaw('professional_type, COUNT(*) as count')
                ->groupBy('professional_type')
                ->pluck('count', 'professional_type')
                ->toArray();
                
            // Get count by specialty
            $specialtyCounts = $clinic->professionals()
                ->selectRaw('specialty, COUNT(*) as count')
                ->groupBy('specialty')
                ->pluck('count', 'specialty')
                ->toArray();
                
            // Top 5 professionals by appointment count
            $topProfessionals = $clinic->professionals()
                ->withCount(['appointments' => function($query) {
                    $query->where('status', 'completed');
                }])
                ->orderBy('appointments_count', 'desc')
                ->take(5)
                ->get(['id', 'name', 'specialty'])
                ->map(function($professional) {
                    return [
                        'id' => $professional->id,
                        'name' => $professional->name,
                        'specialty' => $professional->specialty,
                        'appointments_count' => $professional->appointments_count
                    ];
                });
                
            // Recent additions
            $recentAdditions = $clinic->professionals()
                ->orderBy('created_at', 'desc')
                ->take(5)
                ->get(['id', 'name', 'specialty', 'created_at']);
                
            return response()->json([
                'success' => true,
                'message' => 'Professional statistics retrieved successfully',
                'data' => [
                    'clinic' => [
                        'id' => $clinic->id,
                        'name' => $clinic->name
                    ],
                    'total_professionals' => $clinic->professionals()->count(),
                    'status_counts' => $statusCounts,
                    'type_counts' => $typeCounts,
                    'specialty_counts' => $specialtyCounts,
                    'top_professionals' => $topProfessionals,
                    'recent_additions' => $recentAdditions
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinic professional statistics: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve professional statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unique clinic types.
     *
     * @return JsonResponse
     */
    public function getTypes(): JsonResponse
    {
        try {
            $types = Clinic::whereNotNull('type')
                ->where('type', '!=', '')
                ->distinct()
                ->pluck('type')
                ->sort()
                ->values();

            return response()->json([
                'success' => true,
                'data' => $types
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving clinic types: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve clinic types',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 