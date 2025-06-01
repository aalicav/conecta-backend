<?php

namespace Tests\Unit\Observers;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Observers\NegotiationObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Mockery;

class NegotiationObserverTest extends TestCase
{
    use RefreshDatabase;

    protected $observer;
    protected $negotiation;
    protected $user;

    public function setUp(): void
    {
        parent::setUp();

        $this->observer = new NegotiationObserver();

        // Create a user
        $this->user = User::factory()->create();
        Auth::login($this->user);

        // Create a negotiation
        $this->negotiation = Negotiation::factory()->create([
            'creator_id' => $this->user->id,
            'status' => 'draft',
        ]);
    }

    /** @test */
    public function it_logs_when_negotiation_is_created()
    {
        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Trigger created event
        $this->observer->created($this->negotiation);

        // Verify that the correct log was made
        $logSpy->shouldHaveReceived('info')
            ->with('Negotiation created', [
                'negotiation_id' => $this->negotiation->id,
                'creator_id' => $this->user->id,
                'status' => 'draft'
            ]);
    }

    /** @test */
    public function it_logs_when_negotiation_status_changes()
    {
        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Change negotiation status
        $this->negotiation->status = 'approved';
        
        // Trigger updated event
        $this->observer->updated($this->negotiation);

        // Verify that the correct log was made
        $logSpy->shouldHaveReceived('info')
            ->with('Negotiation status changed', [
                'negotiation_id' => $this->negotiation->id,
                'old_status' => 'draft',
                'new_status' => 'approved',
                'user_id' => $this->user->id
            ]);
    }

    /** @test */
    public function it_logs_when_approval_level_changes()
    {
        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Change approval level
        $this->negotiation->approval_level = 'pending_approval';
        
        // Trigger updated event
        $this->observer->updated($this->negotiation);

        // Verify that the correct log was made
        $logSpy->shouldHaveReceived('info')
            ->with('Negotiation approval level changed', [
                'negotiation_id' => $this->negotiation->id,
                'old_level' => null,
                'new_level' => 'pending_approval',
                'user_id' => $this->user->id
            ]);
    }

    /** @test */
    public function it_logs_when_formalization_status_changes()
    {
        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Change formalization status
        $this->negotiation->formalization_status = 'pending_aditivo';
        
        // Trigger updated event
        $this->observer->updated($this->negotiation);

        // Verify that the correct log was made
        $logSpy->shouldHaveReceived('info')
            ->with('Negotiation formalization status changed', [
                'negotiation_id' => $this->negotiation->id,
                'old_status' => null,
                'new_status' => 'pending_aditivo',
                'user_id' => $this->user->id
            ]);
    }

    /** @test */
    public function it_logs_when_negotiation_is_deleted()
    {
        // Spy on the Log facade
        $logSpy = Mockery::spy('Illuminate\Support\Facades\Log');
        Log::swap($logSpy);

        // Trigger deleted event
        $this->observer->deleted($this->negotiation);

        // Verify that the correct log was made
        $logSpy->shouldHaveReceived('info')
            ->with('Negotiation deleted', [
                'negotiation_id' => $this->negotiation->id,
                'user_id' => $this->user->id
            ]);
    }

    /** @test */
    public function it_records_audit_entries()
    {
        // Mock the recordAudit method on the negotiation
        $this->negotiation = Mockery::mock(Negotiation::class)->makePartial();
        $this->negotiation->shouldReceive('recordAudit')
            ->once()
            ->with('created', [], $this->negotiation->toArray());

        // Trigger created event
        $this->observer->created($this->negotiation);

        // Change status and trigger updated event
        $this->negotiation->shouldReceive('getDirty')->andReturn(['status' => 'approved']);
        $this->negotiation->shouldReceive('getOriginal')->andReturn(['status' => 'draft']);
        $this->negotiation->shouldReceive('recordAudit')
            ->once()
            ->with('updated', ['status' => 'draft'], ['status' => 'approved']);

        $this->observer->updated($this->negotiation);

        // Verify that the audit entries were recorded
        $this->negotiation->shouldHaveReceived('recordAudit')->twice();
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 