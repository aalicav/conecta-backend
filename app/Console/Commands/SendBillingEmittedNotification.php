<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BillingBatch;
use App\Models\User;
use App\Notifications\BillingEmitted;
use Illuminate\Support\Facades\Log;

class SendBillingEmittedNotification extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:send-emitted-notification 
                            {billing_batch_id : ID do lote de cobrança}
                            {--recipients= : IDs dos usuários separados por vírgula (opcional, se não informado envia para todos os usuários com papel de plan_admin)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envia notificação de cobrança emitida via WhatsApp e email';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $billingBatchId = $this->argument('billing_batch_id');
        $recipientIds = $this->option('recipients');

        try {
            // Busca o lote de cobrança
            $billingBatch = BillingBatch::with(['items.appointment.solicitation.patient'])->findOrFail($billingBatchId);
            
            $this->info("Lote de cobrança encontrado: #{$billingBatch->id}");
            $this->info("Período: {$billingBatch->reference_period_start} a {$billingBatch->reference_period_end}");
            $this->info("Valor total: R$ " . number_format($billingBatch->total_amount, 2, ',', '.'));
            
            // Determina os destinatários
            if ($recipientIds) {
                $recipientIds = explode(',', $recipientIds);
                $recipients = User::whereIn('id', $recipientIds)->get();
            } else {
                // Se não especificado, envia para todos os usuários com papel de plan_admin
                $recipients = User::role('plan_admin')->get();
            }
            
            if ($recipients->isEmpty()) {
                $this->error('Nenhum destinatário encontrado!');
                return 1;
            }
            
            $this->info("Enviando notificação para {$recipients->count()} destinatário(s)...");
            
            // Busca o primeiro agendamento do lote para a notificação
            $firstItem = $billingBatch->items->first();
            $appointment = $firstItem ? $firstItem->appointment : null;
            
            if ($appointment) {
                $patientName = $appointment->solicitation->patient->name ?? 'Paciente';
                $appointmentDate = $appointment->scheduled_date ? 
                    \Carbon\Carbon::parse($appointment->scheduled_date)->format('d/m/Y') : 'Data não especificada';
                
                $this->info("Paciente: {$patientName}");
                $this->info("Data do agendamento: {$appointmentDate}");
            }
            
            $successCount = 0;
            $errorCount = 0;
            
            // Envia a notificação para cada destinatário
            foreach ($recipients as $recipient) {
                try {
                    $recipient->notify(new BillingEmitted($billingBatch, $appointment));
                    $this->line("✓ Notificação enviada para: {$recipient->name} ({$recipient->email})");
                    $successCount++;
                } catch (\Exception $e) {
                    $this->error("✗ Erro ao enviar para {$recipient->name}: {$e->getMessage()}");
                    $errorCount++;
                    Log::error('Error sending billing emitted notification', [
                        'recipient_id' => $recipient->id,
                        'billing_batch_id' => $billingBatch->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $this->newLine();
            $this->info("Resumo:");
            $this->info("- Notificações enviadas com sucesso: {$successCount}");
            $this->info("- Erros: {$errorCount}");
            
            if ($successCount > 0) {
                $this->info("Notificação de cobrança emitida enviada com sucesso!");
                return 0;
            } else {
                $this->error("Nenhuma notificação foi enviada com sucesso.");
                return 1;
            }
            
        } catch (\Exception $e) {
            $this->error("Erro: {$e->getMessage()}");
            Log::error('Error in SendBillingEmittedNotification command', [
                'billing_batch_id' => $billingBatchId,
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }
} 