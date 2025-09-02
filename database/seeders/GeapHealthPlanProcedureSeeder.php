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
            // CEARÁ - JUAZEIRO DO NORTE
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'PNEUMO', 470, 12],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'PNEUMO Ped', 490, 222],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'ENDOCRINO PED', 472, 160],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'ENDOCRINO', 430, 114],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'NEURO PED', 640, 195],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'GASTRO PED', 470, 18],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'REUMATO', 350, 6],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'GERIATRIA', 420, 23],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'Alergologista', 540, 0],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'Nutrólogo Pediátrico', 540, 0],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'Proctologista', 480, 0],
            ['CEARÁ', 'JUAZEIRO DO NORTE', 'Infectologista', 450, 0],

            // CEARÁ - IGUATU
            ['CEARÁ', 'IGUATU', 'OTORRINO', 400, 52],
            ['CEARÁ', 'IGUATU', 'PSIQUIATRA PED', 585, 80],
            ['CEARÁ', 'IGUATU', 'ALERGOLOGISTA', 540, 28],
            ['CEARÁ', 'IGUATU', 'NUTROLOGO', 490, 15],
            ['CEARÁ', 'IGUATU', 'OFTALMO PED', 550, 18],
            ['CEARÁ', 'IGUATU', 'NEURO PED', 650, 130],
            ['CEARÁ', 'IGUATU', 'Endocrinologista', 730, 0],
            ['CEARÁ', 'IGUATU', 'DERMATO', 420, 21],

            // CEARÁ - ICÓ
            ['CEARÁ', 'ICÓ', 'PEDIATRA', 400, 24],
            ['CEARÁ', 'ICÓ', 'DERMATO', 410, 12],

            // CEARÁ - MAURITI
            ['CEARÁ', 'MAURITI', 'DERMATOLOGIA', 400, 1],
            ['CEARÁ', 'MAURITI', 'ENDOCRINOLOGIA', 400, 1],
            ['CEARÁ', 'MAURITI', 'GERIATRIA', 400, 1],
            ['CEARÁ', 'MAURITI', 'PNEUMOLOGISTA', 450, 1],

            // CEARÁ - SOBRAL
            ['CEARÁ', 'SOBRAL', 'NEUROLOGISTA', 595, 48],
            ['CEARÁ', 'SOBRAL', 'ENDOCRINO PED', 540, 62],
            ['CEARÁ', 'SOBRAL', 'REUMATO', 540, 45],
            ['CEARÁ', 'SOBRAL', 'ALERGOLOGISTA', 545, 31],
            ['CEARÁ', 'SOBRAL', 'ENDOCRINO', 540, 340],
            ['CEARÁ', 'SOBRAL', 'GASTRO', 400, 12],
            ['CEARÁ', 'SOBRAL', 'GINECOLOGISTA', 436, 26],
            ['CEARÁ', 'SOBRAL', 'Dermatologista', 430, 0],
            ['CEARÁ', 'SOBRAL', 'Neurologista Pediátrico', 750, 0],
            ['CEARÁ', 'SOBRAL', 'Otorrinolaringologista', 540, 0],
            ['CEARÁ', 'SOBRAL', 'Pneumologista', 650, 0],
            ['CEARÁ', 'SOBRAL', 'Psiquiatra', 580, 0],
            ['CEARÁ', 'SOBRAL', 'Infectologista', 650, 0],
            ['CEARÁ', 'SOBRAL', 'Nutrologo', 670, 0],
            ['CEARÁ', 'SOBRAL', 'Gatropediatra', 850, 0],
            ['CEARÁ', 'SOBRAL', 'Proctologista', 500, 0],
            ['CEARÁ', 'SOBRAL', 'Pneumologista Pediátrico', 650, 0],

            // CEARÁ - TIANGUÁ
            ['CEARÁ', 'TIANGUÁ', 'PSIQUIATRA', 460, 95],
            ['CEARÁ', 'TIANGUÁ', 'ENDOCRINO', 540, 104],
            ['CEARÁ', 'TIANGUÁ', 'OTORRINO', 520, 58],
            ['CEARÁ', 'TIANGUÁ', 'NEURO PED', 640, 146],
            ['CEARÁ', 'TIANGUÁ', 'Proctologista', 480, 0],

            // CEARÁ - OUTRAS CIDADES
            ['CEARÁ', 'NOVA RUSSAS', 'Otorrinolaringologista', 540, 0],
            ['CEARÁ', 'RUSSAS', 'Dermatologista', 480, 0],
            ['CEARÁ', 'CRATEÚ', 'Otorrinolaringologista', 480, 0],
            ['CEARÁ', 'ACARAU', 'NEUROPED', 650, 0],

            // RIO GRANDE DO NORTE - MOSSORÓ
            ['RIO GRANDE DO NORTE', 'MOSSORÓ', 'NEUROLOGISTA', 540, 2],
            ['RIO GRANDE DO NORTE', 'MOSSORÓ', 'GINECOLOGISTA', 350, 2],
            ['RIO GRANDE DO NORTE', 'MOSSORÓ', 'NUTROLOGO', 480, 5],

            // RORAIMA - BOA VISTA
            ['RORAIMA', 'BOA VISTA', 'CARDIO PED', 550, 0],
            ['RORAIMA', 'BOA VISTA', 'CARDIO', 440, 9],
            ['RORAIMA', 'BOA VISTA', 'OFTALMO', 300, 17],
            ['RORAIMA', 'BOA VISTA', 'PEDIATRA', 400, 13],
            ['RORAIMA', 'BOA VISTA', 'PSIQUIATRA', 600, 14],
            ['RORAIMA', 'BOA VISTA', 'PSIQUIATRA PED', 600, 1],
            ['RORAIMA', 'BOA VISTA', 'NEURO', 575, 3],
            ['RORAIMA', 'BOA VISTA', 'NEURO PED', 690, 0],
            ['RORAIMA', 'BOA VISTA', 'OTORRINO', 410, 4],
            ['RORAIMA', 'BOA VISTA', 'GASTRO', 440, 19],
            ['RORAIMA', 'BOA VISTA', 'ENDOCRINO', 470, 6],
            ['RORAIMA', 'BOA VISTA', 'DERMATO', 520, 26],
            ['RORAIMA', 'BOA VISTA', 'GINECOLOGISTA', 500, 18],
            ['RORAIMA', 'BOA VISTA', 'COLOPROCTOLOGISTA', 620, 2],
            ['RORAIMA', 'BOA VISTA', 'Ortopedista', 430, 0],
            ['RORAIMA', 'BOA VISTA', 'USG de Mamas', 380, 0],

            // PERNAMBUCO - RECIFE
            ['PERNAMBUCO', 'RECIFE', 'Psiquiatra', 650, 0],
            ['PERNAMBUCO', 'RECIFE', 'Nutrólogo', 650, 0],

            // PERNAMBUCO - CARUARU
            ['PERNAMBUCO', 'CARUARU', 'DERMATOLOGIA', 350, 1],
            ['PERNAMBUCO', 'CARUARU', 'ENDOCRINOLOGIA', 350, 0],
            ['PERNAMBUCO', 'CARUARU', 'GERIATRIA', 400, 0],
            ['PERNAMBUCO', 'CARUARU', 'NEUROPEDIATRA', 585, 2],
            ['PERNAMBUCO', 'CARUARU', 'PNEUMOLOGISTA', 370, 0],
            ['PERNAMBUCO', 'CARUARU', 'PEDIATRA', 320, 0],

            // PERNAMBUCO - OUTRAS CIDADES
            ['PERNAMBUCO', 'CARPINA', 'DERMATOLOGIA', 350, 0],
            ['PERNAMBUCO', 'CARPINA', 'ENDOCRINOLOGIA', 350, 0],
            ['PERNAMBUCO', 'CARPINA', 'GERIATRIA', 400, 0],
            ['PERNAMBUCO', 'CARPINA', 'NEUROPEDIATRA', 500, 1],
            ['PERNAMBUCO', 'CARPINA', 'PNEUMOLOGISTA', 370, 0],

            ['PERNAMBUCO', 'GARANHUNS', 'DERMATOLOGIA', 350, 0],
            ['PERNAMBUCO', 'GARANHUNS', 'ENDOCRINOLOGIA', 350, 0],
            ['PERNAMBUCO', 'GARANHUNS', 'GERIATRIA', 450, 0],
            ['PERNAMBUCO', 'GARANHUNS', 'NEUROPEDIATRA', 500, 0],
            ['PERNAMBUCO', 'GARANHUNS', 'PNEUMOLOGISTA', 400, 0],

            ['PERNAMBUCO', 'PETROLINA', 'DERMATOLOGIA', 420, 0],
            ['PERNAMBUCO', 'PETROLINA', 'ENDOCRINOLOGIA', 420, 0],
            ['PERNAMBUCO', 'PETROLINA', 'GERIATRIA', 400, 0],
            ['PERNAMBUCO', 'PETROLINA', 'NEUROPEDIATRA', 600, 4],
            ['PERNAMBUCO', 'PETROLINA', 'PNEUMOLOGISTA', 400, 0],

            ['PERNAMBUCO', 'GRAVATÁ', 'DERMATOLOGIA', 350, 0],
            ['PERNAMBUCO', 'GRAVATÁ', 'ENDOCRINOLOGIA', 350, 0],
            ['PERNAMBUCO', 'GRAVATÁ', 'GERIATRIA', 400, 0],
            ['PERNAMBUCO', 'GRAVATÁ', 'NEUROPEDIATRA', 500, 0],
            ['PERNAMBUCO', 'GRAVATÁ', 'PNEUMOLOGISTA', 430, 0],

            ['PERNAMBUCO', 'ESCADA', 'DERMATOLOGIA', 350, 0],
            ['PERNAMBUCO', 'ESCADA', 'ENDOCRINOLOGIA', 350, 0],
            ['PERNAMBUCO', 'ESCADA', 'GERIATRIA', 400, 0],
            ['PERNAMBUCO', 'ESCADA', 'NEUROPEDIATRA', 500, 0],

            ['PERNAMBUCO', 'ARCOVERDE', 'DERMATOLOGIA', 480, 1],
            ['PERNAMBUCO', 'ARCOVERDE', 'ENDOCRINOLOGIA', 480, 0],
            ['PERNAMBUCO', 'ARCOVERDE', 'GERIATRIA', 480, 0],
            ['PERNAMBUCO', 'ARCOVERDE', 'NEUROPEDIATRA', 550, 0],
            ['PERNAMBUCO', 'ARCOVERDE', 'PNEUMOLOGISTA', 480, 0],

            ['PERNAMBUCO', 'SERRA TALHADA', 'DERMATOLOGIA', 480, 3],
            ['PERNAMBUCO', 'SERRA TALHADA', 'ENDOCRINOLOGIA', 480, 0],
            ['PERNAMBUCO', 'SERRA TALHADA', 'GERIATRIA', 480, 0],
            ['PERNAMBUCO', 'SERRA TALHADA', 'NEUROPEDIATRA', 550, 0],
            ['PERNAMBUCO', 'SERRA TALHADA', 'PNEUMOLOGISTA', 480, 0],

            // PIAUÍ - TERESINA
            ['PIAUÍ', 'TERESINA', 'Psiquiatra', 650, 0],
            ['PIAUÍ', 'TERESINA', 'Neurologista ADULTO', 550, 0],
            ['PIAUÍ', 'TERESINA', 'Neurologista Pediátrico', 650, 0],
            ['PIAUÍ', 'TERESINA', 'Endocrinologista Pediatra', 650, 0],

            // PIAUÍ - PARNAÍBA
            ['PIAUÍ', 'PARNAÍBA', 'Gastroenterologia', 580, 0],
            ['PIAUÍ', 'PARNAÍBA', 'Psiquiatra', 490, 0],
            ['PIAUÍ', 'PARNAÍBA', 'Endocrinologista', 540, 0],

            // PARAÍBA - JOÃO PESSOA
            ['PARAÍBA', 'JOÃO PESSOA', 'Psiquiatra', 480, 0],

            // PARAÍBA - SOUSA
            ['PARAÍBA', 'SOUSA', 'Neurologista Pediátrico', 600, 0],

            // MARANHÃO - IMPERATRIZ
            ['MARANHÃO', 'IMPERATRIZ', 'Endocrinologista', 450, 0],
            ['MARANHÃO', 'IMPERATRIZ', 'Psiquiatra', 720, 0],
            ['MARANHÃO', 'IMPERATRIZ', 'Neurologista Pediátrico', 600, 0],

            // MARANHÃO - SANTA INÊS
            ['MARANHÃO', 'SANTA INÊS', 'Pediatra', 480, 0],
            ['MARANHÃO', 'SANTA INÊS', 'Neuropeditra', 680, 0],

            // MATO GROSSO - SINOP
            ['MATO GROSSO', 'SINOP', 'Gastroenterologia Ped', 650, 0],

            // MATO GROSSO - ARIPUANÃ
            ['MATO GROSSO', 'ARIPUANÃ', 'Pediatria', 550, 0],

            // PARÁ - MARABÁ
            ['PARÁ', 'MARABÁ', 'Neurologista Pediátrico', 950, 0],
            ['PARÁ', 'MARABÁ', 'Oftalmologista', 390, 0],
            ['PARÁ', 'MARABÁ', 'Neurologista Adulto', 630, 0],
            ['PARÁ', 'MARABÁ', 'Pediatra', 650, 0],

            // PARÁ - TUCUMÃ (Serviços de terapia)
            ['PARÁ', 'TUCUMÃ', 'PSICOLOGIA', 0, 3],
            ['PARÁ', 'TUCUMÃ', 'PSICOPEDAGOGIA', 0, 2],
            ['PARÁ', 'TUCUMÃ', 'PSICOMOTRICIDADE', 0, 1],
            ['PARÁ', 'TUCUMÃ', 'FONOTERAPIA', 0, 3],
            ['PARÁ', 'TUCUMÃ', 'TERAPIA OCUPACIOAL', 0, 3],
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
