<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\HealthPlanProcedure;
use App\Models\HealthPlan;
use App\Models\MedicalSpecialty;
use App\Models\TussProcedure;
use Illuminate\Support\Facades\DB;

class GeapHealthPlanProcedureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $healthPlanId = 30; // ID do GEAP
        
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

        $this->command->info("Iniciando seed das tabelas de valores do GEAP...");

        // Dados do GEAP baseados no CSV
        $geapProcedures = [
            // CE - JUAZEIRO DO NORTE
            ['CE', 'JUAZEIRO DO NORTE', 'PNEUMO', 470, 12],
            ['CE', 'JUAZEIRO DO NORTE', 'PNEUMO Ped', 490, 222],
            ['CE', 'JUAZEIRO DO NORTE', 'ENDOCRINO PED', 472, 160],
            ['CE', 'JUAZEIRO DO NORTE', 'ENDOCRINO', 430, 114],
            ['CE', 'JUAZEIRO DO NORTE', 'NEURO PED', 640, 195],
            ['CE', 'JUAZEIRO DO NORTE', 'GASTRO PED', 470, 18],
            ['CE', 'JUAZEIRO DO NORTE', 'REUMATO', 350, 6],
            ['CE', 'JUAZEIRO DO NORTE', 'GERIATRIA', 420, 23],
            ['CE', 'JUAZEIRO DO NORTE', 'Alergologista', 540, 0],
            ['CE', 'JUAZEIRO DO NORTE', 'Nutrólogo Pediátrico', 540, 0],
            ['CE', 'JUAZEIRO DO NORTE', 'Proctologista', 480, 0],
            ['CE', 'JUAZEIRO DO NORTE', 'Infectologista', 450, 0],

            // CE - IGUATU
            ['CE', 'IGUATU', 'OTORRINO', 400, 52],
            ['CE', 'IGUATU', 'PSIQUIATRA PED', 585, 80],
            ['CE', 'IGUATU', 'ALERGOLOGISTA', 540, 28],
            ['CE', 'IGUATU', 'NUTROLOGO', 490, 15],
            ['CE', 'IGUATU', 'OFTALMO PED', 550, 18],
            ['CE', 'IGUATU', 'NEURO PED', 650, 130],
            ['CE', 'IGUATU', 'Endocrinologista', 730, 0],
            ['CE', 'IGUATU', 'DERMATO', 420, 21],

            // CE - ICÓ
            ['CE', 'ICÓ', 'PEDIATRA', 400, 24],
            ['CE', 'ICÓ', 'DERMATO', 410, 12],

            // CE - MAURITI
            ['CE', 'MAURITI', 'DERMATOLOGIA', 400, 1],
            ['CE', 'MAURITI', 'ENDOCRINOLOGIA', 400, 1],
            ['CE', 'MAURITI', 'GERIATRIA', 400, 1],
            ['CE', 'MAURITI', 'PNEUMOLOGISTA', 450, 1],

            // CE - SOBRAL
            ['CE', 'SOBRAL', 'NEUROLOGISTA', 595, 48],
            ['CE', 'SOBRAL', 'ENDOCRINO PED', 540, 62],
            ['CE', 'SOBRAL', 'REUMATO', 540, 45],
            ['CE', 'SOBRAL', 'ALERGOLOGISTA', 545, 31],
            ['CE', 'SOBRAL', 'ENDOCRINO', 540, 340],
            ['CE', 'SOBRAL', 'GASTRO', 400, 12],
            ['CE', 'SOBRAL', 'GINECOLOGISTA', 436, 26],
            ['CE', 'SOBRAL', 'Dermatologista', 430, 0],
            ['CE', 'SOBRAL', 'Neurologista Pediátrico', 750, 0],
            ['CE', 'SOBRAL', 'Otorrinolaringologista', 540, 0],
            ['CE', 'SOBRAL', 'Pneumologista', 650, 0],
            ['CE', 'SOBRAL', 'Psiquiatra', 580, 0],
            ['CE', 'SOBRAL', 'Infectologista', 650, 0],
            ['CE', 'SOBRAL', 'Nutrologo', 670, 0],
            ['CE', 'SOBRAL', 'Gatropediatra', 850, 0],
            ['CE', 'SOBRAL', 'Proctologista', 500, 0],
            ['CE', 'SOBRAL', 'Pneumologista Pediátrico', 650, 0],

            // CE - TIANGUÁ
            ['CE', 'TIANGUÁ', 'PSIQUIATRA', 460, 95],
            ['CE', 'TIANGUÁ', 'ENDOCRINO', 540, 104],
            ['CE', 'TIANGUÁ', 'OTORRINO', 520, 58],
            ['CE', 'TIANGUÁ', 'NEURO PED', 640, 146],
            ['CE', 'TIANGUÁ', 'Proctologista', 480, 0],

            // CE - OUTRAS CIDADES
            ['CE', 'NOVA RUSSAS', 'Otorrinolaringologista', 540, 0],
            ['CE', 'RUSSAS', 'Dermatologista', 480, 0],
            ['CE', 'CRATEÚ', 'Otorrinolaringologista', 480, 0],
            ['CE', 'ACARAU', 'NEUROPED', 650, 0],

            // RN - MOSSORÓ
            ['RN', 'MOSSORÓ', 'NEUROLOGISTA', 540, 2],
            ['RN', 'MOSSORÓ', 'GINECOLOGISTA', 350, 2],
            ['RN', 'MOSSORÓ', 'NUTROLOGO', 480, 5],

            // RR - BOA VISTA
            ['RR', 'BOA VISTA', 'CARDIO PED', 550, 0],
            ['RR', 'BOA VISTA', 'CARDIO', 440, 9],
            ['RR', 'BOA VISTA', 'OFTALMO', 300, 17],
            ['RR', 'BOA VISTA', 'PEDIATRA', 400, 13],
            ['RR', 'BOA VISTA', 'PSIQUIATRA', 600, 14],
            ['RR', 'BOA VISTA', 'PSIQUIATRA PED', 600, 1],
            ['RR', 'BOA VISTA', 'NEURO', 575, 3],
            ['RR', 'BOA VISTA', 'NEURO PED', 690, 0],
            ['RR', 'BOA VISTA', 'OTORRINO', 410, 4],
            ['RR', 'BOA VISTA', 'GASTRO', 440, 19],
            ['RR', 'BOA VISTA', 'ENDOCRINO', 470, 6],
            ['RR', 'BOA VISTA', 'DERMATO', 520, 26],
            ['RR', 'BOA VISTA', 'GINECOLOGISTA', 500, 18],
            ['RR', 'BOA VISTA', 'COLOPROCTOLOGISTA', 620, 2],
            ['RR', 'BOA VISTA', 'Ortopedista', 430, 0],
            ['RR', 'BOA VISTA', 'USG de Mamas', 380, 0],

            // PE - RECIFE
            ['PE', 'RECIFE', 'Psiquiatra', 650, 0],
            ['PE', 'RECIFE', 'Nutrólogo', 650, 0],

            // PE - CARUARU
            ['PE', 'CARUARU', 'DERMATOLOGIA', 350, 1],
            ['PE', 'CARUARU', 'ENDOCRINOLOGIA', 350, 0],
            ['PE', 'CARUARU', 'GERIATRIA', 400, 0],
            ['PE', 'CARUARU', 'NEUROPEDIATRA', 585, 2],
            ['PE', 'CARUARU', 'PNEUMOLOGISTA', 370, 0],
            ['PE', 'CARUARU', 'PEDIATRA', 320, 0],

            // PE - OUTRAS CIDADES
            ['PE', 'CARPINA', 'DERMATOLOGIA', 350, 0],
            ['PE', 'CARPINA', 'ENDOCRINOLOGIA', 350, 0],
            ['PE', 'CARPINA', 'GERIATRIA', 400, 0],
            ['PE', 'CARPINA', 'NEUROPEDIATRA', 500, 1],
            ['PE', 'CARPINA', 'PNEUMOLOGISTA', 370, 0],

            ['PE', 'GARANHUNS', 'DERMATOLOGIA', 350, 0],
            ['PE', 'GARANHUNS', 'ENDOCRINOLOGIA', 350, 0],
            ['PE', 'GARANHUNS', 'GERIATRIA', 450, 0],
            ['PE', 'GARANHUNS', 'NEUROPEDIATRA', 500, 0],
            ['PE', 'GARANHUNS', 'PNEUMOLOGISTA', 400, 0],

            ['PE', 'PETROLINA', 'DERMATOLOGIA', 420, 0],
            ['PE', 'PETROLINA', 'ENDOCRINOLOGIA', 420, 0],
            ['PE', 'PETROLINA', 'GERIATRIA', 400, 0],
            ['PE', 'PETROLINA', 'NEUROPEDIATRA', 600, 4],
            ['PE', 'PETROLINA', 'PNEUMOLOGISTA', 400, 0],

            ['PE', 'GRAVATÁ', 'DERMATOLOGIA', 350, 0],
            ['PE', 'GRAVATÁ', 'ENDOCRINOLOGIA', 350, 0],
            ['PE', 'GRAVATÁ', 'GERIATRIA', 400, 0],
            ['PE', 'GRAVATÁ', 'NEUROPEDIATRA', 500, 0],
            ['PE', 'GRAVATÁ', 'PNEUMOLOGISTA', 430, 0],

            ['PE', 'ESCADA', 'DERMATOLOGIA', 350, 0],
            ['PE', 'ESCADA', 'ENDOCRINOLOGIA', 350, 0],
            ['PE', 'ESCADA', 'GERIATRIA', 400, 0],
            ['PE', 'ESCADA', 'NEUROPEDIATRA', 500, 0],

            ['PE', 'ARCOVERDE', 'DERMATOLOGIA', 480, 1],
            ['PE', 'ARCOVERDE', 'ENDOCRINOLOGIA', 480, 0],
            ['PE', 'ARCOVERDE', 'GERIATRIA', 480, 0],
            ['PE', 'ARCOVERDE', 'NEUROPEDIATRA', 550, 0],
            ['PE', 'ARCOVERDE', 'PNEUMOLOGISTA', 480, 0],

            ['PE', 'SERRA TALHADA', 'DERMATOLOGIA', 480, 3],
            ['PE', 'SERRA TALHADA', 'ENDOCRINOLOGIA', 480, 0],
            ['PE', 'SERRA TALHADA', 'GERIATRIA', 480, 0],
            ['PE', 'SERRA TALHADA', 'NEUROPEDIATRA', 550, 0],
            ['PE', 'SERRA TALHADA', 'PNEUMOLOGISTA', 480, 0],

            // PI - TERESINA
            ['PI', 'TERESINA', 'Psiquiatra', 650, 0],
            ['PI', 'TERESINA', 'Neurologista ADULTO', 550, 0],
            ['PI', 'TERESINA', 'Neurologista Pediátrico', 650, 0],
            ['PI', 'TERESINA', 'Endocrinologista Pediatra', 650, 0],

            // PI - PARNAÍBA
            ['PI', 'PARNAÍBA', 'Gastroenterologia', 580, 0],
            ['PI', 'PARNAÍBA', 'Psiquiatra', 490, 0],
            ['PI', 'PARNAÍBA', 'Endocrinologista', 540, 0],

            // PB - JOÃO PESSOA
            ['PB', 'JOÃO PESSOA', 'Psiquiatra', 480, 0],

            // PB - SOUSA
            ['PB', 'SOUSA', 'Neurologista Pediátrico', 600, 0],

            // MA - IMPERATRIZ
            ['MA', 'IMPERATRIZ', 'Endocrinologista', 450, 0],
            ['MA', 'IMPERATRIZ', 'Psiquiatra', 720, 0],
            ['MA', 'IMPERATRIZ', 'Neurologista Pediátrico', 600, 0],

            // MA - SANTA INÊS
            ['MA', 'SANTA INÊS', 'Pediatra', 480, 0],
            ['MA', 'SANTA INÊS', 'Neuropeditra', 680, 0],

            // MT - SINOP
            ['MT', 'SINOP', 'Gastroenterologia Ped', 650, 0],

            // MT - ARIPUANÃ
            ['MT', 'ARIPUANÃ', 'Pediatria', 550, 0],

            // PA - MARABÁ
            ['PA', 'MARABÁ', 'Neurologista Pediátrico', 950, 0],
            ['PA', 'MARABÁ', 'Oftalmologista', 390, 0],
            ['PA', 'MARABÁ', 'Neurologista Adulto', 630, 0],
            ['PA', 'MARABÁ', 'Pediatra', 650, 0],

            // PA - TUCUMÃ (Serviços de terapia)
            ['PA', 'TUCUMÃ', 'PSICOLOGIA', 0, 3],
            ['PA', 'TUCUMÃ', 'PSICOPEDAGOGIA', 0, 2],
            ['PA', 'TUCUMÃ', 'PSICOMOTRICIDADE', 0, 1],
            ['PA', 'TUCUMÃ', 'FONOTERAPIA', 0, 3],
            ['PA', 'TUCUMÃ', 'TERAPIA OCUPACIOAL', 0, 3],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($geapProcedures as $procedure) {
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

        $this->command->info("GEAP: {$created} procedimentos criados, {$skipped} já existiam.");
    }

    /**
     * Mapeia nomes de especialidades dos CSVs para os nomes corretos no banco
     */
    private function mapSpecialtyName(string $specialty): string
    {
        $specialty = trim($specialty);
        
        // Mapeamento de especialidades
        $mapping = [
            // Pneumologia
            'PNEUMO' => 'Pneumologia',
            'PNEUMO Ped' => 'Pneumologia Pediátrica',
            'PNEUMOLOGISTA' => 'Pneumologia',
            'PNEUMOLOGIA' => 'Pneumologia',
            'PNEUMOLOGIA PEDIATRICA' => 'Pneumologia Pediátrica',
            'Pneumologista' => 'Pneumologia',
            'Pneumologista Pediátrico' => 'Pneumologia Pediátrica',
            
            // Endocrinologia
            'ENDOCRINO PED' => 'Endocrinologia Pediátrica',
            'ENDOCRINO' => 'Endocrinologia',
            'ENDOCRINOLOGIA' => 'Endocrinologia',
            'ENDOCRINOLOGIA PEDIATRICA' => 'Endocrinologia Pediátrica',
            'Endocrinologista' => 'Endocrinologia',
            'Endocrinologista Pediatra' => 'Endocrinologia Pediátrica',
            'Endocrinologista Pediátrico' => 'Endocrinologia Pediátrica',
            
            // Neurologia
            'NEURO PED' => 'Neurologia Pediátrica',
            'NEURO' => 'Neurologia',
            'NEUROLOGISTA' => 'Neurologia',
            'NEUROLOGIA' => 'Neurologia',
            'NEUROLOGIA PEDIATRICA' => 'Neurologia Pediátrica',
            'NEUROPED' => 'Neurologia Pediátrica',
            'NEUROPEDIATRA' => 'Neurologia Pediátrica',
            'Neurologista' => 'Neurologia',
            'Neurologista ADULTO' => 'Neurologia',
            'Neurologista Pediátrico' => 'Neurologia Pediátrica',
            'Neuropeditra' => 'Neurologia Pediátrica',
            
            // Gastroenterologia
            'GASTRO PED' => 'Gastroenterologia Pediátrica',
            'GASTRO' => 'Gastroenterologia',
            'GASTROENTEROLOGIA' => 'Gastroenterologia',
            'GASTROENTEROLOGIA PEDIATRICA' => 'Gastroenterologia Pediátrica',
            'Gatropediatra' => 'Gastroenterologia Pediátrica',
            'Gastroenterologia Ped' => 'Gastroenterologia Pediátrica',
            
            // Reumatologia
            'REUMATO' => 'Reumatologia',
            'REUMATOLOGIA' => 'Reumatologia',
            
            // Geriatria
            'GERIATRIA' => 'Geriatria',
            
            // Alergologia
            'Alergologista' => 'Alergologia',
            'ALERGOLOGISTA' => 'Alergologia',
            'ALERGOLOGIA' => 'Alergologia',
            
            // Nutrologia
            'Nutrólogo Pediátrico' => 'Nutrologia Pediátrica',
            'NUTROLOGO' => 'Nutrologia',
            'NUTROLOGIA (MÉDICO)' => 'Nutrologia',
            'Nutrólogo' => 'Nutrologia',
            
            // Proctologia
            'Proctologista' => 'Coloproctologia',
            'COLOPROCTOLOGISTA' => 'Coloproctologia',
            
            // Infectologia
            'Infectologista' => 'Infectologia',
            'INFECTOLOGIA' => 'Infectologia',
            
            // Otorrinolaringologia
            'OTORRINO' => 'Otorrinolaringologia',
            'Otorrinolaringologista' => 'Otorrinolaringologia',
            
            // Psiquiatria
            'PSIQUIATRA PED' => 'Psiquiatria Pediátrica',
            'PSIQUIATRA' => 'Psiquiatria',
            'PSIQUIATRIA' => 'Psiquiatria',
            'Psiquiatra' => 'Psiquiatria',
            
            // Oftalmologia
            'OFTALMO PED' => 'Oftalmologia Pediátrica',
            'OFTALMO' => 'Oftalmologia',
            'Oftalmologista' => 'Oftalmologia',
            
            // Dermatologia
            'DERMATO' => 'Dermatologia',
            'DERMATOLOGIA' => 'Dermatologia',
            'Dermatologista' => 'Dermatologia',
            
            // Pediatria
            'PEDIATRA' => 'Pediatria',
            'PEDIATRIA' => 'Pediatria',
            
            // Ginecologia
            'GINECOLOGISTA' => 'Ginecologia e Obstetrícia',
            'GINECOLOGIA' => 'Ginecologia e Obstetrícia',
            'GINECOLOGIA E OBSTETRÍCIA' => 'Ginecologia e Obstetrícia',
            
            // Cardiologia
            'CARDIO PED' => 'Cardiologia Pediátrica',
            'CARDIO' => 'Cardiologia',
            'CARDIOLOGISTA' => 'Cardiologia',
            'CARDIOLOGIA' => 'Cardiologia',
            
            // Ortopedia
            'Ortopedista' => 'Ortopedia e Traumatologia',
            
            // Cirurgia Vascular
            'CIRURGIA VASCULAR' => 'Cirurgia Vascular',
            
            // Urologia
            'UROLOGIA' => 'Urologia',
            
            // Clínica Médica
            'CLÍNICA MÉDICA' => 'Clínica Médica',
            
            // Psicologia
            'PSICOLOGIA' => 'Psicologia',
            
            // Psicopedagogia
            'PSICOPEDAGOGIA' => 'Psicopedagogia',
            
            // Psicomotricidade
            'PSICOMOTRICIDADE' => 'Psicomotricidade',
            
            // Fonoterapia
            'FONOTERAPIA' => 'Fonoterapia',
            
            // Terapia Ocupacional
            'TERAPIA OCUPACIOAL' => 'Terapia Ocupacional',
            
            // Exames
            'USG de Mamas' => 'USG ABSOMINAL',
            'USG TRANSVAGINAL' => 'USG TRANSVAGINAL',
            
            // Exames Laboratoriais
            'EXAME DE IMAGEM' => 'EXAME DE IMAGEM',
            'Exames Laboratoriais' => 'Exames Laboratoriais',
            
            // Geneticista
            'Geneticista' => 'Geneticista',
        ];

        return $mapping[$specialty] ?? $specialty;
    }
}
