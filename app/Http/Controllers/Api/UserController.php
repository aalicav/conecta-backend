<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
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
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Mail;

class UserController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware('auth:sanctum');
        $this->middleware('permission:view users')->only(['index', 'show']);
        $this->middleware('permission:create users')->only(['store']);
        $this->middleware('permission:edit users')->only(['update', 'updateProfile']);
        $this->middleware('permission:delete users')->only(['destroy']);
        $this->middleware('permission:manage roles')->only(['assignRole', 'removeRole']);
    }

    /**
     * Display a listing of users.
     *
     * @param Request $request
     * @return AnonymousResourceCollection|JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = User::with(['roles']);
            
            // Aplicar filtros
            if ($request->has('search')) {
                $searchTerm = $request->search;
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('name', 'like', "%{$searchTerm}%")
                      ->orWhere('email', 'like', "%{$searchTerm}%");
                });
            }
            
            if ($request->has('role')) {
                $query->whereHas('roles', function ($q) use ($request) {
                    $q->where('name', $request->role);
                });
            }
            
            if ($request->has('is_active')) {
                $isActive = $request->is_active === 'true' || $request->is_active === '1';
                $query->where('is_active', $isActive);
            }
            
            if ($request->has('entity_type')) {
                $query->where('entity_type', $request->entity_type);
            }
            
            if ($request->has('entity_id')) {
                $query->where('entity_id', $request->entity_id);
            }
            
            // Ordenação
            $sortField = $request->sort_by ?? 'created_at';
            $sortDirection = $request->sort_direction ?? 'desc';
            $query->orderBy($sortField, $sortDirection);
            
            // Paginação
            $perPage = $request->per_page ?? 15;
            $users = $query->paginate($perPage);
            
            return UserResource::collection($users);
        } catch (\Exception $e) {
            Log::error('Error fetching users: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created user.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8',
                'profile_photo' => 'nullable|image|max:2048',
                'roles' => 'sometimes|array',
                'roles.*' => 'exists:roles,name',
                'entity_type' => 'nullable|string',
                'entity_id' => 'nullable|integer',
                'phone' => 'nullable|string|max:20',
                'is_active' => 'boolean',
                'send_welcome_email' => 'sometimes|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle profile photo upload if provided
            $profilePhotoPath = null;
            if ($request->hasFile('profile_photo')) {
                $profilePhotoPath = $request->file('profile_photo')->store('users/profile-photos', 'public');
            }

            // Create user
            $user = new User();
            $user->name = $request->name;
            $user->email = $request->email;
            $user->password = Hash::make($request->password);
            $user->profile_photo = $profilePhotoPath;
            $user->entity_type = $request->entity_type ?? null;
            $user->entity_id = $request->entity_id ?? null;
            $user->phone = $request->phone ?? null;
            $user->is_active = $request->has('is_active') ? $request->is_active : true;
            $user->save();

            // Assign roles if provided
            if ($request->has('roles') && is_array($request->roles)) {
                $user->assignRole($request->roles);
            }

            // Send welcome email with password if requested
            $sendEmail = $request->has('send_welcome_email') ? $request->boolean('send_welcome_email') : true;
            if ($sendEmail) {
                $plainPassword = $request->password;
                
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
                    'user' => $user,
                    'password' => $plainPassword,
                    'loginUrl' => config('app.frontend_url') . '/login',
                    'companyName' => $companyName,
                    'companyAddress' => $companyAddress,
                    'companyCity' => $companyCity,
                    'companyState' => $companyState,
                    'supportEmail' => $supportEmail,
                    'supportPhone' => $supportPhone,
                    'socialMedia' => $socialMedia,
                ], function ($message) use ($user) {
                    $message->to($user->email, $user->name)
                            ->subject('Bem-vindo ao ' . config('app.name') . ' - Detalhes da sua conta');
                });
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User created successfully',
                'data' => new UserResource($user)
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified user.
     *
     * @param User $user
     * @return JsonResponse
     */
    public function show(User $user): JsonResponse
    {
        try {
            // Load relationships
            $user->load(['roles']);

            // Add profile photo URL for frontend display
            if ($user->profile_photo) {
                $user->profile_photo_url = Storage::disk('public')->url($user->profile_photo);
            }

            return response()->json([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified user.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function update(Request $request, User $user): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id)
                ],
                'password' => 'sometimes|string|min:8',
                'profile_photo' => 'nullable|image|max:2048',
                'roles' => 'sometimes|array',
                'roles.*' => 'exists:roles,name',
                'entity_type' => 'nullable|string',
                'entity_id' => 'nullable|integer',
                'phone' => 'nullable|string|max:20',
                'is_active' => 'boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // Handle profile photo upload if provided
            if ($request->hasFile('profile_photo')) {
                // Delete old profile photo if exists
                if ($user->profile_photo) {
                    Storage::disk('public')->delete($user->profile_photo);
                }
                $user->profile_photo = $request->file('profile_photo')->store('users/profile-photos', 'public');
            }

            // Update user fields
            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('email')) {
                $user->email = $request->email;
            }

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }

            if ($request->has('entity_type')) {
                $user->entity_type = $request->entity_type;
            }

            if ($request->has('entity_id')) {
                $user->entity_id = $request->entity_id;
            }

            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }

            if ($request->has('is_active')) {
                $user->is_active = $request->is_active;
            }

            $user->save();

            // Update roles if provided
            if ($request->has('roles') && is_array($request->roles)) {
                $user->syncRoles($request->roles);
            }

            DB::commit();

            // Load relationships
            $user->load(['roles']);

            // Add profile photo URL for frontend display
            if ($user->profile_photo) {
                $user->profile_photo_url = Storage::disk('public')->url($user->profile_photo);
            }

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the authenticated user's profile.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => [
                    'sometimes',
                    'string',
                    'email',
                    'max:255',
                    Rule::unique('users')->ignore($user->id)
                ],
                'current_password' => 'required_with:password|string',
                'password' => 'sometimes|string|min:8|confirmed',
                'profile_photo' => 'nullable|image|max:2048',
                'phone' => 'nullable|string|max:20'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify current password if changing password
            if ($request->has('password') && !Hash::check($request->current_password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }

            DB::beginTransaction();

            // Handle profile photo upload if provided
            if ($request->hasFile('profile_photo')) {
                // Delete old profile photo if exists
                if ($user->profile_photo) {
                    Storage::disk('public')->delete($user->profile_photo);
                }
                $user->profile_photo = $request->file('profile_photo')->store('users/profile-photos', 'public');
            }

            // Update user fields
            if ($request->has('name')) {
                $user->name = $request->name;
            }

            if ($request->has('email')) {
                $user->email = $request->email;
            }

            if ($request->has('password')) {
                $user->password = Hash::make($request->password);
            }

            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }

            $user->save();

            DB::commit();

            // Add profile photo URL for frontend display
            if ($user->profile_photo) {
                $user->profile_photo_url = Storage::disk('public')->url($user->profile_photo);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating profile: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update profile',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified user.
     *
     * @param User $user
     * @return JsonResponse
     */
    public function destroy(User $user): JsonResponse
    {
        try {
            // Não permitir que o usuário exclua a si mesmo
            if (Auth::id() === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot delete your own account'
                ], 422);
            }

            DB::beginTransaction();

            // Delete profile photo if exists
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            // Delete user
            $user->forceDelete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting user: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle user active status.
     *
     * @param User $user
     * @return JsonResponse
     */
    public function toggleActiveStatus(User $user): JsonResponse
    {
        try {
            // Não permitir que o usuário desative a si mesmo
            if (Auth::id() === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot change your own active status'
                ], 422);
            }

            $user->is_active = !$user->is_active;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => $user->is_active ? 'User activated successfully' : 'User deactivated successfully',
                'data' => [
                    'id' => $user->id,
                    'is_active' => $user->is_active
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error toggling user status: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to toggle user status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign a role to a user.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function assignRole(Request $request, User $user): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'role' => 'required|string|exists:roles,name'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($user->hasRole($request->role)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has this role'
                ], 422);
            }

            $user->assignRole($request->role);

            return response()->json([
                'success' => true,
                'message' => 'Role assigned successfully',
                'data' => [
                    'user_id' => $user->id,
                    'roles' => $user->getRoleNames()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error assigning role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove a role from a user.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function removeRole(Request $request, User $user): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'role' => 'required|string|exists:roles,name'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            if (!$user->hasRole($request->role)) {
                return response()->json([
                    'success' => false,
                    'message' => 'User does not have this role'
                ], 422);
            }

            // Verificar se é o último administrador
            if ($request->role === 'admin' || $request->role === 'super_admin') {
                $adminCount = User::role($request->role)->count();
                if ($adminCount <= 1 && $user->hasRole($request->role)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot remove role. At least one user must have this role.'
                    ], 422);
                }
            }

            $user->removeRole($request->role);

            return response()->json([
                'success' => true,
                'message' => 'Role removed successfully',
                'data' => [
                    'user_id' => $user->id,
                    'roles' => $user->getRoleNames()
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing role: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all available roles.
     *
     * @return JsonResponse
     */
    public function getRoles(): JsonResponse
    {
        try {
            $roles = Role::all(['id', 'name']);

            return response()->json([
                'success' => true,
                'data' => $roles
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting roles: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }
} 