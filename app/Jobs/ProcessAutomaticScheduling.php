<?php

namespace App\Jobs;

use App\Models\Solicitation;
use App\Models\SolicitationInvite;
use App\Services\AppointmentScheduler;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Notifications\NoProvidersFound;
use Illuminate\Support\Facades\Notification;

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
     * The notification service instance.
     *
     * @var \App\Services\NotificationService
     */
    protected $notificationService;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Solicitation  $solicitation
     * @return void
     */
    public function __construct(Solicitation $solicitation)
    {
        $this->solicitation = $solicitation;
        $this->notificationService = app(NotificationService::class);
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        try {
            Log::info("Iniciando processamento automático para solicitação #{$this->solicitation->id}");

            // Check if there are already pending invites for this solicitation
            $existingInvites = SolicitationInvite::where('solicitation_id', $this->solicitation->id)
                ->where('status', 'pending')
                ->count();

            if ($existingInvites > 0) {
                Log::info("Solicitação #{$this->solicitation->id} já possui {$existingInvites} convites pendentes. Pulando processamento.");
                return;
            }

            $scheduler = new AppointmentScheduler();
            $providers = $scheduler->findBestProvider($this->solicitation);

            // Log the providers data for debugging
            Log::info("Providers found for solicitation #{$this->solicitation->id}:", ['providers' => $providers]);

            if (!empty($providers) && is_array($providers)) {
                $createdInvites = 0;
                
                foreach ($providers as $provider) {
                    // Validate provider data structure
                    if (!is_array($provider) || 
                        !isset($provider['provider_type']) || 
                        !isset($provider['provider_id'])) {
                        Log::warning("Invalid provider data structure:", ['provider' => $provider]);
                        continue;
                    }

                    // Double-check if invite already exists for this specific provider
                    $existingProviderInvite = SolicitationInvite::where('solicitation_id', $this->solicitation->id)
                        ->where('provider_type', $provider['provider_type'])
                        ->where('provider_id', $provider['provider_id'])
                        ->where('status', 'pending')
                        ->exists();

                    if ($existingProviderInvite) {
                        Log::info("Convite já existe para provider {$provider['provider_type']}#{$provider['provider_id']} na solicitação #{$this->solicitation->id}");
                        continue;
                    }

                    // Get the provider model
                    $providerModel = null;
                    try {
                        $providerModel = $provider['provider_type']::find($provider['provider_id']);
                    } catch (\Exception $e) {
                        Log::warning("Error finding provider model:", [
                            'provider_type' => $provider['provider_type'],
                            'provider_id' => $provider['provider_id'],
                            'error' => $e->getMessage()
                        ]);
                        continue;
                    }

                    if (!$providerModel || !$providerModel->user) {
                        Log::warning("Provider or user not found:", [
                            'provider_type' => $provider['provider_type'],
                            'provider_id' => $provider['provider_id']
                        ]);
                        continue;
                    }

                    // Create invite for each provider
                    $invite = SolicitationInvite::create([
                        'solicitation_id' => $this->solicitation->id,
                        'provider_type' => $provider['provider_type'],
                        'provider_id' => $provider['provider_id'],
                        'status' => 'pending',
                        'created_by' => $this->solicitation->requested_by
                    ]);
                    
                    // Send notification using NotificationService
                    $this->notificationService->sendSolicitationInviteNotification(
                        $this->solicitation,
                        $invite,
                        $providerModel->user
                    );
                    
                    $createdInvites++;
                }
                
                Log::info("Criados {$createdInvites} novos convites para solicitação #{$this->solicitation->id}");
            } else {
                // If no providers found, mark as pending
                $this->solicitation->markAsPending();
                Log::warning("Nenhum profissional encontrado para solicitação #{$this->solicitation->id}");

                // Notify administrators
                $usersToNotify = User::role(['super_admin', 'network_manager', 'director', 'commercial_manager'])
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->get();

                if (!$usersToNotify->isEmpty()) {
                    Notification::send($usersToNotify, new NoProvidersFound($this->solicitation));
                    Log::info("Notificação enviada para " . $usersToNotify->count() . " administradores sobre a falta de profissionais para solicitação #{$this->solicitation->id}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Erro no processamento automático da solicitação #{$this->solicitation->id}: " . $e->getMessage());
            
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