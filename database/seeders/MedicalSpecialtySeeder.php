<?php

namespace Database\Seeders;

use App\Models\MedicalSpecialty;
use Illuminate\Database\Seeder;

class MedicalSpecialtySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $specialties = [
            [
                'name' => 'Cardiologia',
                'tuss_code' => '2.01.01.07-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Cardiologia',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Clínica Médica',
                'tuss_code' => '2.01.01.01-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Clínica Médica',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Dermatologia',
                'tuss_code' => '2.01.01.10-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Dermatologia',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Ginecologia e Obstetrícia',
                'tuss_code' => '2.01.01.15-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Ginecologia e Obstetrícia',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Ortopedia e Traumatologia',
                'tuss_code' => '2.01.01.28-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Ortopedia e Traumatologia',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Pediatria',
                'tuss_code' => '2.01.01.31-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Pediatria',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Psiquiatria',
                'tuss_code' => '2.01.01.33-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Psiquiatria',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Oftalmologia',
                'tuss_code' => '2.01.01.27-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Oftalmologia',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Neurologia',
                'tuss_code' => '2.01.01.26-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Neurologia',
                'negotiable' => true,
                'active' => true,
            ],
            [
                'name' => 'Endocrinologia',
                'tuss_code' => '2.01.01.12-0',
                'tuss_description' => 'Consulta em consultório (no horário normal ou preestabelecido) - Endocrinologia',
                'negotiable' => true,
                'active' => true,
            ],
        ];

        foreach ($specialties as $specialty) {
            MedicalSpecialty::updateOrCreate(
                ['tuss_code' => $specialty['tuss_code']],
                $specialty
            );
        }
    }
} 