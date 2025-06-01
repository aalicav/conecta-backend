<?php

namespace Tests\Unit\Events;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Events\NegotiationApproved;
use App\Listeners\HandleNegotiationApproval;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class NegotiationApprovedTest extends TestCase
{
    use RefreshDatabase;

    protected $notificationService;
    protected $listener;
    protected $negotiation;
    protected $user;

    public function setUp(): void
    {
        parent::setUp();

        // Mock the notification service
        $this->notificationService = Mockery::mock(NotificationService::class);
        $this->app->instance(NotificationService::class, $this->notificationService);

        // Create the listener
        $this->listener = new HandleNegotiationApproval($this->notificationService);

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
    public function it_sends_notifications_when_negotiation_is_approved()
    {
        // Set up expectations for notifications
        $this->notificationService->shouldReceive('sendToUser')
            ->once()
            ->with($this->user->id, Mockery::on(function ($notification) {
                return $notification['title'] === 'Negociação Aprovada' &&
                       str_contains($notification['body'], $this->negotiation->id);
            }));

        $this->notificationService->shouldReceive('sendToRole')
            ->once()
            ->with('commercial', Mockery::on(function ($notification) {
                return $notification['title'] === 'Negociação Aguardando Formalização' &&
                       str_contains($notification['body'], $this->negotiation->id);
            }));

        // Create and handle the event
        $event = new NegotiationApproved($this->negotiation);
        $this->listener->handle($event);

        // Verify that the notifications were sent
        $this->notificationService->shouldHaveReceived('sendToUser')->once();
        $this->notificationService->shouldHaveReceived('sendToRole')->once();
    }

    /** @test */
    public function it_records_approval_in_history()
    {
        // Create and handle the event
        $event = new NegotiationApproved($this->negotiation);
        $this->listener->handle($event);

        // Get the latest history entry
        $history = $this->negotiation->approvalHistory()->latest()->first();

        // Assert the history entry was created correctly
        $this->assertNotNull($history);
        $this->assertEquals('approve', $history->action);
        $this->assertEquals($this->negotiation->approved_by, $history->user_id);
        $this->assertEquals('Negotiation approved', $history->notes);
    }

    /** @test */
    public function it_dispatches_formalization_job()
    {
        // Create and handle the event
        $event = new NegotiationApproved($this->negotiation);
        $this->listener->handle($event);

        // Assert that the job was dispatched
        $this->assertDatabaseHas('jobs', [
            'queue' => 'default',
            'payload' => Mockery::pattern('/ProcessNegotiationFormalization/'),
        ]);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 