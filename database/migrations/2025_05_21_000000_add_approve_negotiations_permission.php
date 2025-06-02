<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Log;

class AddApproveNegotiationsPermission extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Create the permission if it doesn't exist
            $permission = Permission::firstOrCreate(
                ['name' => 'approve negotiations', 'guard_name' => 'api']
            );
            
            // Add the permission to relevant roles
            $roles = ['super_admin', 'director', 'commercial_manager', 'legal_manager', 'financial_manager', 'plan_admin'];
            
            foreach ($roles as $roleName) {
                $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
                if ($role) {
                    $role->givePermissionTo($permission);
                    Log::info("Added approve negotiations permission to {$roleName} role");
                }
            }
            
            Log::info('Added approve negotiations permission successfully');
        } catch (\Exception $e) {
            Log::error('Error adding approve negotiations permission: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            // Find and delete the permission
            $permission = Permission::where(['name' => 'approve negotiations', 'guard_name' => 'api'])->first();
            
            if ($permission) {
                // Remove from all roles first
                $roles = Role::all();
                foreach ($roles as $role) {
                    if ($role->hasPermissionTo($permission)) {
                        $role->revokePermissionTo($permission);
                    }
                }
                
                // Delete the permission
                $permission->delete();
                Log::info('Removed approve negotiations permission');
            }
        } catch (\Exception $e) {
            Log::error('Error removing approve negotiations permission: ' . $e->getMessage());
        }
    }
} 