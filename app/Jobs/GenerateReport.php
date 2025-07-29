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
use Illuminate\Support\Facades\Log;

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
        Log::info('Starting report generation job', [
            'type' => $this->type,
            'format' => $this->format,
            'user_id' => $this->userId,
            'report_id' => $this->reportId,
            'generation_id' => $this->generationId,
            'filters' => $this->filters
        ]);

        // Get the user and generation record
        $user = User::find($this->userId);
        $generation = ReportGeneration::with('report')->find($this->generationId);

        if (!$generation) {
            Log::error('Report generation record not found', [
                'generation_id' => $this->generationId
            ]);
            throw new \Exception("Report generation record not found");
        }

        try {
            Log::info('Generating report using ReportGenerationService', [
                'type' => $this->type,
                'format' => $this->format
            ]);

            // Generate the report
            $filePath = $reportService->generateReport(
                $this->type,
                $this->filters,
                $this->format
            );

            Log::info('Report generated successfully', [
                'file_path' => $filePath,
                'generation_id' => $this->generationId
            ]);

            // Get file size if available
            $fileSize = Storage::exists($filePath) ? Storage::size($filePath) : null;

            Log::info('Updating generation record with success', [
                'generation_id' => $this->generationId,
                'file_size' => $fileSize
            ]);

            // Update generation record with success
            $generation->update([
                'file_path' => $filePath,
                'status' => 'completed',
                'completed_at' => now(),
                'file_size' => $fileSize
            ]);

            // Update report's last generation time
            if ($generation->report) {
                $generation->report->updateNextScheduledTime();
            }

            Log::info('Report generation completed successfully', [
                'generation_id' => $this->generationId,
                'report_id' => $this->reportId
            ]);

            // Send notification
            if ($user) {
                try {
                    Notification::send($user, new ReportGenerated(
                        $this->type,
                        $filePath,
                        url("storage/{$filePath}")
                    ));
                    Log::info('Notification sent successfully', [
                        'user_id' => $this->userId
                    ]);
                } catch (\Exception $e) {
                    Log::warning('Failed to send notification', [
                        'user_id' => $this->userId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error generating report', [
                'type' => $this->type,
                'filters' => $this->filters,
                'format' => $this->format,
                'user_id' => $this->userId,
                'report_id' => $this->reportId,
                'generation_id' => $this->generationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update generation record with failure
            $generation->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage()
            ]);

            // Log error and notify user
            if ($user) {
                try {
                    Notification::send($user, new ReportGenerationFailed(
                        $this->type,
                        $e->getMessage()
                    ));
                } catch (\Exception $notificationError) {
                    Log::error('Failed to send failure notification', [
                        'user_id' => $this->userId,
                        'error' => $notificationError->getMessage()
                    ]);
                }
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('Report generation job failed', [
            'type' => $this->type,
            'format' => $this->format,
            'user_id' => $this->userId,
            'report_id' => $this->reportId,
            'generation_id' => $this->generationId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        // Update generation record with failure
        $generation = ReportGeneration::find($this->generationId);
        if ($generation) {
            $generation->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage()
            ]);
        }

        // Try to notify user about failure
        $user = User::find($this->userId);
        if ($user) {
            try {
                Notification::send($user, new ReportGenerationFailed(
                    $this->type,
                    $exception->getMessage()
                ));
            } catch (\Exception $notificationError) {
                Log::error('Failed to send failure notification in failed method', [
                    'user_id' => $this->userId,
                    'error' => $notificationError->getMessage()
                ]);
            }
        }
    }
} 