<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\BillingRuleService;

class ProcessBillingRules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'billing:process-rules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all active billing rules and generate batches';

    /**
     * Execute the console command.
     */
    public function handle(BillingRuleService $service)
    {
        $this->info('Starting billing rules processing...');

        try {
            $service->processBillingRules();
            $this->info('Billing rules processed successfully.');
        } catch (\Exception $e) {
            $this->error('Error processing billing rules: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
} 