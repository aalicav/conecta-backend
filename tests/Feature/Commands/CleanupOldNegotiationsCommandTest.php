<?php

namespace Tests\Feature\Commands;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;

class CleanupOldNegotiationsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected $notificationService;

    public function setUp(): void
    {
        parent::setUp();

        // Mock the notification service
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->app->instance(NotificationService::class, $this->notificationService);

        // Set up default notification expectations
        $this->notificationService->shouldReceive('sendToRole')->andReturn(true);
        $this->notificationService->shouldReceive('sendToUser')->andReturn(true);
    }

    /** @test */
    public function it_marks_old_unformalized_negotiations_as_expired()
    {
        // Create a user
        $user = User::factory()->create();

        // Create an old approved but unformalized negotiation
        $oldNegotiation = Negotiation::factory()->create([
            'creator_id' => $user->id,
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(31),
        ]);

        // Create a recent approved but unformalized negotiation
        $recentNegotiation = Negotiation::factory()->create([
            'creator_id' => $user->id,
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(15),
        ]);

        // Run the command
        $this->artisan('negotiations:cleanup')
            ->expectsOutput('Looking for negotiations older than 30 days...')
            ->expectsOutput("Found 1 old negotiations.")
            ->expectsOutput("Processing negotiation #{$oldNegotiation->id}...")
            ->expectsOutput("Negotiation #{$oldNegotiation->id} marked as expired.")
            ->expectsOutput('Cleanup completed successfully.')
            ->assertExitCode(0);

        // Refresh the models
        $oldNegotiation->refresh();
        $recentNegotiation->refresh();

        // Assert the old negotiation was marked as expired
        $this->assertEquals('expired', $oldNegotiation->status);

        // Assert the recent negotiation was not affected
        $this->assertEquals('approved', $recentNegotiation->status);
    }

    /** @test */
    public function it_sends_notifications_when_marking_negotiations_as_expired()
    {
        // Create a user and negotiation
        $user = User::factory()->create();
        $negotiation = Negotiation::factory()->create([
            'creator_id' => $user->id,
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(31),
        ]);

        // Set up notification expectations
        $this->notificationService->shouldReceive('sendToRole')
            ->once()
            ->with('commercial', Mockery::on(function ($notification) use ($negotiation) {
                return $notification['title'] === 'Negociação Expirada' &&
                       str_contains($notification['body'], $negotiation->id);
            }));

        $this->notificationService->shouldReceive('sendToUser')
            ->once()
            ->with($user->id, Mockery::on(function ($notification) use ($negotiation) {
                return $notification['title'] === 'Negociação Expirada' &&
                       str_contains($notification['body'], $negotiation->id);
            }));

        // Run the command
        $this->artisan('negotiations:cleanup')->assertExitCode(0);

        // Verify that the notifications were sent
        $this->notificationService->shouldHaveReceived('sendToRole')->once();
        $this->notificationService->shouldHaveReceived('sendToUser')->once();
    }

    /** @test */
    public function it_respects_custom_days_parameter()
    {
        // Create a user
        $user = User::factory()->create();

        // Create negotiations with different ages
        $veryOldNegotiation = Negotiation::factory()->create([
            'creator_id' => $user->id,
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(61),
        ]);

        $oldNegotiation = Negotiation::factory()->create([
            'creator_id' => $user->id,
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(45),
        ]);

        // Run the command with custom days parameter
        $this->artisan('negotiations:cleanup', ['--days' => 60])
            ->expectsOutput('Looking for negotiations older than 60 days...')
            ->expectsOutput("Found 1 old negotiations.")
            ->expectsOutput("Processing negotiation #{$veryOldNegotiation->id}...")
            ->expectsOutput("Negotiation #{$veryOldNegotiation->id} marked as expired.")
            ->expectsOutput('Cleanup completed successfully.')
            ->assertExitCode(0);

        // Refresh the models
        $veryOldNegotiation->refresh();
        $oldNegotiation->refresh();

        // Assert only the very old negotiation was marked as expired
        $this->assertEquals('expired', $veryOldNegotiation->status);
        $this->assertEquals('approved', $oldNegotiation->status);
    }

    /** @test */
    public function it_handles_no_old_negotiations()
    {
        // Create a recent negotiation
        $negotiation = Negotiation::factory()->create([
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(15),
        ]);

        // Run the command
        $this->artisan('negotiations:cleanup')
            ->expectsOutput('Looking for negotiations older than 30 days...')
            ->expectsOutput('No old negotiations found.')
            ->assertExitCode(0);

        // Verify the negotiation was not affected
        $negotiation->refresh();
        $this->assertEquals('approved', $negotiation->status);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 