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
            // Create a report generation record
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

            $generation = $report->generations()->create([
                'file_format' => $this->format,
                'parameters' => $this->filters,
                'generated_by' => $this->userId,
                'status' => 'processing'
            ]);

            // Generate the report
            $filePath = $reportService->generateReport(
                $this->type,
                $this->filters,
                $this->format
            );

            // Update generation record with success
            $fileSize = Storage::exists($filePath) ? Storage::size($filePath) : null;
            $generation->markAsCompleted(null, $fileSize);

            // Send notification
            if ($user) {
                Notification::send($user, new ReportGenerated(
                    $this->type,
                    $filePath,
                    url("storage/{$filePath}")
                ));
            }
        } catch (\Exception $e) {
            // Update generation record with failure if it exists
            if (isset($generation)) {
                $generation->markAsFailed($e->getMessage());
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