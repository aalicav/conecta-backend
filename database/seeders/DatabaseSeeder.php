<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\App;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call([
            RolesAndPermissionsSeeder::class,
            EnhancedRolesAndPermissionsSeeder::class, // Dr. Ítalo's specified roles and permissions
            SystemSettingSeeder::class,
            HealthPlanDocumentTypesSeeder::class,
            EntityDocumentTypesSeeder::class, // Tipos de documentos para profissionais e clínicas
            // TussProcedureSeeder::class,
            // ContractTemplateSeeder::class,
            // NegotiationExampleSeeder::class,
        ]);        
        
        // Create default admin user
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
        ]);

        $user->assignRole('super_admin');
        
        // Only seed sample users in local or development environments
        if (App::environment(['local', 'development'])) {
            $this->call(SampleUsersSeeder::class);
        }
    }
}
