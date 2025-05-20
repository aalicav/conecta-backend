<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AddUserManagementPermissions extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Create user management permissions
            $permissions = [
                'view users',
                'create users',
                'edit users',
                'delete users',
                'assign roles',
                'assign permissions',
            ];
            
            foreach ($permissions as $permission) {
                Permission::firstOrCreate(
                    ['name' => $permission, 'guard_name' => 'api']
                );
            }
            
            // Assign these permissions to super_admin role
            $superAdminRole = Role::where('name', 'super_admin')
                ->where('guard_name', 'api')
                ->first();
                
            if ($superAdminRole) {
                $superAdminRole->givePermissionTo($permissions);
            }
        } catch (\Exception $e) {
            // Log error, but don't stop migration
            \Log::error('Error adding user management permissions: ' . $e->getMessage());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Do not remove permissions in down migration to prevent data loss
    }
} 