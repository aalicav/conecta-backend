<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\TussProcedure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TussProcedureSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $csvFile = database_path('seeders/Tabela_22_Procedimentos_e_eventos_em_saúde.csv');
        
        if (!File::exists($csvFile)) {
            $this->command->error('Arquivo CSV não encontrado!');
            return;
        }

        // Ler o arquivo CSV
        $handle = fopen($csvFile, 'r');
        
        // Pular a primeira linha (cabeçalho)
        fgetcsv($handle, 0, ';');

        // Processar cada linha
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            if (count($data) >= 5) {
                try {
                    TussProcedure::create([
                        'code' => $data[0], // Código do Termo
                        'name' => Str::limit($data[1], 190, '...'), // Limitando a 190 caracteres
                        'description' => $data[1], // Descrição completa
                        'category' => 'TUSS', // Categoria padrão
                        'is_active' => true, // Ativo por padrão
                        'created_at' => $data[2] ? date('Y-m-d H:i:s', strtotime($data[2])) : now(), // Data de início de vigência
                        'updated_at' => now(),
                    ]);
                } catch (\Exception $e) {
                    $this->command->error("Erro ao processar linha com código {$data[0]}: " . $e->getMessage());
                    continue;
                }
            }
        }

        fclose($handle);
        
        $this->command->info('Procedimentos TUSS importados com sucesso!');
    }
} 