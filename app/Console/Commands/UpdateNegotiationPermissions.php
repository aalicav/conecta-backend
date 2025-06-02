<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UpdateNegotiationPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'permissions:update-negotiations';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the permissions for negotiations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating negotiation permissions...');

        // Create permissions if they don't exist
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
            Permission::firstOrCreate(
                ['name' => $name],
                ['description' => $description]
            );
        }

        // Get or create roles
        $commercialRole = Role::firstOrCreate(['name' => 'commercial']);
        $approverRole = Role::firstOrCreate(['name' => 'approver']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Sync permissions to roles
        $commercialRole->syncPermissions([
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'submit negotiations',
            'view negotiation history',
            'formalize negotiations'
        ]);

        $approverRole->syncPermissions([
            'view negotiations',
            'approve negotiations',
            'view negotiation history'
        ]);

        $adminRole->syncPermissions([
            'view negotiations',
            'create negotiations',
            'edit negotiations',
            'delete negotiations',
            'submit negotiations',
            'approve negotiations',
            'formalize negotiations',
            'view negotiation history'
        ]);

        $this->info('Negotiation permissions have been updated successfully!');
        
        // Show current permissions for each role
        $this->info("\nCurrent permissions for roles:");
        
        $this->info("\nCommercial role permissions:");
        foreach ($commercialRole->permissions as $permission) {
            $this->line("- {$permission->name}");
        }
        
        $this->info("\nApprover role permissions:");
        foreach ($approverRole->permissions as $permission) {
            $this->line("- {$permission->name}");
        }
        
        $this->info("\nAdmin role permissions:");
        foreach ($adminRole->permissions as $permission) {
            $this->line("- {$permission->name}");
        }
    }
} 