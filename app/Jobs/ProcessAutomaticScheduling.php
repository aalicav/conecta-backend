<?php

namespace App\Jobs;

use App\Models\Solicitation;
use App\Services\AppointmentScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAutomaticScheduling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var int
     */
    public $backoff = 60;

    /**
     * The solicitation instance.
     *
     * @var \App\Models\Solicitation
     */
    protected $solicitation;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @return void
     */
    public function __construct(Solicitation $solicitation)
    {
        $this->solicitation = $solicitation;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            Log::info("Iniciando agendamento automático para solicitação #{$this->solicitation->id}");

            $scheduler = new AppointmentScheduler();
            $result = $scheduler->findBestProvider($this->solicitation);

            if ($result['success']) {
                // Create appointment with the found provider
                $appointment = new \App\Models\Appointment([
                    'solicitation_id' => $this->solicitation->id,
                    'provider_type' => $result['provider']['provider_type'],
                    'provider_id' => $result['provider']['provider_id'],
                    'status' => \App\Models\Appointment::STATUS_SCHEDULED,
                    'created_by' => $this->solicitation->requested_by
                ]);

                $appointment->save();
                
                // Mark solicitation as scheduled
                $this->solicitation->markAsScheduled(true);
                
                Log::info("Agendamento automático concluído com sucesso para solicitação #{$this->solicitation->id}");
            } else {
                // If no provider found, mark as pending
                $this->solicitation->markAsPending();
                Log::warning("Nenhum profissional encontrado para solicitação #{$this->solicitation->id}");
            }
        } catch (\Exception $e) {
            Log::error("Erro no agendamento automático da solicitação #{$this->solicitation->id}: " . $e->getMessage());
            
            // Make sure to mark as pending if an error occurred
            $this->solicitation->markAsPending();
            
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function failed(\Throwable $exception)
    {
        Log::error("Falha definitiva no agendamento automático da solicitação #{$this->solicitation->id}: " . $exception->getMessage());
        
        // Ensure solicitation is marked as pending
        $this->solicitation->markAsPending();
    }
} 