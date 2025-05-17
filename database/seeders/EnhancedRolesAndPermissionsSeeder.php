<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnhancedRolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * This seeder implements the detailed role requirements specified by Dr. Ítalo
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Additional permissions needed for the enhanced role system
        $additionalPermissions = [
            // Negotiation approval workflow permissions
            'approve_negotiation_commercial',
            'approve_negotiation_financial',
            'approve_negotiation_management',
            'approve_negotiation_legal',
            'approve_negotiation_direction',
            'reject_negotiation_commercial',
            'reject_negotiation_financial',
            'reject_negotiation_management',
            'reject_negotiation_legal',
            'reject_negotiation_direction',
            
            // Contract approval workflow permissions
            'approve contracts final',
            'legal review contracts',
            'commercial review contracts',
            
            // Exception handling permissions
            'request scheduling exceptions',
            'approve scheduling exceptions',
            
            // System management
            'manage user accounts',
            'manage backups',
            'configure system',
            
            // Contract and negotiation specific permissions
            'manage contract templates',
            'manage contract values',
            'manage extemporaneous negotiations',
            'initiate contract approval',
            
            // Financial specific permissions
            'manage billing',
            'manage refunds',
            'attach payment proof',
            'view financial dashboard',
            'export financial data',
            
            // Operational permissions
            'confirm appointments',
            'manage patient flow',
            'register patient arrival',
            
            // Dashboard and reporting
            'view executive dashboard',
            'view operational dashboard',
            'view commercial dashboard',
            'view financial dashboard',
            
            // External access
            'portal health plan access',
            'portal provider access',
        ];

        // Get existing permissions
        $existingPermissions = Permission::where('guard_name', 'api')->pluck('name')->toArray();
        
        // Create new permissions safely
        foreach ($additionalPermissions as $permission) {
            try {
                if (!in_array($permission, $existingPermissions)) {
                    Permission::create(['name' => $permission, 'guard_name' => 'api']);
                    $this->command->info("Created permission: $permission");
                } else {
                    $this->command->comment("Permission already exists: $permission");
                }
            } catch (\Exception $e) {
                $this->command->warn("Could not create permission: $permission - " . $e->getMessage());
                Log::warning("Could not create permission: $permission - " . $e->getMessage());
            }
        }

        // All permissions for reference
        $allPermissions = Permission::where('guard_name', 'api')->get();

        // 1. Administrador do Sistema (already exists as super_admin)
        // Just making sure it has all permissions 
        $adminRole = Role::where('name', 'super_admin')->where('guard_name', 'api')->first();
        if ($adminRole) {
            $adminRole->syncPermissions($allPermissions);
            $this->command->info("Updated super_admin role with all permissions");
        }
        
        // 2. Direção (Director) - Dr. Ítalo
        $directorPermissions = [
            'approve_negotiation_direction',
            'reject_negotiation_direction',
            'approve contracts final',
            'approve scheduling exceptions',
            'view executive dashboard',
            'view financial dashboard',
            'view commercial dashboard',
            'view reports',
            'view financial reports',
            'export reports',
            'view contracts',
            'sign contracts',
            'view negotiations',
            'view health plans',
            'view clinics',
            'view professionals',
            'view appointments',
            'view payments',
        ];
        $this->createOrUpdateRole('director', $directorPermissions);
        
        // 3. Equipe Comercial (Commercial Team) - Mirelle and team
        $commercialManagerPermissions = [
            'approve_negotiation_commercial',
            'reject_negotiation_commercial',
            'view health plans',
            'create health plans',
            'edit health plans',
            'view clinics',
            'create clinics',
            'edit clinics',
            'view professionals',
            'create professionals',
            'edit professionals',
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'submit negotiations',
            'cancel negotiations',
            'respond to negotiations',
            'counter negotiations',
            'view contracts',
            'create contracts',
            'edit contracts',
            'sign contracts',
            'initiate contract approval',
            'commercial review contracts',
            'manage contract values',
            'manage extemporaneous negotiations',
            'manage contract templates',
            'view commercial dashboard',
            'view reports',
            'generate contracts from negotiations',
        ];
        $this->createOrUpdateRole('commercial_manager', $commercialManagerPermissions);
        
        // 4. Equipe Jurídica (Legal Team)
        $legalManagerPermissions = [
            'approve_negotiation_legal',
            'reject_negotiation_legal',
            'view contracts',
            'edit contracts',
            'legal review contracts',
            'manage contract templates',
            'view health plans',
            'view clinics',
            'view professionals',
            'view documents',
        ];
        $this->createOrUpdateRole('legal_manager', $legalManagerPermissions);
        
        // 5. Equipe Financeira (Financial Team) - Aline and Paula
        $financialManagerPermissions = [
            'approve_negotiation_financial',
            'reject_negotiation_financial',
            'view payments',
            'process payments',
            'view financial reports',
            'export financial data',
            'manage billing',
            'manage refunds',
            'attach payment proof',
            'view financial dashboard',
            'view appointments',
            'view patients',
            'view health plans',
            'view clinics',
            'view professionals',
            'manage financials',
            'apply gloss',
        ];
        $this->createOrUpdateRole('financial_manager', $financialManagerPermissions);

        // 6. Comitê de Gestão (Management Committee)
        $managementCommitteePermissions = [
            'approve_negotiation_management',
            'reject_negotiation_management',
            'view executive dashboard',
            'view financial dashboard',
            'view commercial dashboard',
            'view reports',
            'view financial reports',
            'export reports',
            'view contracts',
            'view negotiations',
            'view health plans',
            'view clinics',
            'view professionals',
            'view appointments',
            'view payments',
        ];
        $this->createOrUpdateRole('management_committee', $managementCommitteePermissions);
        
        // 7. Operadora de Saúde (Health Plan Portal)
        $healthPlanPortalPermissions = [
            'portal health plan access',
            'view appointments',
            'view patients',
            'view reports',
            'sign contracts',
        ];
        $this->createOrUpdateRole('health_plan_portal', $healthPlanPortalPermissions);
        
        // 8. Estabelecimento de Saúde/Profissional (Provider Portal)
        $providerPortalPermissions = [
            'portal provider access',
            'view appointments',
            'confirm appointments',
            'confirm presence',
            'complete appointment',
            'sign contracts',
        ];
        $this->createOrUpdateRole('provider_portal', $providerPortalPermissions);
    }
    
    /**
     * Create a role or update its permissions if it already exists
     */
    private function createOrUpdateRole(string $roleName, $permissions): void
    {
        try {
            $role = Role::where('name', $roleName)->where('guard_name', 'api')->first();
            
            if (!$role) {
                $role = Role::create(['name' => $roleName, 'guard_name' => 'api']);
                $this->command->info("Created role: $roleName");
            } else {
                $this->command->comment("Role already exists: $roleName, updating permissions");
            }
            
            // Get Permission objects from permission names
            $permissionObjects = Permission::whereIn('name', $permissions)->where('guard_name', 'api')->get();
            
            $role->syncPermissions($permissionObjects);
            $this->command->info("Assigned " . $permissionObjects->count() . " permissions to role: $roleName");
        } catch (\Exception $e) {
            $this->command->error("Error processing role $roleName: " . $e->getMessage());
            Log::error("Error processing role $roleName: " . $e->getMessage());
        }
    }
} 