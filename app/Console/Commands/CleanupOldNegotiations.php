<?php

namespace App\Console\Commands;

use App\Models\Negotiation;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOldNegotiations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'negotiations:cleanup {--days=30 : Number of days to keep negotiations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old negotiations that were not formalized';

    /**
     * Execute the console command.
     *
     * @param  \App\Services\NotificationService  $notificationService
     * @return int
     */
    public function handle(NotificationService $notificationService)
    {
        $days = $this->option('days');
        $date = now()->subDays($days);

        $this->info("Looking for negotiations older than {$days} days...");

        try {
            // Find old negotiations that were approved but not formalized
            $negotiations = Negotiation::where('status', 'approved')
                ->where('formalization_status', 'pending_aditivo')
                ->where('approved_at', '<', $date)
                ->get();

            if ($negotiations->isEmpty()) {
                $this->info('No old negotiations found.');
                return 0;
            }

            $this->info("Found {$negotiations->count()} old negotiations.");

            foreach ($negotiations as $negotiation) {
                $this->info("Processing negotiation #{$negotiation->id}...");

                // Send notification to commercial team
                $notificationService->sendToRole('commercial', [
                    'title' => 'Negociação Expirada',
                    'body' => "A negociação #{$negotiation->id} expirou por falta de formalização.",
                    'action_link' => "/negotiations/{$negotiation->id}",
                    'priority' => 'high'
                ]);

                // Send notification to creator
                $notificationService->sendToUser($negotiation->creator_id, [
                    'title' => 'Negociação Expirada',
                    'body' => "Sua negociação #{$negotiation->id} expirou por falta de formalização.",
                    'action_link' => "/negotiations/{$negotiation->id}",
                    'priority' => 'high'
                ]);

                // Update negotiation status
                $negotiation->status = 'expired';
                $negotiation->save();

                $this->info("Negotiation #{$negotiation->id} marked as expired.");
            }

            $this->info('Cleanup completed successfully.');
            return 0;

        } catch (\Exception $e) {
            Log::error('Failed to cleanup old negotiations', [
                'error' => $e->getMessage()
            ]);

            $this->error('Failed to cleanup old negotiations: ' . $e->getMessage());
            return 1;
        }
    }
} 