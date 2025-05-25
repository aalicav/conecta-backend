<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfessionalResource;
use App\Models\Professional;
use App\Models\Document;
use App\Models\Phone;
use App\Models\Address;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use App\Http\Controllers\Api\NegotiationController;
use App\Services\NotificationService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;
use App\Notifications\ProfessionalRegistrationSubmitted;
use App\Notifications\ProfessionalRegistrationReviewed;
use App\Notifications\ProfessionalContractLinked;
use Illuminate\Support\Facades\Notification;

class ProfessionalController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:view professionals')->only(['index', 'show']);
        $this->middleware('permission:create professionals')->only(['store']);
        $this->middleware('permission:edit professionals')->only(['update']);
        $this->middleware('permission:delete professionals')->only(['destroy']);
        $this->middleware('permission:approve professionals')->only(['approve']);
    }

    /**
     * Display a listing of professionals.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Professional::query();

        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('professional_type')) {
            $query->where('professional_type', $request->professional_type);
        }

        if ($request->has('specialty')) {
            $query->where('specialty', $request->specialty);
        }

        if ($request->has('clinic_id')) {
            $query->where('clinic_id', $request->clinic_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('cpf', 'like', "%{$search}%")
                  ->orWhere('council_number', 'like', "%{$search}%");
            });
        }

        // Apply role based filtering
        $user = Auth::user();
        if ($user->hasRole('clinic_admin')) {
            $query->where('clinic_id', $user->clinic_id);
        } elseif ($user->hasRole('professional')) {
            $query->where('id', $user->professional_id);
        }

        // Apply sorting
        $sort = $request->sort ?? 'created_at';
        $direction = $request->direction ?? 'desc';
        $query->orderBy($sort, $direction);

        // Load relationships if requested
        if ($request->has('with')) {
            $relations = explode(',', $request->with);
            $allowedRelations = ['clinic', 'phones', 'documents', 'approver', 'user', 'contract'];
            $validRelations = array_intersect($allowedRelations, $relations);
            
            if (!empty($validRelations)) {
                $query->with($validRelations);
            }
        }

        // Pagination
        $perPage = $request->per_page ?? 15;
        $professionals = $query->paginate($perPage);

        return ProfessionalResource::collection($professionals);
    }

    /**
     * Store a newly created professional.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'cpf' => 'required|string|unique:professionals,cpf',
                'birth_date' => 'required|date',
                'gender' => 'nullable|string',
                'professional_type' => 'required|string',
                'council_type' => 'required|string',
                'council_number' => 'required|string',
                'council_state' => 'required|string',
                'specialty' => 'nullable|string',
                'clinic_id' => 'nullable|exists:clinics,id',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'postal_code' => 'nullable|string',
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
                'photo' => 'nullable|image|max:2048',
                'phones' => 'nullable|array',
                'phones.*.number' => 'required|string',
                'phones.*.type' => 'required|string',
                'create_user' => 'nullable|boolean',
                'email' => 'required_if:create_user,true|email|unique:users,email',
                'password' => 'nullable|min:8',
                'specialties' => 'sometimes|array',
                'specialties.*.name' => 'required|string',
                'specialties.*.description' => 'nullable|string',
                'documents' => 'sometimes|array',
                'documents.*.file' => 'required|file|max:10240',
                'documents.*.type' => 'required|string',
                'documents.*.description' => 'nullable|string',
                'send_welcome_email' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle photo upload
            $photoPath = null;
            if ($request->hasFile('photo')) {
                $photoPath = $request->file('photo')->store('professionals', 'public');
            }

            // Create professional
            $professionalData = $request->except(['phones', 'create_user', 'email', 'password', 'photo', 'specialties', 'documents', 'send_welcome_email', 'addresses']);
            $professionalData['photo'] = $photoPath;
            $professionalData['status'] = 'pending';
            
            $professional = Professional::create($professionalData);

            // Create phones if provided
            if ($request->has('phones')) {
                foreach ($request->phones as $phoneData) {
                    $professional->phones()->create([
                        'number' => $phoneData['number'],
                        'type' => $phoneData['type'],
                    ]);
                }
            }

            // Create addresses if provided
            if ($request->has('addresses') && is_array($request->addresses)) {
                foreach ($request->addresses as $addressData) {
                    $professional->addresses()->create([
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
            } else if ($request->has('address')) {
                // Create address from legacy fields if no addresses array is provided
                $professional->addresses()->create([
                    'street' => $request->address,
                    'city' => $request->city,
                    'state' => $request->state,
                    'postal_code' => $request->postal_code,
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude,
                    'is_primary' => true,
                ]);
            }

            // Create specialties if provided using the main specialty field
            if ($request->has('specialties')) {
                // Set the main specialty from the first specialty in the array
                if (isset($request->specialties[0]['name'])) {
                    $professional->update([
                        'specialty' => $request->specialties[0]['name']
                    ]);
                }

                // Additional specialties can be handled in the frontend display
                // since we're using the main specialty field
            }

            // Create documents if provided
            if ($request->has('documents')) {
                foreach ($request->documents as $documentData) {
                    $path = $documentData['file']->store('professionals/documents', 'public');
                    
                    $document = $professional->documents()->create([
                        'name' => $documentData['type'] . ' - ' . $professional->name,
                        'file_path' => $path,
                        'type' => $documentData['type'],
                        'description' => $documentData['description'] ?? null,
                        'uploaded_by' => Auth::id(),
                    ]);
                }
            }

            // Create user account if requested
            $plainPassword = '';
            $professionalUser = null;
            
                // Generate a random password if not provided
                $plainPassword = $request->password ?? Str::random(10);
                
                $professionalUser = User::create([
                    'name' => $professional->name,
                    'email' => $request->email,
                    'password' => bcrypt($plainPassword),
                    'entity_id' => $professional->id,
                    'entity_type' => Professional::class,
                    'is_active' => false,
                ]);

                // Assign professional role
                $role = Role::where('name', 'professional')->first();
                if ($role) {
                    $professionalUser->assignRole($role);
                }

            // Send welcome email with password
            if ($professionalUser) {
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
                    'user' => $professionalUser,
                    'password' => $plainPassword,
                    'loginUrl' => config('app.frontend_url') . '/login',
                    'companyName' => $companyName,
                    'companyAddress' => $companyAddress,
                    'companyCity' => $companyCity,
                    'companyState' => $companyState,
                    'supportEmail' => $supportEmail,
                    'supportPhone' => $supportPhone,
                    'socialMedia' => $socialMedia,
                    'entityType' => 'Profissional',
                    'professional' => $professional
                ], function ($message) use ($professionalUser) {
                    $message->to($professionalUser->email, $professionalUser->name)
                            ->subject('Bem-vindo ao ' . config('app.name') . ' - Detalhes da sua conta');
                });
            }

            // Notify validators about the new professional registration
            app(NotificationService::class)->notifyProfessionalRegistrationSubmitted($professional);

            DB::commit();

            // Load relationships
            $professional->load(['phones', 'clinic', 'user', 'addresses']);

            return response()->json([
                'success' => true,
                'message' => 'Professional created successfully',
                'data' => new ProfessionalResource($professional)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating professional: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create professional',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified professional.
     *
     * @param Professional $professional
     * @return ProfessionalResource|JsonResponse
     */
    public function show(Professional $professional)
    {
        try {
            // Check if user has permission to view this professional
            if (!$this->canAccessProfessional($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view this professional'
                ], 403);
            }

            // Load relationships
            $professional->load([
                'phones', 
                'documents', 
                'approver', 
                'clinic', 
                'user',
                'contract',
                'addresses',
                'appointments' => function($query) {
                    $query->latest()->take(10);
                }
            ]);

            // Load counts
            $professional->loadCount(['appointments']);

            return new ProfessionalResource($professional);
        } catch (\Exception $e) {
            Log::error('Error retrieving professional: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve professional',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function update(Request $request, Professional $professional): JsonResponse
    {
        try {
            // Check if user has permission to update this professional
            if (!$this->canManageProfessional($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to update this professional'
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'cpf' => 'sometimes|string|unique:professionals,cpf,' . $professional->id,
                'birth_date' => 'sometimes|date',
                'gender' => 'nullable|string',
                'professional_type' => 'sometimes|string',
                'council_type' => 'sometimes|string',
                'council_number' => 'sometimes|string',
                'council_state' => 'sometimes|string',
                'specialty' => 'nullable|string',
                'clinic_id' => 'nullable|exists:clinics,id',
                'address' => 'nullable|string',
                'city' => 'nullable|string',
                'state' => 'nullable|string',
                'postal_code' => 'nullable|string',
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
                'photo' => 'nullable|image|max:2048',
                'status' => 'sometimes|in:pending,approved,rejected',
                'is_active' => 'sometimes|boolean',
                'phones' => 'nullable|array',
                'phones.*.id' => 'nullable|exists:phones,id',
                'phones.*.number' => 'required|string',
                'phones.*.type' => 'required|string',
                'documents' => 'sometimes|array',
                'documents.*.file' => 'required|file|max:10240',
                'documents.*.type' => 'required|string',
                'documents.*.description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle photo upload
            if ($request->hasFile('photo')) {
                // Delete old photo if exists
                if ($professional->photo) {
                    Storage::disk('public')->delete($professional->photo);
                }
                $photoPath = $request->file('photo')->store('professionals', 'public');
                $professional->photo = $photoPath;
            }

            // Update professional
            $professional->fill($request->except(['phones', 'photo', 'documents', 'addresses']));
            $professional->save();

            // Update phones if provided
            if ($request->has('phones')) {
                // Get existing phone IDs
                $existingPhoneIds = $professional->phones->pluck('id')->toArray();
                $updatedPhoneIds = collect($request->phones)->pluck('id')->filter()->toArray();
                
                // Delete phones that are not in the updated list
                $phoneIdsToDelete = array_diff($existingPhoneIds, $updatedPhoneIds);
                if (!empty($phoneIdsToDelete)) {
                    Phone::whereIn('id', $phoneIdsToDelete)->delete();
                }
                
                // Update or create phones
                foreach ($request->phones as $phoneData) {
                    if (isset($phoneData['id'])) {
                        Phone::where('id', $phoneData['id'])->update([
                            'number' => $phoneData['number'],
                            'type' => $phoneData['type'],
                        ]);
                    } else {
                        $professional->phones()->create([
                            'number' => $phoneData['number'],
                            'type' => $phoneData['type'],
                        ]);
                    }
                }
            }

            // Update addresses if provided
            if ($request->has('addresses') && is_array($request->addresses)) {
                // Get existing address IDs
                $existingAddressIds = $professional->addresses->pluck('id')->toArray();
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
                        $professional->addresses()->create([
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

            // Update the associated user name if exists
            if ($professional->user) {
                $professional->user->update(['name' => $professional->name]);
            }

            // Update documents if provided
            if ($request->has('documents')) {
                // Não apagar documentos existentes, apenas adicionar novos
                foreach ($request->documents as $documentData) {
                    $path = $documentData['file']->store('professionals/documents', 'public');
                    
                    $document = $professional->documents()->create([
                        'name' => $documentData['type'] . ' - ' . $professional->name,
                        'file_path' => $path,
                        'type' => $documentData['type'],
                        'description' => $documentData['description'] ?? null,
                        'uploaded_by' => Auth::id(),
                    ]);
                }
            }

            DB::commit();

            // Reload professional with relationships
            $professional->load(['phones', 'clinic', 'user', 'addresses']);

            return response()->json([
                'success' => true,
                'message' => 'Professional updated successfully',
                'data' => new ProfessionalResource($professional)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating professional: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update professional',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified professional.
     *
     * @param Professional $professional
     * @return JsonResponse
     */
    public function destroy(Professional $professional): JsonResponse
    {
        try {
            // Check if user has permission to delete this professional
            if (!$this->canManageProfessional($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to delete this professional'
                ], 403);
            }

            // Check if professional has appointments
            if ($professional->appointments()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete professional with existing appointments'
                ], 422);
            }

            DB::beginTransaction();

            // Delete photo if exists
            if ($professional->photo) {
                Storage::disk('public')->delete($professional->photo);
            }

            // Delete phones
            $professional->phones()->delete();

            // Delete addresses
            $professional->addresses()->delete();

            // Delete documents
            $professional->documents()->delete();

            // Delete associated user if exists
            if ($professional->user) {
                $professional->user->delete();
            }

            // Delete professional
            $professional->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Professional deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting professional: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete professional',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Approve a professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function approve(Request $request, Professional $professional): JsonResponse
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'approved' => 'required|boolean',
                'rejection_reason' => 'required_if:approved,false|nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Only allow approving pending professionals
            if ($professional->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only pending professionals can be approved or rejected'
                ], 422);
            }

            $approved = $request->boolean('approved');
            
            $professional->status = $approved ? 'approved' : 'rejected';
            
            if ($approved) {
                $professional->approved_at = now();
                $professional->approved_by = Auth::id();
            } else {
                $professional->rejection_reason = $request->rejection_reason;
            }
            
            $professional->save();

            // Activate the associated user if approved
            if ($approved && $professional->user) {
                $professional->user->update(['is_active' => true]);
            }

            // Send notifications through the notification service
            app(NotificationService::class)->notifyProfessionalRegistrationReviewed(
                $professional,
                $approved,
                $request->rejection_reason
            );

            // Load relationships
            $professional->load(['approver']);

            return response()->json([
                'success' => true,
                'message' => $approved ? 'Professional approved successfully' : 'Professional rejected successfully',
                'data' => new ProfessionalResource($professional)
            ]);
        } catch (\Exception $e) {
            Log::error('Error approving professional: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to approve professional',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload documents for a professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function uploadDocuments(Request $request, Professional $professional): JsonResponse
    {
        try {
            // Check if user has permission to update this professional
            if (!$this->canManageProfessional($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to upload documents for this professional'
                ], 403);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'documents' => 'required|array',
                'documents.*.file' => 'required|file|max:10240',
                'documents.*.type' => 'required|string',
                'documents.*.description' => 'nullable|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $uploadedDocuments = [];

            foreach ($request->documents as $documentData) {
                $path = $documentData['file']->store('professionals/documents', 'public');
                
                $document = $professional->documents()->create([
                    'name' => $documentData['type'] . ' - ' . $professional->name,
                    'file_path' => $path,
                    'type' => $documentData['type'],
                    'description' => $documentData['description'] ?? null,
                    'uploaded_by' => Auth::id(),
                ]);
                
                $uploadedDocuments[] = $document;
            }

            // Load all documents
            $professional->load('documents');

            return response()->json([
                'success' => true,
                'message' => 'Documents uploaded successfully',
                'data' => [
                    'professional' => new ProfessionalResource($professional),
                    'uploaded_documents' => $uploadedDocuments
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error uploading documents: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if the current user can access the professional.
     *
     * @param Professional $professional
     * @return bool
     */
    protected function canAccessProfessional(Professional $professional): bool
    {
        $user = Auth::user();

        // Super admins can access any professional
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Clinic admins can access professionals from their clinic
        if ($user->hasRole('clinic_admin')) {
            return $professional->clinic_id === $user->clinic_id;
        }

        // Professionals can access their own profile
        if ($user->hasRole('professional')) {
            return $user->professional_id === $professional->id;
        }

        // Health plan admins can access all professionals
        if ($user->hasRole('health_plan_admin')) {
            return true;
        }

        return false;
    }

    /**
     * Check if the current user can manage the professional (update, delete, etc.).
     *
     * @param Professional $professional
     * @return bool
     */
    protected function canManageProfessional(Professional $professional): bool
    {
        $user = Auth::user();

        // Super admins can manage any professional
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Clinic admins can manage professionals from their clinic
        if ($user->hasRole('clinic_admin')) {
            return $professional->clinic_id === $user->clinic_id;
        }

        // Professionals can manage their own profile
        if ($user->hasRole('professional')) {
            return $user->professional_id === $professional->id;
        }

        return false;
    }

    /**
     * Update procedures for the professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function updateProcedures(Request $request, Professional $professional): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'procedures' => 'required|array',
                'procedures.*.tuss_id' => 'required|exists:tuss_procedures,id',
                'procedures.*.value' => 'required|numeric|min:0',
                'procedures.*.notes' => 'nullable|string',
                'main_specialty' => 'sometimes|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Update main specialty if provided (this is a qualification detail, not financial)
            if ($request->has('main_specialty')) {
                $professional->update([
                    'specialty' => $request->main_specialty
                ]);
            }

            // Create negotiation for procedures through proper channel
            $negotiationService = app(NotificationService::class);
            $negotiationController = new NegotiationController($negotiationService);
            
            // Prepare negotiation data
            $contractTemplate = \App\Models\ContractTemplate::where('entity_type', 'professional')
                ->where('is_active', true)
                ->first();
            
            if (!$contractTemplate) {
                // Fallback to a generic template
                $contractTemplate = \App\Models\ContractTemplate::where('is_active', true)
                    ->first();
            }
            
            $negotiationData = [
                'entity_type' => Professional::class,
                'entity_id' => $professional->id,
                'title' => 'Negociação - ' . $professional->name,
                'description' => 'Atualização de valores para profissional ' . $professional->name,
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addMonths(3)->format('Y-m-d'),
                'status' => 'draft',
                'contract_template_id' => $contractTemplate->id ?? null,
                'items' => array_map(function($p) {
                    return [
                        'tuss_id' => $p['tuss_id'],
                        'proposed_value' => $p['value'],
                        'notes' => $p['notes'] ?? null,
                    ];
                }, $request->procedures)
            ];

            // Create the negotiation through the proper controller
            $negReq = new Request($negotiationData);
            $response = $negotiationController->store($negReq);
            $negotiation = json_decode($response->getContent(), true);
            
            // Create a value verification for the negotiation if required
            if ($request->has('procedures') && !empty($request->procedures)) {
                $totalValue = array_sum(array_column($request->procedures, 'value'));
                
                if ($totalValue > 0) {
                    $valueVerification = new \App\Models\ValueVerification([
                        'entity_type' => 'negotiation',
                        'entity_id' => $negotiation['data']['id'] ?? null,
                        'original_value' => $totalValue,
                        'notes' => "Valores propostos para procedimentos do profissional {$professional->name}",
                        'requester_id' => Auth::id(),
                        'status' => 'pending'
                    ]);
                    $valueVerification->save();
                    
                    // Notify directors about the new value verification
                    $notificationService = app(NotificationService::class);
                    $notificationService->sendToRole('director', [
                        'title' => 'Nova Verificação de Valor',
                        'body' => "Uma nova verificação de valor foi solicitada para negociação do profissional {$professional->name}.",
                        'action_link' => "/value-verifications/{$valueVerification->id}",
                        'icon' => 'dollar-sign',
                        'channels' => ['system']
                    ]);
                }
            }

            // After successful contract creation and procedure linking, notify through the notification service
            app(NotificationService::class)->notifyProfessionalContractLinked(
                $professional,
                $negotiation['data']['contract'],
                $request->procedures
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Procedimentos enviados para negociação com sucesso',
                'data' => [
                    'professional' => new ProfessionalResource($professional),
                    'negotiation' => $negotiation['data'] ?? null
                ]
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating professional procedures: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update procedures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get contract data for the professional
     * 
     * @param Professional $professional
     * @return JsonResponse
     */
    public function getContractData(Professional $professional): JsonResponse
    {
        try {
            $contracts = $professional->contracts()
                ->with(['signatureRequests', 'creator'])
                ->orderBy('created_at', 'desc')
                ->get();
                
            $latestContract = $professional->contract;
            
            return response()->json([
                'success' => true,
                'message' => 'Contract data retrieved successfully',
                'data' => [
                    'has_signed_contract' => $professional->has_signed_contract,
                    'latest_contract' => $latestContract,
                    'contracts' => $contracts,
                    'professional' => [
                        'id' => $professional->id,
                        'name' => $professional->name
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving professional contract data: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve contract data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the specialties for the professional.
     *
     * @param Professional $professional
     * @return JsonResponse
     */
    public function getSpecialties(Professional $professional): JsonResponse
    {
        try {
            // Obter a especialidade principal
            $mainSpecialty = [
                'name' => $professional->specialty,
                'description' => null,
                'is_primary' => true
            ];

            // Obter especialidades adicionais (podem ser inferidas de outros campos ou informações)
            // Por exemplo, podemos usar as categorias de procedimentos como especialidades adicionais
            $additionalSpecialties = [];
            
            // Agrupar procedimentos por categorias para inferir especialidades
            $procedureCategories = $professional->pricingContracts()
                ->with('procedure')
                ->where('is_active', true)
                ->get()
                ->map(function($contract) {
                    return $contract->procedure->category ?? null;
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            
            foreach ($procedureCategories as $category) {
                if ($category !== $professional->specialty) { // Evitar duplicar a especialidade principal
                    $additionalSpecialties[] = [
                        'name' => $category,
                        'description' => 'Inferido de procedimentos',
                        'is_primary' => false
                    ];
                }
            }

            // Combinar especialidade principal com adicionais
            $specialties = [$mainSpecialty];
            if (!empty($additionalSpecialties)) {
                $specialties = array_merge($specialties, $additionalSpecialties);
            }

            return response()->json([
                'success' => true,
                'message' => 'Specialties retrieved successfully',
                'data' => $specialties,
                'professional' => [
                    'id' => $professional->id,
                    'name' => $professional->name
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving professional specialties: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve specialties',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get the pricing contracts (negotiated procedures) for the professional.
     *
     * @param Professional $professional
     * @return JsonResponse
     */
    public function getProcedures(Professional $professional): JsonResponse
    {
        try {
            $contracts = $professional->pricingContracts()->with('procedure')->where('is_active', true)->get();
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
                'professional' => [
                    'id' => $professional->id,
                    'name' => $professional->name
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving professional procedures: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve procedures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update specialties for the professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @return JsonResponse
     */
    public function updateSpecialties(Request $request, Professional $professional): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'specialties' => 'required|array',
                'specialties.*.name' => 'required|string',
                'specialties.*.description' => 'nullable|string',
                'specialties.*.is_primary' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Encontrar a especialidade principal (definida como primária ou a primeira da lista)
            $primarySpecialty = null;
            foreach ($request->specialties as $specialtyData) {
                if (isset($specialtyData['is_primary']) && $specialtyData['is_primary']) {
                    $primarySpecialty = $specialtyData['name'];
                    break;
                }
            }

            // Se nenhuma especialidade foi marcada como primária, use a primeira da lista
            if (!$primarySpecialty && !empty($request->specialties)) {
                $primarySpecialty = $request->specialties[0]['name'];
            }

            // Atualizar o campo de especialidade principal do profissional
            if ($primarySpecialty) {
                $professional->specialty = $primarySpecialty;
                $professional->save();
            }

            DB::commit();

            // Obter especialidades atualizadas (incluindo principais e inferidas)
            $mainSpecialty = [
                'name' => $professional->specialty,
                'description' => null,
                'is_primary' => true
            ];

            $procedureCategories = $professional->pricingContracts()
                ->with('procedure')
                ->where('is_active', true)
                ->get()
                ->map(function($contract) {
                    return $contract->procedure->category ?? null;
                })
                ->filter()
                ->unique()
                ->values()
                ->toArray();
            
            $additionalSpecialties = [];
            foreach ($procedureCategories as $category) {
                if ($category !== $professional->specialty) {
                    $additionalSpecialties[] = [
                        'name' => $category,
                        'description' => 'Inferido de procedimentos',
                        'is_primary' => false
                    ];
                }
            }

            $specialties = [$mainSpecialty];
            if (!empty($additionalSpecialties)) {
                $specialties = array_merge($specialties, $additionalSpecialties);
            }

            return response()->json([
                'success' => true,
                'message' => 'Specialties updated successfully',
                'data' => [
                    'professional' => new ProfessionalResource($professional),
                    'specialties' => $specialties
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating professional specialties: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update specialties',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a specific document of a professional.
     *
     * @param Request $request
     * @param Professional $professional
     * @param int $documentId
     * @return JsonResponse
     */
    public function deleteDocument(Professional $professional, int $documentId): JsonResponse
    {
        try {
            // Check if user has permission to update this professional
            if (!$this->canManageProfessional($professional)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to manage documents for this professional'
                ], 403);
            }

            // Find the document and check if it belongs to this professional
            $document = $professional->documents()->find($documentId);
            
            if (!$document) {
                return response()->json([
                    'success' => false,
                    'message' => 'Document not found or does not belong to this professional'
                ], 404);
            }

            // Delete the file from storage
            if (Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }

            // Delete the document record
            $document->delete();

            return response()->json([
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting professional document: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete document',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 