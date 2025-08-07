<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Log;

class RoleController extends Controller
{
    protected $restrictedRoles = ['super_admin', 'plan_admin', 'professional', 'clinic_admin'];

    /**
     * Create a new controller instance.
     */
    public function __construct()
    {
        $this->middleware(['auth:sanctum']);
        $this->middleware('role:super_admin');
    }

    /**
     * Display a listing of roles.
     */
    public function index(Request $request)
    {
        try {
            $query = Role::with('permissions');
            
            // Search functionality
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%");
            }
            
            // Filter by guard
            if ($request->has('guard_name')) {
                $query->where('guard_name', $request->guard_name);
            }
            
            // Pagination
            $perPage = $request->input('per_page', 15);
            $roles = $query->paginate($perPage);
            
            return response()->json([
                'data' => $roles,
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching roles: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to fetch roles',
                'success' => false
            ], 500);
        }
    }

    /**
     * Store a newly created role.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name',
            'guard_name' => 'sometimes|string|max:255',
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

        try {
            $role = Role::create([
                'name' => $request->name,
                'guard_name' => $request->guard_name ?? 'web',
            ]);

            // Assign permissions if provided
            if ($request->has('permissions')) {
                $role->syncPermissions($request->permissions);
            }

            $role->load('permissions');

            return response()->json([
                'message' => 'Role created successfully',
                'data' => $role,
                'success' => true
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating role: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to create role',
                'success' => false
            ], 500);
        }
    }

    /**
     * Display the specified role.
     */
    public function show(Role $role)
    {
        try {
            $role->load('permissions');
            
            return response()->json([
                'data' => $role,
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching role: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to fetch role',
                'success' => false
            ], 500);
        }
    }

    /**
     * Update the specified role.
     */
    public function update(Request $request, Role $role)
    {
        // Check if role is restricted
        if (in_array($role->name, $this->restrictedRoles)) {
            return response()->json([
                'message' => 'Cannot edit system roles',
                'success' => false
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:roles,name,' . $role->id,
            'guard_name' => 'sometimes|string|max:255',
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

        try {
            // Update role data
            if ($request->has('name')) {
                $role->name = $request->name;
            }
            
            if ($request->has('guard_name')) {
                $role->guard_name = $request->guard_name;
            }
            
            $role->save();

            // Update permissions if provided
            if ($request->has('permissions')) {
                $role->syncPermissions($request->permissions);
            }

            $role->load('permissions');

            return response()->json([
                'message' => 'Role updated successfully',
                'data' => $role,
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating role: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update role',
                'success' => false
            ], 500);
        }
    }

    /**
     * Remove the specified role.
     */
    public function destroy(Role $role)
    {
        // Check if role is restricted
        if (in_array($role->name, $this->restrictedRoles)) {
            return response()->json([
                'message' => 'Cannot delete system roles',
                'success' => false
            ], 403);
        }

        try {
            // Check if role is assigned to any users
            $usersWithRole = $role->users()->count();
            if ($usersWithRole > 0) {
                return response()->json([
                    'message' => 'Cannot delete role that is assigned to users',
                    'success' => false
                ], 422);
            }

            $role->delete();

            return response()->json([
                'message' => 'Role deleted successfully',
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting role: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to delete role',
                'success' => false
            ], 500);
        }
    }

    /**
     * Get all available permissions for a role
     */
    public function getPermissions(Role $role)
    {
        try {
            $allPermissions = Permission::all();
            $rolePermissions = $role->permissions->pluck('name')->toArray();
            
            return response()->json([
                'data' => [
                    'all_permissions' => $allPermissions,
                    'role_permissions' => $rolePermissions
                ],
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching role permissions: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to fetch role permissions',
                'success' => false
            ], 500);
        }
    }

    /**
     * Sync permissions for a role
     */
    public function syncPermissions(Request $request, Role $role)
    {
        // Check if role is restricted
        if (in_array($role->name, $this->restrictedRoles)) {
            return response()->json([
                'message' => 'Cannot edit system roles',
                'success' => false
            ], 403);
        }

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

        try {
            $role->syncPermissions($request->permissions);

            return response()->json([
                'message' => 'Role permissions updated successfully',
                'data' => $role->fresh('permissions'),
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error syncing role permissions: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to update role permissions',
                'success' => false
            ], 500);
        }
    }

    /**
     * Get all available permissions
     */
    public function getAllPermissions()
    {
        try {
            $permissions = Permission::all();
            
            return response()->json([
                'data' => $permissions,
                'success' => true
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching permissions: ' . $e->getMessage());
            
            return response()->json([
                'message' => 'Failed to fetch permissions',
                'success' => false
            ], 500);
        }
    }
} 