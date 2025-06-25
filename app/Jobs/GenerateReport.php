<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ReportGenerationService;
use App\Models\User;
use App\Models\Report;
use App\Models\ReportGeneration;
use App\Notifications\ReportGenerated;
use App\Notifications\ReportGenerationFailed;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class GenerateReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $type;
    protected $filters;
    protected $format;
    protected $userId;
    protected $reportId;
    protected $generationId;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, array $filters, string $format, int $userId, int $reportId, int $generationId)
    {
        $this->type = $type;
        $this->filters = $filters;
        $this->format = $format;
        $this->userId = $userId;
        $this->reportId = $reportId;
        $this->generationId = $generationId;
    }

    /**
     * Execute the job.
     */
    public function handle(ReportGenerationService $reportService)
    {
        // Get the user and generation record
        $user = User::find($this->userId);
        $generation = ReportGeneration::with('report')->find($this->generationId);

        if (!$generation) {
            throw new \Exception("Report generation record not found");
        }

        try {
            // Generate the report
            $filePath = $reportService->generateReport(
                $this->type,
                $this->filters,
                $this->format
            );

            // Get file size if available
            $fileSize = Storage::exists($filePath) ? Storage::size($filePath) : null;

            // Update generation record with success
            $generation->update([
                'file_path' => $filePath,
                'status' => 'completed',
                'completed_at' => now(),
                'file_size' => $fileSize
            ]);

            // Update report's last generation time
            $generation->report->updateNextScheduledTime();

            // Send notification
            if ($user) {
                Notification::send($user, new ReportGenerated(
                    $this->type,
                    $filePath,
                    url("storage/{$filePath}")
                ));
            }
        } catch (\Exception $e) {
            // Update generation record with failure
            $generation->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage()
            ]);

            // Log error and notify user
            \Log::error('Error generating report: ' . $e->getMessage(), [
                'type' => $this->type,
                'filters' => $this->filters,
                'format' => $this->format,
                'user_id' => $this->userId,
                'report_id' => $this->reportId,
                'generation_id' => $this->generationId
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