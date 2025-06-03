<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // All permissions
        $permissions = [
            // Health Plan permissions
            'view health plans',
            'create health plans',
            'edit health plans',
            'delete health plans',
            'approve health plans',
            'view health plan details',
            'view health plan documents',
            'view health plan contracts',
            'view health plan procedures',
            'view health plan solicitations',
            'view health plan financial data',
            
            // Novas permissões para endereços e telefones
            'manage health plan addresses',
            'view health plan addresses',
            'manage health plan phones',
            'view health plan phones',
            'manage user addresses',
            'view user addresses',
            'manage user phones',
            'view user phones',

            // Clinic permissions
            'view clinics',
            'create clinics',
            'edit clinics',
            'delete clinics',
            'approve clinics',

            // Professional permissions
            'view professionals',
            'create professionals',
            'edit professionals',
            'delete professionals',
            'approve professionals',

            // Professional procedures permissions
            'view professional procedures',
            'create professional procedures',
            'edit professional procedures',
            'delete professional procedures',
            'manage professional procedures',

            // Negotiation permissions
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'submit negotiations',
            'cancel negotiations',
            'respond to negotiations',
            'counter negotiations',
            'approve negotiations',
            'generate contracts from negotiations',

            // Patient permissions
            'view patients',
            'create patients',
            'edit patients',
            'delete patients',

            // Solicitation permissions
            'view solicitations',
            'create solicitations',
            'edit solicitations',
            'delete solicitations',

            // Appointment permissions
            'view appointments',
            'create appointments',
            'edit appointments',
            'delete appointments',
            'confirm presence',
            'complete appointment',

            // Contract permissions
            'view contracts',
            'create contracts',
            'edit contracts',
            'delete contracts',
            'sign contracts',

            // Payment permissions
            'view payments',
            'process payments',
            'manage financials',
            'apply gloss',

            // Report permissions
            'view reports',
            'view financial reports',
            'export reports',

            // System settings permissions
            'view settings',
            'edit settings',

            // Audit log permissions
            'view audit logs',
            'export audit logs',
            
            // User management permissions
            'view users',
            'create users',
            'edit users',
            'delete users',
            'assign roles',
            'assign permissions',
        ];

        // Get existing permissions
        $existingPermissions = Permission::where('guard_name', 'api')->pluck('name')->toArray();
        
        // Create new permissions only
        foreach ($permissions as $permission) {
            if (!in_array($permission, $existingPermissions)) {
                Permission::create(['name' => $permission, 'guard_name' => 'api']);
            }
        }

        // Check if roles already exist, create if they don't
        $this->createOrUpdateRole('super_admin', Permission::all());
        
        // Adicionar novas permissões aos papéis existentes
        $planAdminPermissions = [
            'view health plans',
            'view health plan details',
            'view health plan documents',
            'view health plan contracts',
            'view health plan procedures',
            'view health plan solicitations',
            'view health plan financial data',
            'manage health plan addresses',
            'view health plan addresses',
            'manage health plan phones',
            'view health plan phones',
            'manage user addresses',
            'view user addresses',
            'manage user phones',
            'view user phones',
            'view patients',
            'create patients',
            'edit patients',
            'view solicitations',
            'create solicitations',
            'edit solicitations',
            'view appointments',
            'view contracts',
            'sign contracts',
            'view payments',
            'view reports',
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'submit negotiations',
            'cancel negotiations',
            'approve negotiations',
            'generate contracts from negotiations',
        ];
        
        $this->createOrUpdateRole('plan_admin', $planAdminPermissions);

        // Legal Representative Role - Adicionar permissões de visualização
        $legalRepPermissions = [
            'view health plan details',
            'view health plan documents',
            'view health plan contracts',
            'view health plan financial data',
            'view health plan addresses',
            'view health plan phones',
            'view contracts',
            'sign contracts',
            'view negotiations',
            'view payments',
        ];
        
        $this->createOrUpdateRole('legal_representative', $legalRepPermissions);

        // Operational Representative Role - Adicionar permissões de visualização
        $operationalRepPermissions = [
            'view health plan details',
            'view health plan documents',
            'view health plan procedures',
            'view health plan solicitations',
            'view health plan addresses',
            'view health plan phones',
            'view solicitations',
            'view appointments',
            'view contracts',
            'view negotiations',
        ];
        
        $this->createOrUpdateRole('operational_representative', $operationalRepPermissions);
        
        $this->createOrUpdateRole('clinic_admin', [
            'view clinics',
            'edit clinics',
            'view professionals',
            'create professionals',
            'edit professionals',
            'view professional procedures',
            'create professional procedures',
            'edit professional procedures',
            'manage professional procedures',
            'view appointments',
            'confirm presence',
            'complete appointment',
            'view contracts',
            'sign contracts',
            'view payments',
            'view reports',
            'view negotiations',
            'respond to negotiations',
            'counter negotiations',
        ]);
        
        $this->createOrUpdateRole('professional', [
            'view appointments',
            'confirm presence',
            'complete appointment',
            'view contracts',
            'sign contracts',
            'view payments',
            'view professional procedures',
            'view negotiations',
            'respond to negotiations',
            'counter negotiations',
        ]);

        // Health Plan User permissions - Adicionar permissões de endereço e telefone
        $planUserPermissions = [
            'view appointments',
            'list appointments',
            'create solicitations',
            'edit solicitations',
            'list solicitations',
            'view health plan details',
            'view user addresses',
            'manage user addresses',
            'view user phones',
            'manage user phones',
        ];

        // Create health plan user role
        $planUserRole = Role::where('name', 'plan_user')->first();
        if (!$planUserRole) {
            $planUserRole = Role::create(['name' => 'plan_user', 'guard_name' => 'api']);
        }
        $planUserRole->syncPermissions($planUserPermissions);
    }
    
    /**
     * Create a role or update its permissions if it already exists
     */
    private function createOrUpdateRole(string $roleName, $permissions): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
        
        if (!$role) {
            $role = Role::create(['name' => $roleName, 'guard_name' => 'api']);
        }
        
        // Mantém as permissões existentes e adiciona as novas
        $existingPermissions = $role->permissions->pluck('name')->toArray();
        $newPermissions = array_unique(array_merge($existingPermissions, is_array($permissions) ? $permissions : $permissions->pluck('name')->toArray()));
        
        $role->syncPermissions($newPermissions);
    }
} 