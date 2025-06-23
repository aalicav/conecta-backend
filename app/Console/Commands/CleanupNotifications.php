<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CleanupNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:cleanup {--days=30 : Number of days to keep notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting notifications cleanup...');

        $days = $this->option('days');
        $cutoffDate = Carbon::now()->subDays($days);

        $count = DB::table('notifications')
            ->where('created_at', '<', $cutoffDate)
            ->delete();

        $this->info("Cleanup completed. Deleted {$count} notifications older than {$days} days.");
    }
} 