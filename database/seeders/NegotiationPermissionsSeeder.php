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
            'view negotiations' => 'View negotiations',
            'create negotiations' => 'Create negotiations',
            'edit negotiations' => 'Edit negotiations',
            'delete negotiations' => 'Delete negotiations',
            'submit negotiations' => 'Submit negotiations for approval',
            'approve negotiations' => 'Approve negotiations',
            'formalize negotiations' => 'Formalize negotiations',
            'view negotiation history' => 'View negotiation history',
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
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'submit negotiations',
            'view negotiation history',
            'formalize negotiations'
        ]);

        $approverRole->givePermissionTo([
            'view negotiations',
            'approve negotiations',
            'view negotiation history'
        ]);

        $adminRole->givePermissionTo([
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'delete negotiations',
            'submit negotiations',
            'approve negotiations',
            'formalize negotiations',
            'view negotiation history'
        ]);
    }
} 