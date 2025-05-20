<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class UserManagementController extends Controller
{
    protected $restrictedRoles = ['plan_admin', 'professional', 'clinic_admin'];

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
        $this->middleware('role:super_admin');
    }

    /**
     * Display a listing of users.
     */
    public function index(Request $request)
    {
        $query = User::with('roles', 'permissions');
        
        // Search functionality
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }
        
        // Filter by role
        if ($request->has('role')) {
            $role = $request->role;
            $query->whereHas('roles', function($q) use ($role) {
                $q->where('name', $role);
            });
        }
        
        // Pagination
        $perPage = $request->input('per_page', 15);
        $users = $query->paginate($perPage);
        
        return response()->json([
            'data' => $users,
            'success' => true
        ]);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'sometimes|string|min:8',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($validator->fails()) {
            // Check if the validation error is due to a unique email constraint
            $errors = $validator->errors();
            if ($errors->has('email') && str_contains($errors->first('email'), 'já foi utilizado')) {
                // Check if the email exists in a soft-deleted user
                $existingUser = User::withTrashed()->where('email', $request->email)->whereNotNull('deleted_at')->first();
                
                if ($existingUser) {
                    // Restore the user and update their details
                    $existingUser->restore();
                    $existingUser->name = $request->name;
                    
                    // Generate password if not provided
                    $password = $request->password ?? Str::random(10);
                    $existingUser->password = Hash::make($password);
                    $existingUser->save();
                    
                    // Sync roles if provided
                    if ($request->has('roles')) {
                        // Check for restricted roles
                        foreach ($request->roles as $role) {
                            if (in_array($role, $this->restrictedRoles)) {
                                return response()->json([
                                    'message' => 'Cannot assign restricted roles (plan_admin, professional, clinic_admin)',
                                    'success' => false
                                ], 403);
                            }
                        }
                        
                        $existingUser->syncRoles($request->roles);
                    }
                    
                    // Sync permissions if provided
                    if ($request->has('permissions')) {
                        $existingUser->syncPermissions($request->permissions);
                    }
                    
                    // Send welcome email
                    try {
                        Mail::send('emails.welcome_new_user', [
                            'name' => $existingUser->name,
                            'email' => $existingUser->email,
                            'password' => $password
                        ], function ($message) use ($existingUser) {
                            $message->to($existingUser->email)
                                ->subject('Bem-vindo ao sistema!');
                        });
                    } catch (\Exception $e) {
                        \Log::error('Error sending welcome email: ' . $e->getMessage());
                    }
                    
                    return response()->json([
                        'message' => 'User restored and updated successfully',
                        'data' => $existingUser->load('roles', 'permissions'),
                        'success' => true
                    ], 201);
                }
            }
            
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'success' => false
            ], 422);
        }

        // Check for restricted roles
        if ($request->has('roles')) {
            foreach ($request->roles as $role) {
                if (in_array($role, $this->restrictedRoles)) {
                    return response()->json([
                        'message' => 'Cannot assign restricted roles (plan_admin, professional, clinic_admin)',
                        'success' => false
                    ], 403);
                }
            }
        }

        // Gerar senha aleatória se não for informada
        $password = $request->password ?? Str::random(10);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($password),
        ]);

        // Assign roles if provided
        if ($request->has('roles')) {
            $user->assignRole($request->roles);
        }

        // Assign direct permissions if provided
        if ($request->has('permissions')) {
            $user->givePermissionTo($request->permissions);
        }

        // Enviar e-mail de boas-vindas com a senha
        try {
            Mail::send('emails.welcome_new_user', [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $password
            ], function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Bem-vindo ao sistema!');
            });
        } catch (\Exception $e) {
            // Logar erro, mas não impedir criação
        }

        return response()->json([
            'message' => 'User created successfully',
            'data' => $user->load('roles', 'permissions'),
            'success' => true
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show(User $user)
    {
        $user->load('roles', 'permissions');
        
        return response()->json([
            'data' => $user,
            'success' => true
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $user->id,
            'password' => 'sometimes|string|min:8',
            'roles' => 'sometimes|array',
            'roles.*' => 'string|exists:roles,name',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'success' => false
            ], 422);
        }

        // Check for restricted roles
        if ($request->has('roles')) {
            foreach ($request->roles as $role) {
                if (in_array($role, $this->restrictedRoles)) {
                    return response()->json([
                        'message' => 'Cannot assign restricted roles (plan_admin, professional, clinic_admin)',
                        'success' => false
                    ], 403);
                }
            }
        }

        // Update user data
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        
        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }
        
        $user->save();

        // Update roles if provided
        if ($request->has('roles')) {
            $user->syncRoles($request->roles);
        }

        // Update direct permissions if provided
        if ($request->has('permissions')) {
            $user->syncPermissions($request->permissions);
        }

        return response()->json([
            'message' => 'User updated successfully',
            'data' => $user->fresh(['roles', 'permissions']),
            'success' => true
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy(User $user)
    {
        // Prevent deleting yourself
        if ($user->id === Auth::id()) {
            return response()->json([
                'message' => 'You cannot delete your own account',
                'success' => false
            ], 403);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
            'success' => true
        ]);
    }

    /**
     * Get all available roles
     */
    public function getRoles()
    {
        $roles = Role::where('guard_name', 'api')
            ->whereNotIn('name', $this->restrictedRoles)
            ->get();
            
        return response()->json([
            'data' => $roles,
            'success' => true
        ]);
    }

    /**
     * Get all available permissions
     */
    public function getPermissions()
    {
        $permissions = Permission::where('guard_name', 'api')->get();
        
        return response()->json([
            'data' => $permissions,
            'success' => true
        ]);
    }

    /**
     * Add/Remove role to/from user
     */
    public function updateRoles(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'success' => false
            ], 422);
        }

        // Check for restricted roles
        foreach ($request->roles as $role) {
            if (in_array($role, $this->restrictedRoles)) {
                return response()->json([
                    'message' => 'Cannot assign restricted roles (plan_admin, professional, clinic_admin)',
                    'success' => false
                ], 403);
            }
        }

        $user->syncRoles($request->roles);

        return response()->json([
            'message' => 'User roles updated successfully',
            'data' => $user->fresh('roles'),
            'success' => true
        ]);
    }

    /**
     * Add/Remove direct permissions to/from user
     */
    public function updatePermissions(Request $request, User $user)
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'success' => false
            ], 422);
        }

        $user->syncPermissions($request->permissions);

        return response()->json([
            'message' => 'User permissions updated successfully',
            'data' => $user->fresh('permissions'),
            'success' => true
        ]);
    }
} 