<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ExtemporaneousNegotiationPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $permissions = [
            // Visualização
            'view_extemporaneous_negotiations' => 'Ver negociações extemporâneas',
            'list_extemporaneous_negotiations' => 'Listar negociações extemporâneas',
            
            // Criação e edição
            'create_extemporaneous_negotiations' => 'Criar negociações extemporâneas',
            'edit_extemporaneous_negotiations' => 'Editar negociações extemporâneas',
            
            // Aprovação
            'approve_extemporaneous_negotiations' => 'Aprovar negociações extemporâneas',
            'reject_extemporaneous_negotiations' => 'Rejeitar negociações extemporâneas',
            
            // Formalização
            'formalize_extemporaneous_negotiations' => 'Formalizar negociações extemporâneas',
            'manage_addendums' => 'Gerenciar aditivos contratuais',
            
            // Cancelamento
            'cancel_extemporaneous_negotiations' => 'Cancelar negociações extemporâneas',
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
        $networkManagerRole = Role::firstOrCreate(['name' => 'network_manager']);
        $adminRole = Role::firstOrCreate(['name' => 'admin']);

        // Assign permissions to commercial role (Equipe Comercial)
        $commercialRole->givePermissionTo([
            'view_extemporaneous_negotiations',
            'list_extemporaneous_negotiations',
            'create_extemporaneous_negotiations',
            'edit_extemporaneous_negotiations',
            'formalize_extemporaneous_negotiations',
            'manage_addendums'
        ]);

        // Assign permissions to network manager role (Alçada Superior)
        $networkManagerRole->givePermissionTo([
            'view_extemporaneous_negotiations',
            'list_extemporaneous_negotiations',
            'approve_extemporaneous_negotiations',
            'reject_extemporaneous_negotiations',
            'cancel_extemporaneous_negotiations'
        ]);

        // Assign all permissions to admin role
        $adminRole->givePermissionTo(array_keys($permissions));
    }
} 