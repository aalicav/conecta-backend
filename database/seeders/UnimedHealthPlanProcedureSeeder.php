<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HealthPlanProcedure;
use App\Models\HealthPlan;
use App\Models\MedicalSpecialty;
use App\Models\TussProcedure;
use Illuminate\Support\Facades\DB;

class UnimedHealthPlanProcedureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $healthPlanId = 15; // ID do UNIMED
        
        // Verificar se o plano de saúde existe
        $healthPlan = HealthPlan::find($healthPlanId);
        if (!$healthPlan) {
            $this->command->error("Plano de saúde com ID {$healthPlanId} não encontrado.");
            return;
        }

        // Buscar o procedimento TUSS 10101012 (Consulta em consultório)
        $tussProcedure = TussProcedure::where('code', '10101012')->first();
        if (!$tussProcedure) {
            $this->command->error("Procedimento TUSS com código 10101012 não encontrado.");
            return;
        }

        $this->command->info("Iniciando seed das tabelas de valores do UNIMED...");

        // Dados do UNIMED baseados no CSV
        $unimedProcedures = [
            // PARAÍBA - POMBAL
            ['Paraíba', 'POMBAL', 'CARDIOLOGISTA', 475, 0],
            ['Paraíba', 'POMBAL', 'DERMATOLOGISTA', 398, 0],
            ['Paraíba', 'POMBAL', 'ENDOCRINOLOGIA', 440, 0],
            ['Paraíba', 'POMBAL', 'GASTROENTEROLOGIA', 402, 0],
            ['Paraíba', 'POMBAL', 'GASTROENTEROLOGIA PEDIATRICA', 546, 0],
            ['Paraíba', 'POMBAL', 'GERIATRIA', 470, 0],
            ['Paraíba', 'POMBAL', 'GINECOLOGIA', 395, 0],
            ['Paraíba', 'POMBAL', 'NEUROLOGIA', 503, 0],
            ['Paraíba', 'POMBAL', 'PEDIATRIA', 395, 0],
            ['Paraíba', 'POMBAL', 'PNEUMOLOGIA', 640, 0],
            ['Paraíba', 'POMBAL', 'REUMATOLOGIA', 502, 0],

            // PARAÍBA - PATOS
            ['Paraíba', 'PATOS', 'ENDOCRINOLOGIA PEDIATRICA', 590, 0],
            ['Paraíba', 'PATOS', 'GASTROENTEROLOGIA', 340, 0],
            ['Paraíba', 'PATOS', 'GERIATRIA', 491, 0],
            ['Paraíba', 'PATOS', 'NEUROLOGIA', 440, 0],
            ['Paraíba', 'PATOS', 'NEUROLOGIA PEDIATRICA', 640, 0],
            ['Paraíba', 'PATOS', 'PEDIATRIA', 435, 0],
            ['Paraíba', 'PATOS', 'PNEUMOLOGIA', 493, 0],
            ['Paraíba', 'PATOS', 'PNEUMOLOGIA PEDIATRICA', 495, 0],
            ['Paraíba', 'PATOS', 'PSIQUIATRIA', 541, 0],
            ['Paraíba', 'PATOS', 'REUMATOLOGIA', 440, 0],

            // PARAÍBA - CAJAZEIRAS
            ['Paraíba', 'CAJAZEIRAS', 'CIRURGIA VASCULAR', 395, 0],
            ['Paraíba', 'CAJAZEIRAS', 'DERMATOLOGIA', 435, 0],
            ['Paraíba', 'CAJAZEIRAS', 'ENDOCRINOLOGIA', 440, 0],
            ['Paraíba', 'CAJAZEIRAS', 'GERIATRIA', 640, 0],
            ['Paraíba', 'CAJAZEIRAS', 'NEUROLOGIA', 490, 0],
            ['Paraíba', 'CAJAZEIRAS', 'NEUROLOGIA PEDIATRICA', 645, 0],
            ['Paraíba', 'CAJAZEIRAS', 'NUTROLOGIA (MÉDICO)', 495, 0],
            ['Paraíba', 'CAJAZEIRAS', 'OTORRINOLARINGOLOGIA', 392, 0],
            ['Paraíba', 'CAJAZEIRAS', 'PEDIATRIA', 341, 0],
            ['Paraíba', 'CAJAZEIRAS', 'PNEUMOLOGIA', 493, 0],
            ['Paraíba', 'CAJAZEIRAS', 'PSIQUIATRIA', 493, 0],
            ['Paraíba', 'CAJAZEIRAS', 'UROLOGIA', 391, 0],

            // PARAÍBA - SOUSA
            ['Paraíba', 'SOUSA', 'CARDIOLOGIA', 370, 0],
            ['Paraíba', 'SOUSA', 'DERMATOLOGIA', 410, 0],
            ['Paraíba', 'SOUSA', 'ENDOCRINOLOGIA', 441, 0],
            ['Paraíba', 'SOUSA', 'ENDOCRINOLOGIA PEDIATRICA', 512, 0],
            ['Paraíba', 'SOUSA', 'GASTROENTEROLOGIA', 370, 0],
            ['Paraíba', 'SOUSA', 'GASTROENTEROLOGIA PEDIATRICA', 490, 0],
            ['Paraíba', 'SOUSA', 'GERIATRIA', 402, 0],
            ['Paraíba', 'SOUSA', 'GINECOLOGIA E OBSTETRÍCIA', 341, 0],
            ['Paraíba', 'SOUSA', 'NEUROLOGIA', 490, 0],
            ['Paraíba', 'SOUSA', 'NEUROLOGIA PEDIATRICA', 842, 0],
            ['Paraíba', 'SOUSA', 'PEDIATRIA', 342, 0],
            ['Paraíba', 'SOUSA', 'PNEUMOLOGIA', 440, 0],
            ['Paraíba', 'SOUSA', 'PNEUMOLOGIA PEDIATRICA', 540, 0],
            ['Paraíba', 'SOUSA', 'PSIQUIATRIA', 591, 0],
            ['Paraíba', 'SOUSA', 'REUMATOLOGIA', 440, 0],

            // PARAÍBA - CAMPINA GRANDE
            ['Paraíba', 'CAMPINA GRANDE', 'ENDOCRINOLOGIA PEDIATRICA', 549, 0],
            ['Paraíba', 'CAMPINA GRANDE', 'GASTROENTEROLOGIA PEDIATRICA', 579, 0],
            ['Paraíba', 'CAMPINA GRANDE', 'GERIATRIA', 349, 0],
            ['Paraíba', 'CAMPINA GRANDE', 'NEUROLOGIA PEDIATRICA', 549, 0],
            ['Paraíba', 'CAMPINA GRANDE', 'NUTROLOGIA (MÉDICO)', 649, 0],
            ['Paraíba', 'CAMPINA GRANDE', 'PNEUMOLOGIA PEDIATRICA', 592, 0],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($unimedProcedures as $procedure) {
            [$state, $city, $specialty, $price, $appointments] = $procedure;

            // Mapear especialidade para o nome correto
            $mappedSpecialty = $this->mapSpecialtyName($specialty);
            
            // Buscar especialidade médica existente
            $medicalSpecialty = MedicalSpecialty::where('name', $mappedSpecialty)->first();
            
            if (!$medicalSpecialty) {
                $this->command->warn("Especialidade '{$specialty}' não encontrada. Mapeada para: '{$mappedSpecialty}'");
                continue;
            }

            // Verificar se já existe um procedimento para este plano, especialidade, estado e cidade
            $existingProcedure = HealthPlanProcedure::where([
                'health_plan_id' => $healthPlanId,
                'medical_specialty_id' => $medicalSpecialty->id,
                'state' => $state,
                'city' => $city,
            ])->first();

            if (!$existingProcedure) {
                HealthPlanProcedure::create([
                    'health_plan_id' => $healthPlanId,
                    'tuss_procedure_id' => $tussProcedure->id,
                    'medical_specialty_id' => $medicalSpecialty->id,
                    'price' => $price,
                    'state' => $state,
                    'city' => $city,
                    'is_active' => true,
                    'start_date' => now(),
                    'notes' => "Criado automaticamente via seeder. Agendamentos: {$appointments}",
                    'created_by' => 1, // Assumindo que o usuário admin tem ID 1
                ]);
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->command->info("UNIMED: {$created} procedimentos criados, {$skipped} já existiam.");
    }

    /**
     * Mapeia nomes de especialidades dos CSVs para os nomes corretos no banco
     */
    private function mapSpecialtyName(string $specialty): string
    {
        $specialty = trim($specialty);
        
        // Mapeamento de especialidades
        $mapping = [
            // Cardiologia
            'CARDIOLOGISTA' => 'Cardiologia',
            'CARDIOLOGIA' => 'Cardiologia',
            
            // Dermatologia
            'DERMATOLOGISTA' => 'Dermatologia',
            'DERMATOLOGIA' => 'Dermatologia',
            
            // Endocrinologia
            'ENDOCRINOLOGIA' => 'Endocrinologia',
            'ENDOCRINOLOGIA PEDIATRICA' => 'Endocrinologia Pediátrica',
            
            // Gastroenterologia
            'GASTROENTEROLOGIA' => 'Gastroenterologia',
            'GASTROENTEROLOGIA PEDIATRICA' => 'Gastroenterologia Pediátrica',
            
            // Geriatria
            'GERIATRIA' => 'Geriatria',
            
            // Ginecologia
            'GINECOLOGIA' => 'Ginecologia e Obstetrícia',
            'GINECOLOGIA E OBSTETRÍCIA' => 'Ginecologia e Obstetrícia',
            
            // Neurologia
            'NEUROLOGIA' => 'Neurologia',
            'NEUROLOGIA PEDIATRICA' => 'Neurologia Pediátrica',
            
            // Pediatria
            'PEDIATRIA' => 'Pediatria',
            
            // Pneumologia
            'PNEUMOLOGIA' => 'Pneumologia',
            'PNEUMOLOGIA PEDIATRICA' => 'Pneumologia Pediátrica',
            
            // Psiquiatria
            'PSIQUIATRIA' => 'Psiquiatria',
            
            // Reumatologia
            'REUMATOLOGIA' => 'Reumatologia',
            
            // Cirurgia Vascular
            'CIRURGIA VASCULAR' => 'Cirurgia Vascular',
            
            // Nutrologia
            'NUTROLOGIA (MÉDICO)' => 'Nutrologia',
            
            // Otorrinolaringologia
            'OTORRINOLARINGOLOGIA' => 'Otorrinolaringologia',
            
            // Urologia
            'UROLOGIA' => 'Urologia',
        ];

        return $mapping[$specialty] ?? $specialty;
    }
}
