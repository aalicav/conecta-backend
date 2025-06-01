<?php

namespace Tests\Unit\Resources;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Http\Resources\NegotiationResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class NegotiationResourceTest extends TestCase
{
    use RefreshDatabase;

    protected $negotiation;
    protected $creator;
    protected $approver;

    public function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'approve_negotiations']);

        // Create roles
        $approverRole = Role::create(['name' => 'approver']);
        $approverRole->givePermissionTo('approve_negotiations');

        // Create users
        $this->creator = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->approver->assignRole('approver');

        // Create a negotiation
        $this->negotiation = Negotiation::factory()->create([
            'creator_id' => $this->creator->id,
            'status' => 'approved',
            'approval_level' => null,
            'formalization_status' => 'pending_aditivo',
            'approved_by' => $this->approver->id,
            'approved_at' => now(),
            'approval_notes' => 'Approved by approver',
        ]);
    }

    /** @test */
    public function it_transforms_negotiation_correctly()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id);
        $request->setUserResolver(fn () => $this->creator);

        $resource = new NegotiationResource($this->negotiation);
        $array = $resource->toArray($request);

        $this->assertEquals($this->negotiation->id, $array['id']);
        $this->assertEquals($this->negotiation->status, $array['status']);
        $this->assertEquals($this->negotiation->approval_level, $array['approval_level']);
        $this->assertEquals($this->negotiation->formalization_status, $array['formalization_status']);
        
        // Check approval information
        $this->assertEquals($this->approver->id, $array['approved_by']['id']);
        $this->assertEquals($this->approver->name, $array['approved_by']['name']);
        $this->assertEquals($this->approver->email, $array['approved_by']['email']);
        $this->assertEquals($this->negotiation->approved_at, $array['approved_at']);
        $this->assertEquals($this->negotiation->approval_notes, $array['approval_notes']);
    }

    /** @test */
    public function it_includes_correct_permissions()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id);
        
        // Test with creator
        $request->setUserResolver(fn () => $this->creator);
        $array = (new NegotiationResource($this->negotiation))->toArray($request);
        
        $this->assertFalse($array['can_approve']);
        $this->assertTrue($array['can_edit']);

        // Test with approver
        $request->setUserResolver(fn () => $this->approver);
        $array = (new NegotiationResource($this->negotiation))->toArray($request);
        
        $this->assertTrue($array['can_approve']);
        $this->assertFalse($array['can_edit']);
    }

    /** @test */
    public function it_handles_null_values_correctly()
    {
        $negotiation = Negotiation::factory()->create([
            'creator_id' => $this->creator->id,
            'status' => 'draft',
            'approval_level' => null,
            'formalization_status' => null,
            'approved_by' => null,
            'approved_at' => null,
            'approval_notes' => null,
        ]);

        $request = Request::create('/api/negotiations/' . $negotiation->id);
        $request->setUserResolver(fn () => $this->creator);

        $array = (new NegotiationResource($negotiation))->toArray($request);

        $this->assertNull($array['approval_level']);
        $this->assertNull($array['formalization_status']);
        $this->assertArrayNotHasKey('approved_by', $array);
        $this->assertNull($array['approved_at']);
        $this->assertNull($array['approval_notes']);
    }

    /** @test */
    public function it_includes_related_entities()
    {
        $this->negotiation->load('creator', 'items.tuss');

        $request = Request::create('/api/negotiations/' . $this->negotiation->id);
        $request->setUserResolver(fn () => $this->creator);

        $array = (new NegotiationResource($this->negotiation))->toArray($request);

        $this->assertArrayHasKey('creator', $array);
        $this->assertEquals($this->creator->id, $array['creator']['id']);
        $this->assertEquals($this->creator->name, $array['creator']['name']);

        $this->assertArrayHasKey('items', $array);
        foreach ($array['items'] as $item) {
            $this->assertArrayHasKey('tuss', $item);
            $this->assertArrayHasKey('id', $item['tuss']);
            $this->assertArrayHasKey('code', $item['tuss']);
            $this->assertArrayHasKey('description', $item['tuss']);
        }
    }
} 