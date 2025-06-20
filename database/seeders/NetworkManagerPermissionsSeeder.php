<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class NetworkManagerPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Get or create network_manager role
        $networkManagerRole = Role::firstOrCreate(['name' => 'network_manager']);

        // Get all existing permissions
        $allPermissions = Permission::all();

        if ($allPermissions->isEmpty()) {
            $this->command->warn('No permissions found in the system.');
            return;
        }

        // Give all permissions to network_manager role
        $networkManagerRole->givePermissionTo($allPermissions);

        $this->command->info("All {$allPermissions->count()} permissions have been assigned to network_manager role.");
        
        // List the permissions that were assigned
        $this->command->line('Permissions assigned:');
        foreach ($allPermissions as $permission) {
            $this->command->line("- {$permission->name}");
        }
    }
} 