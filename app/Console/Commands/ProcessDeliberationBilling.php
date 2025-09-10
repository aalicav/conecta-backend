<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DeliberationBillingService;
use Illuminate\Support\Facades\Log;

class ProcessDeliberationBilling extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deliberations:process-billing 
                            {--dry-run : Run without making changes}
                            {--force : Force processing even if there are errors}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process approved deliberations for billing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting deliberation billing process...');

        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            $billingService = new DeliberationBillingService();
            
            // Get statistics before processing
            $stats = $billingService->getBillingStatistics();
            
            $this->info("Current statistics:");
            $this->table(
                ['Metric', 'Count', 'Value'],
                [
                    ['Total Deliberations', $stats['total_deliberations'], '-'],
                    ['Approved Deliberations', $stats['approved_deliberations'], '-'],
                    ['Billed Deliberations', $stats['billed_deliberations'], '-'],
                    ['Pending Billing', $stats['pending_billing'], 'R$ ' . number_format($stats['pending_value'], 2, ',', '.')],
                    ['Total Value Billed', '-', 'R$ ' . number_format($stats['total_value_billed'], 2, ',', '.')],
                    ['Billing Rate', number_format($stats['billing_rate'], 2) . '%', '-']
                ]
            );

            if ($stats['pending_billing'] === 0) {
                $this->info('No deliberations pending billing.');
                return 0;
            }

            if (!$dryRun) {
                $this->info("Processing {$stats['pending_billing']} deliberations...");
                
                $result = $billingService->processApprovedDeliberations();
                
                $this->info("Processed: {$result['total_processed']} deliberations");
                
                if ($result['total_errors'] > 0) {
                    $this->error("Errors: {$result['total_errors']} deliberations failed");
                    
                    if (!$force) {
                        $this->error('Use --force to continue despite errors');
                        return 1;
                    }
                    
                    $this->warn('Continuing despite errors...');
                }

                // Show processed deliberations
                if (!empty($result['processed'])) {
                    $this->info("\nProcessed deliberations:");
                    $this->table(
                        ['ID', 'Number', 'Health Plan', 'Clinic', 'Total Value'],
                        collect($result['processed'])->map(function ($deliberation) {
                            return [
                                $deliberation->id,
                                $deliberation->deliberation_number,
                                $deliberation->healthPlan->name,
                                $deliberation->clinic->name,
                                'R$ ' . number_format($deliberation->total_value, 2, ',', '.')
                            ];
                        })->toArray()
                    );
                }

                // Show errors
                if (!empty($result['errors'])) {
                    $this->error("\nErrors:");
                    $this->table(
                        ['ID', 'Number', 'Error'],
                        collect($result['errors'])->map(function ($error) {
                            return [
                                $error['deliberation_id'],
                                $error['deliberation_number'],
                                $error['error']
                            ];
                        })->toArray()
                    );
                }

                $this->info('Deliberation billing process completed successfully!');
            } else {
                $this->info('DRY RUN: Would process ' . $stats['pending_billing'] . ' deliberations');
            }

            return 0;
        } catch (\Exception $e) {
            $this->error('Error processing deliberations: ' . $e->getMessage());
            Log::error('Deliberation billing process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
}