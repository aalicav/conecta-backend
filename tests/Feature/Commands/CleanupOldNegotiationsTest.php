<?php

namespace Tests\Feature\Commands;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Mockery;

class CleanupOldNegotiationsTest extends TestCase
{
    use RefreshDatabase;

    protected $notificationService;

    public function setUp(): void
    {
        parent::setUp();

        // Mock the notification service
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->app->instance(NotificationService::class, $this->notificationService);

        // Expect notifications to be sent
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
        Artisan::call('negotiations:cleanup', ['--days' => 30]);

        // Refresh the models
        $oldNegotiation->refresh();
        $recentNegotiation->refresh();

        // Assert the old negotiation was marked as expired
        $this->assertEquals('expired', $oldNegotiation->status);

        // Assert the recent negotiation was not affected
        $this->assertEquals('approved', $recentNegotiation->status);
    }

    /** @test */
    public function it_only_affects_approved_unformalized_negotiations()
    {
        // Create negotiations in different states
        $approvedUnformalized = Negotiation::factory()->create([
            'status' => 'approved',
            'formalization_status' => 'pending_aditivo',
            'approved_at' => now()->subDays(31),
        ]);

        $approvedFormalized = Negotiation::factory()->create([
            'status' => 'approved',
            'formalization_status' => 'formalized',
            'approved_at' => now()->subDays(31),
        ]);

        $pendingOld = Negotiation::factory()->create([
            'status' => 'pending',
            'formalization_status' => null,
            'created_at' => now()->subDays(31),
        ]);

        // Run the command
        Artisan::call('negotiations:cleanup', ['--days' => 30]);

        // Refresh the models
        $approvedUnformalized->refresh();
        $approvedFormalized->refresh();
        $pendingOld->refresh();

        // Assert only the approved unformalized negotiation was affected
        $this->assertEquals('expired', $approvedUnformalized->status);
        $this->assertEquals('approved', $approvedFormalized->status);
        $this->assertEquals('pending', $pendingOld->status);
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

        // Set up expectations for notifications
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
        Artisan::call('negotiations:cleanup', ['--days' => 30]);

        // Verify that the notifications were sent
        $this->notificationService->shouldHaveReceived('sendToRole')->once();
        $this->notificationService->shouldHaveReceived('sendToUser')->once();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 