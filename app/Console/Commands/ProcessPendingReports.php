<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ReportGeneration;
use App\Jobs\GenerateReport;
use Illuminate\Support\Facades\Log;

class ProcessPendingReports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:process-pending {--force : Force reprocess all stuck reports}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending or stuck report generations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando relatórios pendentes...');
        
        $query = ReportGeneration::where('status', 'processing');
        
        if ($this->option('force')) {
            $query->orWhere('status', 'failed');
            $this->warn('⚠️  Modo force ativado - reprocessando relatórios falhados também');
        }
        
        $pendingReports = $query->with('report')->get();
        
        if ($pendingReports->isEmpty()) {
            $this->info('✅ Nenhum relatório pendente encontrado');
            return 0;
        }
        
        $this->info("📊 Encontrados {$pendingReports->count()} relatórios pendentes");
        
        $processed = 0;
        $failed = 0;
        
        foreach ($pendingReports as $generation) {
            $this->info("🔄 Processando relatório #{$generation->id} (Tipo: {$generation->report->type})");
            
            try {
                // Check if generation is too old (more than 1 hour)
                if ($generation->started_at && $generation->started_at->diffInHours(now()) > 1) {
                    $this->warn("⚠️  Relatório #{$generation->id} está preso há mais de 1 hora, marcando como falhado");
                    $generation->update([
                        'status' => 'failed',
                        'completed_at' => now(),
                        'error_message' => 'Relatório preso em processamento por muito tempo'
                    ]);
                    $failed++;
                    continue;
                }
                
                // Dispatch new job
                GenerateReport::dispatch(
                    $generation->report->type,
                    $generation->parameters ?? [],
                    $generation->file_format,
                    $generation->generated_by,
                    $generation->report_id,
                    $generation->id
                );
                
                $this->info("✅ Job despachado para relatório #{$generation->id}");
                $processed++;
                
            } catch (\Exception $e) {
                $this->error("❌ Erro ao processar relatório #{$generation->id}: {$e->getMessage()}");
                $failed++;
                
                // Mark as failed
                $generation->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => $e->getMessage()
                ]);
            }
        }
        
        $this->newLine();
        $this->info("📈 Resumo:");
        $this->info("  ✅ Processados: {$processed}");
        $this->info("  ❌ Falhados: {$failed}");
        
        if ($processed > 0) {
            $this->info("💡 Execute 'php artisan queue:work' para processar os jobs despachados");
        }
        
        return $failed === 0 ? 0 : 1;
    }
} 