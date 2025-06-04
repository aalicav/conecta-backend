<?php

namespace App\Console\Commands;

use App\Models\BillingBatch;
use App\Notifications\PaymentOverdue;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckOverduePayments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:check-overdue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica pagamentos em atraso e envia notificações';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando verificação de pagamentos em atraso...');

        $overdueBatches = BillingBatch::where('payment_status', 'pending')
            ->where('due_date', '<', now())
            ->where('is_late', false)
            ->get();

        $count = 0;
        foreach ($overdueBatches as $batch) {
            $batch->update([
                'is_late' => true,
                'days_late' => now()->diffInDays($batch->due_date)
            ]);

            // Notifica sobre o atraso
            Notification::send($batch->entity, new PaymentOverdue($batch));
            $count++;

            $this->info("Notificação enviada para lote #{$batch->id} - {$batch->days_late} dias em atraso");
        }

        $this->info("Verificação concluída. {$count} lotes em atraso encontrados.");
    }
} 