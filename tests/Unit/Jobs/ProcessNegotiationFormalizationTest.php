<?php

namespace Tests\Unit\Jobs;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Jobs\ProcessNegotiationFormalization;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;

class ProcessNegotiationFormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected $notificationService;
    protected $negotiation;
    protected $user;

    public function setUp(): void
    {
        parent::setUp();

        // Mock the notification service
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->app->instance(NotificationService::class, $this->notificationService);

        // Create a user
        $this->user = User::factory()->create();

        // Create a negotiation
        $this->negotiation = Negotiation::factory()->create([
            'creator_id' => $this->user->id,
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
        ]);
    }

    /** @test */
    public function it_sends_notifications_when_processing_formalization()
    {
        // Set up expectations for notifications
        $this->notificationService->shouldReceive('sendToRole')
            ->once()
            ->with('commercial', Mockery::on(function ($notification) {
                return $notification['title'] === 'Formalização Pendente' &&
                       str_contains($notification['body'], $this->negotiation->id);
            }));

        $this->notificationService->shouldReceive('sendToUser')
            ->once()
            ->with($this->user->id, Mockery::on(function ($notification) {
                return $notification['title'] === 'Negociação em Formalização' &&
                       str_contains($notification['body'], $this->negotiation->id);
            }));

        // Process the job
        $job = new ProcessNegotiationFormalization($this->negotiation);
        $job->handle($this->notificationService);

        // Verify that the notifications were sent
        $this->notificationService->shouldHaveReceived('sendToRole')->once();
        $this->notificationService->shouldHaveReceived('sendToUser')->once();
    }

    /** @test */
    public function it_logs_when_negotiation_is_not_pending_formalization()
    {
        // Change negotiation status
        $this->negotiation->formalization_status = 'formalized';
        $this->negotiation->save();

        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Process the job
        $job = new ProcessNegotiationFormalization($this->negotiation);
        $job->handle($this->notificationService);

        // Verify that the correct log was made
        $logSpy->shouldHaveReceived('info')
            ->with('Negotiation is not pending formalization', [
                'negotiation_id' => $this->negotiation->id,
                'status' => 'formalized'
            ]);
    }

    /** @test */
    public function it_handles_errors_gracefully()
    {
        // Make the notification service throw an exception
        $this->notificationService->shouldReceive('sendToRole')
            ->andThrow(new \Exception('Failed to send notification'));

        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Process the job and expect an exception
        $this->expectException(\Exception::class);

        try {
            $job = new ProcessNegotiationFormalization($this->negotiation);
            $job->handle($this->notificationService);
        } catch (\Exception $e) {
            // Verify that the error was logged
            $logSpy->shouldHaveReceived('error')
                ->with('Failed to process negotiation formalization', [
                    'negotiation_id' => $this->negotiation->id,
                    'error' => 'Failed to send notification'
                ]);

            throw $e;
        }
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 