<?php

namespace App\Jobs;

use App\Models\Negotiation;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNegotiationFormalization implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $negotiation;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\Negotiation  $negotiation
     * @return void
     */
    public function __construct(Negotiation $negotiation)
    {
        $this->negotiation = $negotiation;
    }

    /**
     * Execute the job.
     *
     * @param  \App\Services\NotificationService  $notificationService
     * @return void
     */
    public function handle(NotificationService $notificationService)
    {
        try {
            // Check if negotiation is in correct status
            if ($this->negotiation->formalization_status !== 'pending_aditivo') {
                Log::info('Negotiation is not pending formalization', [
                    'negotiation_id' => $this->negotiation->id,
                    'status' => $this->negotiation->formalization_status
                ]);
                return;
            }

            // Here you would implement the actual formalization process
            // For example:
            // - Generate contract addendum
            // - Update contract terms
            // - Update pricing tables
            // - etc.

            // For now, we'll just send notifications
            $notificationService->sendToRole('commercial', [
                'title' => 'Formalização Pendente',
                'body' => "A negociação #{$this->negotiation->id} precisa ser formalizada via aditivo contratual.",
                'action_link' => "/negotiations/{$this->negotiation->id}",
                'priority' => 'high'
            ]);

            // You might also want to notify other stakeholders
            $notificationService->sendToUser($this->negotiation->creator_id, [
                'title' => 'Negociação em Formalização',
                'body' => "Sua negociação #{$this->negotiation->id} está em processo de formalização.",
                'action_link' => "/negotiations/{$this->negotiation->id}",
                'priority' => 'medium'
            ]);

            Log::info('Negotiation formalization process started', [
                'negotiation_id' => $this->negotiation->id
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process negotiation formalization', [
                'negotiation_id' => $this->negotiation->id,
                'error' => $e->getMessage()
            ]);

            // Notify about the failure
            $notificationService->sendToRole('commercial', [
                'title' => 'Erro na Formalização',
                'body' => "Ocorreu um erro ao processar a formalização da negociação #{$this->negotiation->id}.",
                'action_link' => "/negotiations/{$this->negotiation->id}",
                'priority' => 'high'
            ]);

            throw $e;
        }
    }
} 