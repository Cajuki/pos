<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\BusinessPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalonStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_profile_only_staff_member_with_scoped_assignments(): void
    {
        [$user, $business, $branch] = $this->salonContext();
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $title = $this->getJson('/api/v1/salon/job-titles')->assertOk()->json('data.0');
        $service = $this->postJson('/api/v1/products', [
            'sku' => 'SALON-CUT-01', 'name' => 'Haircut', 'unit_price' => 1500,
            'product_type' => 'service', 'service_duration_minutes' => 30,
        ])->assertCreated()->json('data');

        $response = $this->postJson('/api/v1/salon/staff', [
            'name' => 'Maya Stylist', 'phone' => '+254700000001', 'employment_type' => 'full_time',
            'employment_status' => 'active', 'job_title_ids' => [$title['id']],
            'branch_ids' => [$branch->id], 'service_ids' => [$service['id']],
        ])->assertCreated()->assertJsonPath('data.account_status', 'profile_only')
            ->assertJsonPath('data.job_titles.0.name', $title['name'])
            ->assertJsonPath('data.services.0.name', 'Haircut');

        $this->assertDatabaseHas('staff_profiles', ['business_id' => $business->id, 'name' => 'Maya Stylist', 'user_id' => null]);
        $this->assertDatabaseHas('staff_profile_branches', ['staff_profile_id' => $response->json('data.id'), 'business_id' => $business->id, 'branch_id' => $branch->id]);
        $this->assertDatabaseHas('staff_service_assignments', ['staff_profile_id' => $response->json('data.id'), 'business_id' => $business->id, 'product_id' => $service['id']]);
        $this->getJson('/api/v1/salon/staff')->assertOk()->assertJsonPath('meta.summary.total', 1);
    }

    public function test_staff_assignments_are_tenant_scoped_and_salary_is_hidden_from_manager(): void
    {
        [$user, $business, $branch] = $this->salonContext();
        [$otherUser, $otherBusiness, $otherBranch] = $this->salonContext();
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $title = $this->getJson('/api/v1/salon/job-titles')->assertOk()->json('data.0');

        $this->postJson('/api/v1/salon/staff', [
            'name' => 'Cross Tenant Attempt', 'employment_type' => 'contract', 'employment_status' => 'active',
            'job_title_ids' => [$title['id']], 'branch_ids' => [$otherBranch->id], 'service_ids' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_ids.0');

        $user->businesses()->updateExistingPivot($business->id, ['role' => 'manager']);
        $created = $this->postJson('/api/v1/salon/staff', [
            'name' => 'Manager Created Profile', 'employment_type' => 'part_time', 'employment_status' => 'active',
            'job_title_ids' => [$title['id']], 'branch_ids' => [$branch->id], 'service_ids' => [],
        ])->assertCreated();
        $this->assertArrayNotHasKey('salary_amount', $created->json('data'));

        $otherTitle = $this->actingAs($otherUser, 'sanctum')->withHeader('X-Business-ID', $otherBusiness->id)
            ->getJson('/api/v1/salon/job-titles')->assertOk()->json('data.0');
        $this->putJson('/api/v1/salon/staff/'.$created->json('data.id'), [
            'name' => 'Unauthorized edit', 'employment_type' => 'full_time', 'employment_status' => 'active',
            'job_title_ids' => [$otherTitle['id']], 'branch_ids' => [$otherBranch->id], 'service_ids' => [],
        ])->assertNotFound();
    }

    public function test_profile_only_staff_can_be_assigned_to_qualified_services_and_appointments(): void
    {
        [$user, $business, $branch] = $this->salonContext();
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $title = $this->getJson('/api/v1/salon/job-titles')->assertOk()->json('data.0');
        $service = $this->postJson('/api/v1/products', [
            'sku' => 'PROFILE-ONLY-SVC', 'name' => 'Wash and style', 'unit_price' => 1200,
            'product_type' => 'service', 'service_duration_minutes' => 45,
        ])->assertCreated()->json('data');
        $profile = $this->postJson('/api/v1/salon/staff', [
            'name' => 'No Login Stylist', 'employment_type' => 'part_time', 'employment_status' => 'active',
            'job_title_ids' => [$title['id']], 'branch_ids' => [$branch->id], 'service_ids' => [$service['id']],
        ])->assertCreated()->json('data');

        $appointment = $this->postJson('/api/v1/appointments', [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_profile_id' => $profile['id'],
            'starts_at' => now()->addDays(2)->setTime(10, 0)->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.staff_user_id', null)
            ->assertJsonPath('data.staff_profile_id', $profile['id'])->json('data');

        $this->assertDatabaseHas('appointments', ['id' => $appointment['id'], 'staff_profile_id' => $profile['id'], 'staff_user_id' => null]);
    }

    public function test_system_permissions_are_stored_separately_from_the_job_title(): void
    {
        [$owner, $business, $branch] = $this->salonContext();
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $title = $this->getJson('/api/v1/salon/job-titles')->assertOk()->json('data.0');
        $employee = User::factory()->create();
        $employee->businesses()->attach($business, ['role' => 'cashier']);
        $profile = $this->postJson('/api/v1/salon/staff', [
            'name' => $employee->name, 'email' => $employee->email, 'employment_type' => 'full_time',
            'employment_status' => 'active', 'job_title_ids' => [$title['id']], 'branch_ids' => [$branch->id],
            'service_ids' => [], 'permission_overrides' => ['appointments.manage'],
        ])->assertCreated()->json('data');
        StaffProfile::query()->whereKey($profile['id'])->update(['user_id' => $employee->id]);

        $permissions = app(BusinessPermissions::class);
        $this->assertTrue($permissions->allows($employee, $business, 'appointments.manage'));
        $this->assertFalse($permissions->allows($employee, $business, 'staff.manage'));
    }

    private function salonContext(): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Salon', 'industry' => 'salon', 'timezone' => 'Africa/Nairobi']);
        $business->settings = ['enabled_modules' => ['appointments']];
        $business->save();
        $user->businesses()->attach($business, ['role' => 'owner']);
        $branch = Branch::create(['business_id' => $business->id, 'name' => 'Main', 'code' => 'MAIN', 'is_default' => true, 'is_active' => true]);

        return [$user, $business, $branch];
    }
}
