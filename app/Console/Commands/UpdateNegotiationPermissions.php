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
            'view_negotiations',
            'create_negotiations',
            'edit_negotiations',
            'submit_negotiations',
            'view_negotiation_history',
            'formalize_negotiations'
        ]);

        $approverRole->syncPermissions([
            'view_negotiations',
            'approve_negotiations',
            'view_negotiation_history'
        ]);

        $adminRole->syncPermissions([
            'view_negotiations',
            'create_negotiations',
            'edit_negotiations',
            'delete_negotiations',
            'submit_negotiations',
            'approve_negotiations',
            'formalize_negotiations',
            'view_negotiation_history'
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