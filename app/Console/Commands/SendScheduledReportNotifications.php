<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ScheduledReportAvailable;
use Carbon\Carbon;

class SendScheduledReportNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reports:send-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send notifications for scheduled reports';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to send scheduled report notifications...');

        $reports = Report::where('is_scheduled', true)
            ->where('is_active', true)
            ->whereNotNull('last_generated_at')
            ->get();

        $count = 0;

        foreach ($reports as $report) {
            try {
                // Get the creator
                $creator = User::find($report->created_by);
                if ($creator) {
                    $creator->notify(new ScheduledReportAvailable($report));
                    $count++;
                }

                // Send to additional recipients if any
                if ($report->recipients) {
                    foreach ($report->recipients as $email) {
                        $user = User::where('email', $email)->first();
                        if ($user) {
                            $user->notify(new ScheduledReportAvailable($report));
                            $count++;
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->error("Failed to send notification for report {$report->id}: " . $e->getMessage());
            }
        }

        $this->info("Completed. Sent {$count} notifications.");
    }
} 