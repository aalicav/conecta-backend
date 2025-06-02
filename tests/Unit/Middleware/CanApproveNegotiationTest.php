<?php

namespace Tests\Unit\Middleware;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Http\Middleware\CanApproveNegotiation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Mockery;

class CanApproveNegotiationTest extends TestCase
{
    use RefreshDatabase;

    protected $middleware;
    protected $creator;
    protected $approver;
    protected $negotiation;

    public function setUp(): void
    {
        parent::setUp();

        $this->middleware = new CanApproveNegotiation();

        // Create permissions
        Permission::create(['name' => 'approve negotiations']);

        // Create roles
        $approverRole = Role::create(['name' => 'approver']);
        $approverRole->givePermissionTo('approve negotiations');

        // Create users
        $this->creator = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->approver->assignRole('approver');

        // Create a negotiation
        $this->negotiation = Negotiation::factory()->create([
            'creator_id' => $this->creator->id,
            'status' => 'submitted',
            'approval_level' => 'pending_approval',
        ]);
    }

    /** @test */
    public function it_allows_users_with_approval_permission()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/process-approval', 'POST');
        $request->setUserResolver(fn () => $this->approver);
        $request->route()->setParameter('negotiation', $this->negotiation);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(200, $response->status());
    }

    /** @test */
    public function it_denies_users_without_approval_permission()
    {
        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/process-approval', 'POST');
        $request->setUserResolver(fn () => $this->creator);
        $request->route()->setParameter('negotiation', $this->negotiation);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(403, $response->status());
        $this->assertEquals('You do not have permission to approve negotiations', json_decode($response->getContent())->message);
    }

    /** @test */
    public function it_denies_creator_from_approving_own_negotiation()
    {
        // Give creator approval permission
        $this->creator->givePermissionTo('approve negotiations');

        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/process-approval', 'POST');
        $request->setUserResolver(fn () => $this->creator);
        $request->route()->setParameter('negotiation', $this->negotiation);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(403, $response->status());
        $this->assertEquals('You cannot approve your own negotiation', json_decode($response->getContent())->message);
    }

    /** @test */
    public function it_denies_approval_for_wrong_status()
    {
        // Change negotiation status
        $this->negotiation->approval_level = null;
        $this->negotiation->save();

        $request = Request::create('/api/negotiations/' . $this->negotiation->id . '/process-approval', 'POST');
        $request->setUserResolver(fn () => $this->approver);
        $request->route()->setParameter('negotiation', $this->negotiation);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(422, $response->status());
        $this->assertEquals('This negotiation is not pending approval', json_decode($response->getContent())->message);
    }

    /** @test */
    public function it_returns_404_when_negotiation_not_found()
    {
        $request = Request::create('/api/negotiations/999/process-approval', 'POST');
        $request->setUserResolver(fn () => $this->approver);
        $request->route()->setParameter('negotiation', null);

        $response = $this->middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(404, $response->status());
        $this->assertEquals('Negotiation not found', json_decode($response->getContent())->message);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
} 