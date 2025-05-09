<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SampleUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Sample users for each role - one user per role for testing
        $users = [
            [
                'name' => 'Dr. Ítalo',
                'email' => 'italo@example.com',
                'password' => 'password',
                'role' => 'director'
            ],
            [
                'name' => 'Mirelle (Comercial)',
                'email' => 'mirelle@example.com',
                'password' => 'password',
                'role' => 'commercial'
            ],
            [
                'name' => 'Jurídico',
                'email' => 'juridico@example.com',
                'password' => 'password',
                'role' => 'legal'
            ],
            [
                'name' => 'Lorena (Operacional)',
                'email' => 'lorena@example.com',
                'password' => 'password',
                'role' => 'operational'
            ],
            [
                'name' => 'Aline (Financeiro)',
                'email' => 'aline@example.com',
                'password' => 'password',
                'role' => 'financial'
            ],
            [
                'name' => 'Paula (Financeiro)',
                'email' => 'paula@example.com',
                'password' => 'password',
                'role' => 'financial'
            ],
            [
                'name' => 'Alisson (Admin)',
                'email' => 'alisson@example.com',
                'password' => 'password',
                'role' => 'super_admin'
            ],
            [
                'name' => 'Portal Operadora',
                'email' => 'portal.plano@example.com',
                'password' => 'password',
                'role' => 'health_plan_portal'
            ],
            [
                'name' => 'Portal Prestador',
                'email' => 'portal.prestador@example.com',
                'password' => 'password',
                'role' => 'provider_portal'
            ],
        ];

        foreach ($users as $userData) {
            // Skip if user already exists
            if (User::where('email', $userData['email'])->exists()) {
                $this->command->info("User {$userData['email']} already exists. Skipping.");
                continue;
            }

            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
            ]);

            $user->assignRole($userData['role']);

            $this->command->info("Created user: {$userData['name']} with role: {$userData['role']}");
        }
    }
} 