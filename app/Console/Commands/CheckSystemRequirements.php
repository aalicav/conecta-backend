<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CheckSystemRequirements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:check-requirements';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check system requirements for report generation';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando requisitos do sistema...');
        
        $issues = [];
        $warnings = [];
        
        // Check PHP GD extension
        if (!extension_loaded('gd')) {
            $issues[] = 'Extensão GD do PHP não está instalada';
            $this->error('❌ Extensão GD do PHP não está instalada');
        } else {
            $this->info('✅ Extensão GD do PHP está instalada');
        }
        
        // Check PHP extensions for PDF generation
        $requiredExtensions = ['mbstring', 'xml', 'zip'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $issues[] = "Extensão PHP {$ext} não está instalada";
                $this->error("❌ Extensão PHP {$ext} não está instalada");
            } else {
                $this->info("✅ Extensão PHP {$ext} está instalada");
            }
        }
        
        // Check storage directory
        $storagePath = storage_path('app/public/reports');
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0755, true)) {
                $issues[] = 'Não foi possível criar o diretório de relatórios';
                $this->error('❌ Não foi possível criar o diretório de relatórios');
            } else {
                $this->info('✅ Diretório de relatórios criado');
            }
        } else {
            $this->info('✅ Diretório de relatórios existe');
        }
        
        // Check if logo exists
        $logoPath = public_path('logo.png');
        if (!file_exists($logoPath)) {
            $warnings[] = 'Arquivo logo.png não encontrado em public/';
            $this->warn('⚠️  Arquivo logo.png não encontrado em public/');
        } else {
            $this->info('✅ Arquivo logo.png encontrado');
        }
        
        // Check queue configuration
        $queueDriver = config('queue.default');
        $this->info("📋 Driver de fila configurado: {$queueDriver}");
        
        if ($queueDriver === 'database') {
            // Check if jobs table exists
            try {
                \DB::table('jobs')->count();
                $this->info('✅ Tabela jobs está acessível');
            } catch (\Exception $e) {
                $issues[] = 'Tabela jobs não está acessível';
                $this->error('❌ Tabela jobs não está acessível');
            }
        }
        
        // Check if queue worker is running
        $this->info('📊 Verificando se o worker de fila está rodando...');
        $this->call('queue:work', ['--once' => true, '--timeout' => 5]);
        
        // Summary
        $this->newLine();
        if (empty($issues) && empty($warnings)) {
            $this->info('🎉 Todos os requisitos estão atendidos!');
        } else {
            if (!empty($issues)) {
                $this->error('❌ Problemas encontrados:');
                foreach ($issues as $issue) {
                    $this->error("  - {$issue}");
                }
            }
            
            if (!empty($warnings)) {
                $this->warn('⚠️  Avisos:');
                foreach ($warnings as $warning) {
                    $this->warn("  - {$warning}");
                }
            }
            
            $this->newLine();
            $this->info('💡 Para resolver os problemas:');
            $this->info('  1. Instale a extensão GD: sudo apt-get install php-gd');
            $this->info('  2. Reinicie o servidor web: sudo systemctl restart apache2');
            $this->info('  3. Execute: php artisan queue:work --daemon');
        }
        
        return empty($issues) ? 0 : 1;
    }
} 