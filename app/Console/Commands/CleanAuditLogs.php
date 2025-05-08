<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use OwenIt\Auditing\Models\Audit;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CleanAuditLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'audit:clean 
                            {--days=90 : Number of days to keep audit logs}
                            {--limit=1000 : Maximum number of records to delete in a single batch}
                            {--dry-run : Run without deleting anything, just show stats}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean old audit logs older than specified days';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = (int) $this->option('days');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');
        
        if ($days < 1) {
            $this->error('Days must be at least 1');
            return 1;
        }

        $date = Carbon::now()->subDays($days);
        
        $count = Audit::where('created_at', '<', $date)->count();
        
        $this->info("Found {$count} audit logs older than {$days} days.");
        
        if ($dryRun) {
            $this->info('Dry run complete, no records were deleted.');
            return 0;
        }
        
        if ($count === 0) {
            $this->info('No audit logs to delete.');
            return 0;
        }
        
        $this->info("Starting deletion process with limit of {$limit} records per batch...");
        
        $bar = $this->output->createProgressBar(ceil($count / $limit));
        $bar->start();
        
        $totalDeleted = 0;
        
        do {
            // Delete in batches to avoid memory issues
            $deleted = Audit::where('created_at', '<', $date)
                ->limit($limit)
                ->delete();
                
            $totalDeleted += $deleted;
            $bar->advance();
            
            // Sleep for a moment to reduce database pressure
            if ($deleted > 0 && $totalDeleted < $count) {
                usleep(200000); // 0.2 seconds
            }
            
        } while ($deleted > 0 && $totalDeleted < $count);
        
        $bar->finish();
        $this->newLine(2);
        
        $this->info("Successfully deleted {$totalDeleted} audit logs.");
        
        // Log the cleanup
        Log::info("Audit logs cleanup: deleted {$totalDeleted} logs older than {$days} days.");
        
        return 0;
    }
} 