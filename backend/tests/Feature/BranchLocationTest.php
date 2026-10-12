<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_can_manage_branches_and_branch_warehouses(): void
    {
        [$user, $business] = $this->businessContext();

        $branch = $this->postJson('/api/v1/branches', [
            'name' => 'Westlands Store',
            'code' => 'west-01',
            'address' => 'Westlands, Nairobi',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'WEST-01')
            ->assertJsonPath('data.is_default', true)
            ->json('data');

        $warehouse = $this->postJson('/api/v1/warehouses', [
            'branch_id' => $branch['id'],
            'name' => 'Westlands Backroom',
            'code' => 'west-back',
        ])->assertCreated()
            ->assertJsonPath('data.branch_id', $branch['id'])
            ->assertJsonPath('data.is_default', true)
            ->json('data');

        $register = $this->postJson('/api/v1/cash-registers', [
            'branch_id' => $branch['id'],
            'name' => 'Westlands Checkout 1',
            'code' => 'WEST-TILL-1',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('warehouses', [
            'id' => $warehouse['id'],
            'business_id' => $business->id,
            'branch_id' => $branch['id'],
        ]);
        $this->assertDatabaseHas('cash_registers', [
            'id' => $register['register']['id'],
            'business_id' => $business->id,
            'branch_id' => $branch['id'],
        ]);

        $this->getJson('/api/v1/warehouses?branch_id='.$branch['id'])
            ->assertOk()
            ->assertJsonPath('data.0.id', $warehouse['id']);
    }

    public function test_branch_and_warehouse_lookups_are_scoped_to_the_active_business(): void
    {
        [$user, $business] = $this->businessContext();
        $otherBusiness = Business::create(['name' => 'Other Business']);
        $otherBranch = $otherBusiness->branches()->create([
            'name' => 'Other Branch',
            'code' => 'OTHER',
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/warehouses', [
            'branch_id' => $otherBranch->id,
            'name' => 'Cross-tenant warehouse',
            'code' => 'CROSS-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->patchJson('/api/v1/branches/'.$otherBranch->id, ['name' => 'Hijacked Branch'])->assertNotFound();
    }

    public function test_cashier_can_read_locations_but_cannot_create_a_branch(): void
    {
        [$owner, $business] = $this->businessContext();
        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business, ['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->getJson('/api/v1/branches')->assertOk();
        $this->postJson('/api/v1/branches', [
            'name' => 'Restricted Branch',
            'code' => 'NOPE',
        ])->assertForbidden();
    }

    private function businessContext(): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Location Test Store']);
        $user->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);

        return [$user, $business];
    }
}
