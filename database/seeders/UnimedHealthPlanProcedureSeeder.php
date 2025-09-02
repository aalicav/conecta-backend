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
            // PB - POMBAL
            ['PB', 'POMBAL', 'CARDIOLOGISTA', 475, 0],
            ['PB', 'POMBAL', 'DERMATOLOGISTA', 398, 0],
            ['PB', 'POMBAL', 'ENDOCRINOLOGIA', 440, 0],
            ['PB', 'POMBAL', 'GASTROENTEROLOGIA', 402, 0],
            ['PB', 'POMBAL', 'GASTROENTEROLOGIA PEDIATRICA', 546, 0],
            ['PB', 'POMBAL', 'GERIATRIA', 470, 0],
            ['PB', 'POMBAL', 'GINECOLOGIA', 395, 0],
            ['PB', 'POMBAL', 'NEUROLOGIA', 503, 0],
            ['PB', 'POMBAL', 'PEDIATRIA', 395, 0],
            ['PB', 'POMBAL', 'PNEUMOLOGIA', 640, 0],
            ['PB', 'POMBAL', 'REUMATOLOGIA', 502, 0],

            // PB - PATOS
            ['PB', 'PATOS', 'ENDOCRINOLOGIA PEDIATRICA', 590, 0],
            ['PB', 'PATOS', 'GASTROENTEROLOGIA', 340, 0],
            ['PB', 'PATOS', 'GERIATRIA', 491, 0],
            ['PB', 'PATOS', 'NEUROLOGIA', 440, 0],
            ['PB', 'PATOS', 'NEUROLOGIA PEDIATRICA', 640, 0],
            ['PB', 'PATOS', 'PEDIATRIA', 435, 0],
            ['PB', 'PATOS', 'PNEUMOLOGIA', 493, 0],
            ['PB', 'PATOS', 'PNEUMOLOGIA PEDIATRICA', 495, 0],
            ['PB', 'PATOS', 'PSIQUIATRIA', 541, 0],
            ['PB', 'PATOS', 'REUMATOLOGIA', 440, 0],

            // PB - CAJAZEIRAS
            ['PB', 'CAJAZEIRAS', 'CIRURGIA VASCULAR', 395, 0],
            ['PB', 'CAJAZEIRAS', 'DERMATOLOGIA', 435, 0],
            ['PB', 'CAJAZEIRAS', 'ENDOCRINOLOGIA', 440, 0],
            ['PB', 'CAJAZEIRAS', 'GERIATRIA', 640, 0],
            ['PB', 'CAJAZEIRAS', 'NEUROLOGIA', 490, 0],
            ['PB', 'CAJAZEIRAS', 'NEUROLOGIA PEDIATRICA', 645, 0],
            ['PB', 'CAJAZEIRAS', 'NUTROLOGIA (MÉDICO)', 495, 0],
            ['PB', 'CAJAZEIRAS', 'OTORRINOLARINGOLOGIA', 392, 0],
            ['PB', 'CAJAZEIRAS', 'PEDIATRIA', 341, 0],
            ['PB', 'CAJAZEIRAS', 'PNEUMOLOGIA', 493, 0],
            ['PB', 'CAJAZEIRAS', 'PSIQUIATRIA', 493, 0],
            ['PB', 'CAJAZEIRAS', 'UROLOGIA', 391, 0],

            // PB - SOUSA
            ['PB', 'SOUSA', 'CARDIOLOGIA', 370, 0],
            ['PB', 'SOUSA', 'DERMATOLOGIA', 410, 0],
            ['PB', 'SOUSA', 'ENDOCRINOLOGIA', 441, 0],
            ['PB', 'SOUSA', 'ENDOCRINOLOGIA PEDIATRICA', 512, 0],
            ['PB', 'SOUSA', 'GASTROENTEROLOGIA', 370, 0],
            ['PB', 'SOUSA', 'GASTROENTEROLOGIA PEDIATRICA', 490, 0],
            ['PB', 'SOUSA', 'GERIATRIA', 402, 0],
            ['PB', 'SOUSA', 'GINECOLOGIA E OBSTETRÍCIA', 341, 0],
            ['PB', 'SOUSA', 'NEUROLOGIA', 490, 0],
            ['PB', 'SOUSA', 'NEUROLOGIA PEDIATRICA', 842, 0],
            ['PB', 'SOUSA', 'PEDIATRIA', 342, 0],
            ['PB', 'SOUSA', 'PNEUMOLOGIA', 440, 0],
            ['PB', 'SOUSA', 'PNEUMOLOGIA PEDIATRICA', 540, 0],
            ['PB', 'SOUSA', 'PSIQUIATRIA', 591, 0],
            ['PB', 'SOUSA', 'REUMATOLOGIA', 440, 0],

            // PB - CAMPINA GRANDE
            ['PB', 'CAMPINA GRANDE', 'ENDOCRINOLOGIA PEDIATRICA', 549, 0],
            ['PB', 'CAMPINA GRANDE', 'GASTROENTEROLOGIA PEDIATRICA', 579, 0],
            ['PB', 'CAMPINA GRANDE', 'GERIATRIA', 349, 0],
            ['PB', 'CAMPINA GRANDE', 'NEUROLOGIA PEDIATRICA', 549, 0],
            ['PB', 'CAMPINA GRANDE', 'NUTROLOGIA (MÉDICO)', 649, 0],
            ['PB', 'CAMPINA GRANDE', 'PNEUMOLOGIA PEDIATRICA', 592, 0],
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
