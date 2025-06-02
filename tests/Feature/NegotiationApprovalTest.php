<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Negotiation;
use App\Models\NegotiationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class NegotiationApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected $creator;
    protected $approver;
    protected $negotiation;

    public function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'create negotiations']);
        Permission::create(['name' => 'submit negotiations']);
        Permission::create(['name' => 'approve negotiations']);
        Permission::create(['name' => 'edit negotiations']);

        // Create roles
        $commercialRole = Role::create(['name' => 'commercial']);
        $approverRole = Role::create(['name' => 'approver']);

        // Assign permissions to roles
        $commercialRole->givePermissionTo(['create negotiations', 'submit negotiations', 'edit negotiations']);
        $approverRole->givePermissionTo('approve negotiations');

        // Create users
        $this->creator = User::factory()->create();
        $this->approver = User::factory()->create();

        // Assign roles to users
        $this->creator->assignRole('commercial');
        $this->approver->assignRole('approver');

        // Create a negotiation
        $this->negotiation = Negotiation::factory()->create([
            'creator_id' => $this->creator->id,
            'status' => 'draft',
        ]);

        // Add some items to the negotiation
        NegotiationItem::factory()->count(3)->create([
            'negotiation_id' => $this->negotiation->id,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function commercial_user_can_submit_negotiation_for_approval()
    {
        $response = $this->actingAs($this->creator)
            ->postJson("/api/negotiations/{$this->negotiation->id}/submit-approval");

        $response->assertStatus(200);
        $this->assertEquals('pending_approval', $response->json('data.approval_level'));
    }

    /** @test */
    public function creator_cannot_approve_own_negotiation()
    {
        // First submit for approval
        $this->actingAs($this->creator)
            ->postJson("/api/negotiations/{$this->negotiation->id}/submit-approval");

        // Try to approve own negotiation
        $response = $this->actingAs($this->creator)
            ->postJson("/api/negotiations/{$this->negotiation->id}/process-approval", [
                'approved' => true,
                'approval_notes' => 'Approved',
            ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function approver_can_approve_negotiation()
    {
        // First submit for approval
        $this->actingAs($this->creator)
            ->postJson("/api/negotiations/{$this->negotiation->id}/submit-approval");

        // Approve the negotiation
        $response = $this->actingAs($this->approver)
            ->postJson("/api/negotiations/{$this->negotiation->id}/process-approval", [
                'approved' => true,
                'approval_notes' => 'Approved by approver',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('approved', $response->json('data.status'));
        $this->assertEquals($this->approver->id, $response->json('data.approved_by.id'));
    }

    /** @test */
    public function approver_can_reject_negotiation()
    {
        // First submit for approval
        $this->actingAs($this->creator)
            ->postJson("/api/negotiations/{$this->negotiation->id}/submit-approval");

        // Reject the negotiation
        $response = $this->actingAs($this->approver)
            ->postJson("/api/negotiations/{$this->negotiation->id}/process-approval", [
                'approved' => false,
                'approval_notes' => 'Rejected by approver',
            ]);

        $response->assertStatus(200);
        $this->assertEquals('rejected', $response->json('data.status'));
        $this->assertEquals($this->approver->id, $response->json('data.rejected_by.id'));
    }

    /** @test */
    public function approval_history_is_recorded()
    {
        // Submit for approval
        $this->actingAs($this->creator)
            ->postJson("/api/negotiations/{$this->negotiation->id}/submit-approval");

        // Approve the negotiation
        $this->actingAs($this->approver)
            ->postJson("/api/negotiations/{$this->negotiation->id}/process-approval", [
                'approved' => true,
                'approval_notes' => 'Approved by approver',
            ]);

        // Get approval history
        $response = $this->actingAs($this->creator)
            ->getJson("/api/negotiations/{$this->negotiation->id}/approval-history");

        $response->assertStatus(200);
        $history = $response->json('data');
        
        $this->assertCount(2, $history);
        $this->assertEquals('submit_for_approval', $history[1]['action']);
        $this->assertEquals('approve', $history[0]['action']);
        $this->assertEquals($this->creator->id, $history[1]['user']['id']);
        $this->assertEquals($this->approver->id, $history[0]['user']['id']);
    }
} 