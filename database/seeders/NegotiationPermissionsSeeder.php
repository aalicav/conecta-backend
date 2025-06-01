<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class NegotiationPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $permissions = [
            'view_negotiations' => 'View negotiations',
            'create_negotiations' => 'Create negotiations',
            'edit_negotiations' => 'Edit negotiations',
            'delete_negotiations' => 'Delete negotiations',
            'submit_negotiations' => 'Submit negotiations for approval',
            'approve_negotiations' => 'Approve negotiations',
            'formalize_negotiations' => 'Formalize negotiations',
            'view_negotiation_history' => 'View negotiation history',
        ];

        foreach ($permissions as $name => $description) {
            Permission::create([
                'name' => $name,
                'description' => $description,
                'guard_name' => 'web'
            ]);
        }

        // Get or create roles
        $commercialRole = Role::firstOrCreate(['name' => 'commercial']);
        $approverRole = Role::firstOrCreate(['name' => 'approver']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Assign permissions to roles
        $commercialRole->givePermissionTo([
            'view_negotiations',
            'create_negotiations',
            'edit_negotiations',
            'submit_negotiations',
            'view_negotiation_history',
            'formalize_negotiations'
        ]);

        $approverRole->givePermissionTo([
            'view_negotiations',
            'approve_negotiations',
            'view_negotiation_history'
        ]);

        $adminRole->givePermissionTo([
            'view_negotiations',
            'create_negotiations',
            'edit_negotiations',
            'delete_negotiations',
            'submit_negotiations',
            'approve_negotiations',
            'formalize_negotiations',
            'view_negotiation_history'
        ]);
    }
} 