<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ReportGenerationService;
use App\Models\User;
use App\Notifications\ReportGenerated;
use App\Notifications\ReportGenerationFailed;
use Illuminate\Support\Facades\Notification;

class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $type;
    protected $filters;
    protected $format;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, array $filters, string $format, int $userId)
    {
        $this->type = $type;
        $this->filters = $filters;
        $this->format = $format;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(ReportGenerationService $reportService)
    {
        // Get the user before try-catch block
        $user = User::find($this->userId);

        try {
            // Generate the report
            $filePath = $reportService->generateReport(
                $this->type,
                $this->filters,
                $this->format
            );

            // Send notification
            if ($user) {
                Notification::send($user, new ReportGenerated(
                    $this->type,
                    $filePath,
                    url("storage/{$filePath}")
                ));
            }
        } catch (\Exception $e) {
            // Log error and notify user
            \Log::error('Error generating report: ' . $e->getMessage(), [
                'type' => $this->type,
                'filters' => $this->filters,
                'format' => $this->format,
                'user_id' => $this->userId
            ]);

            if ($user) {
                Notification::send($user, new ReportGenerationFailed(
                    $this->type,
                    $e->getMessage()
                ));
            }

            throw $e;
        }
    }
} 