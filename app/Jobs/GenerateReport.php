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
use App\Models\Report;
use Illuminate\Support\Facades\Storage;

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
            // Create or get the report record
            $report = Report::where('type', $this->type)->first();
            if (!$report) {
                $report = Report::create([
                    'type' => $this->type,
                    'name' => ucfirst($this->type) . ' Report',
                    'description' => 'Automatically created report',
                    'parameters' => $this->filters,
                    'file_format' => $this->format,
                    'created_by' => $this->userId
                ]);
            }

            // Generate the report first
            $filePath = $reportService->generateReport(
                $this->type,
                $this->filters,
                $this->format
            );

            // Get file size if available
            $fileSize = Storage::exists($filePath) ? Storage::size($filePath) : null;

            // Create generation record with the file path
            $generation = $report->generations()->create([
                'file_path' => $filePath,
                'file_format' => $this->format,
                'parameters' => $this->filters,
                'generated_by' => $this->userId,
                'status' => 'completed',
                'completed_at' => now(),
                'file_size' => $fileSize,
                'rows_count' => null // You might want to add this if you can count the rows
            ]);

            // Send notification
            if ($user) {
                Notification::send($user, new ReportGenerated(
                    $this->type,
                    $filePath,
                    url("storage/{$filePath}")
                ));
            }

            // Update report's last generation time
            $report->updateNextScheduledTime();

        } catch (\Exception $e) {
            // Create failed generation record if we have a report
            if (isset($report)) {
                $report->generations()->create([
                    'file_path' => null,
                    'file_format' => $this->format,
                    'parameters' => $this->filters,
                    'generated_by' => $this->userId,
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_message' => $e->getMessage()
                ]);
            }

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