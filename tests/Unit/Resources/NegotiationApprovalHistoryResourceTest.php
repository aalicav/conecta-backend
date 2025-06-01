<?php

namespace Tests\Unit\Resources;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Models\NegotiationApprovalHistory;
use App\Http\Resources\NegotiationApprovalHistoryResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class NegotiationApprovalHistoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected $history;
    protected $user;
    protected $negotiation;

    public function setUp(): void
    {
        parent::setUp();

        // Create a user
        $this->user = User::factory()->create();

        // Create a negotiation
        $this->negotiation = Negotiation::factory()->create([
            'creator_id' => $this->user->id,
        ]);

        // Create an approval history entry
        $this->history = NegotiationApprovalHistory::create([
            'negotiation_id' => $this->negotiation->id,
            'action' => 'approve',
            'user_id' => $this->user->id,
            'notes' => 'Approved by user',
            'created_at' => now(),
        ]);

        // Load the user relationship
        $this->history->load('user');
    }

    /** @test */
    public function it_transforms_approval_history_correctly()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/approval-history');
        
        $resource = new NegotiationApprovalHistoryResource($this->history);
        $array = $resource->toArray($request);

        $this->assertEquals($this->history->id, $array['id']);
        $this->assertEquals($this->history->action, $array['action']);
        $this->assertEquals($this->history->notes, $array['notes']);
        $this->assertEquals($this->history->created_at, $array['created_at']);

        // Check user information
        $this->assertArrayHasKey('user', $array);
        $this->assertEquals($this->user->id, $array['user']['id']);
        $this->assertEquals($this->user->name, $array['user']['name']);
        $this->assertEquals($this->user->email, $array['user']['email']);
    }

    /** @test */
    public function it_handles_null_values_correctly()
    {
        $history = NegotiationApprovalHistory::create([
            'negotiation_id' => $this->negotiation->id,
            'action' => 'submit_for_approval',
            'user_id' => $this->user->id,
            'notes' => null,
        ]);

        $history->load('user');

        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/approval-history');
        
        $array = (new NegotiationApprovalHistoryResource($history))->toArray($request);

        $this->assertNull($array['notes']);
    }

    /** @test */
    public function it_includes_user_information_correctly()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/approval-history');
        
        $array = (new NegotiationApprovalHistoryResource($this->history))->toArray($request);

        $this->assertArrayHasKey('user', $array);
        $this->assertEquals([
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
        ], $array['user']);
    }

    /** @test */
    public function it_formats_dates_correctly()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/approval-history');
        
        $array = (new NegotiationApprovalHistoryResource($this->history))->toArray($request);

        $this->assertInstanceOf(\Carbon\Carbon::class, $array['created_at']);
    }
} 