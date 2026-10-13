<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppointmentSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_hours_breaks_service_buffers_and_rescheduling_are_enforced(): void
    {
        [$user, $business, $branch] = $this->salonContext();
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $service = $this->postJson('/api/v1/products', [
            'sku' => 'CUT-001',
            'name' => 'Cut and beard',
            'unit_price' => 1800,
            'product_type' => 'service',
            'service_duration_minutes' => 30,
            'service_buffer_minutes' => 15,
        ])->assertCreated()->json('data');

        $day = now()->next('Monday')->dayOfWeek;
        $this->putJson('/api/v1/staff-schedules', [
            'branch_id' => $branch->id,
            'staff_user_id' => $user->id,
            'day_of_week' => $day,
            'is_working' => true,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
            'break_starts_at' => '12:00',
            'break_ends_at' => '13:00',
        ])->assertOk();

        $startsAt = now()->setTimezone($business->timezone)->next('Monday')->setTime(10, 0);
        $appointment = $this->postJson('/api/v1/appointments', [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'starts_at' => $startsAt->toIso8601String(),
        ])->assertCreated()
            ->assertJsonPath('data.buffer_minutes', 15)
            ->assertJsonPath('data.branch_id', $branch->id)
            ->json('data');

        $this->postJson('/api/v1/appointments', [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'starts_at' => $startsAt->copy()->addMinutes(40)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('staff_user_id');

        $this->postJson('/api/v1/appointments', [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'starts_at' => $startsAt->copy()->setTime(12, 30)->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors('starts_at');

        $this->putJson('/api/v1/appointments/'.$appointment['id'], [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'starts_at' => $startsAt->copy()->setTime(14, 0)->toIso8601String(),
        ])->assertOk()->assertJsonStructure(['data' => ['starts_at']]);

        $checkIn = $this->postJson('/api/v1/appointments/'.$appointment['id'].'/check-in')->assertOk();
        $this->assertNotNull($checkIn->json('data.checked_in_at'));
        $this->patchJson('/api/v1/appointments/'.$appointment['id'].'/status', ['status' => 'in_progress'])
            ->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->putJson('/api/v1/appointments/'.$appointment['id'], [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'starts_at' => $startsAt->copy()->setTime(15, 0)->toIso8601String(),
        ])->assertUnprocessable();
    }

    public function test_walk_ins_enter_the_check_in_queue_and_branch_ids_are_tenant_scoped(): void
    {
        [$user, $business, $branch] = $this->salonContext();
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $service = $this->postJson('/api/v1/products', [
            'sku' => 'TRIM-001',
            'name' => 'Beard trim',
            'unit_price' => 800,
            'product_type' => 'service',
        ])->assertCreated()->json('data');

        $walkIn = $this->postJson('/api/v1/appointments', [
            'branch_id' => $branch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'walk_in' => true,
        ])->assertCreated()->assertJsonPath('data.booking_source', 'walk_in');
        $this->assertNotNull($walkIn->json('data.checked_in_at'));

        [$otherUser, $otherBusiness, $otherBranch] = $this->salonContext();
        $this->postJson('/api/v1/appointments', [
            'branch_id' => $otherBranch->id,
            'service_product_id' => $service['id'],
            'staff_user_id' => $user->id,
            'walk_in' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }

    public function test_paid_sales_create_commissions_and_refunds_create_idempotent_reversals(): void
    {
        [$user, $business] = $this->salonContext();
        $this->actingAs($user, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $product = $this->postJson('/api/v1/products', [
            'sku' => 'WAX-001',
            'name' => 'Hair wax',
            'unit_price' => 1000,
            'quantity_on_hand' => 3,
            'commission_type' => 'percentage',
            'commission_value' => 10,
        ])->assertCreated()->json('data');
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $sale = $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [[
                'product_id' => $product['id'],
                'quantity' => 3,
                'performed_by_user_id' => $user->id,
            ]],
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('commission_entries', [
            'business_id' => $business->id,
            'sale_id' => $sale['id'],
            'staff_user_id' => $user->id,
            'basis_amount' => '3000.00',
            'earned_amount' => '300.00',
            'status' => 'pending',
        ]);
        $saleItemId = $sale['items'][0]['id'];
        $firstRefund = $this->postJson('/api/v1/sales/'.$sale['id'].'/refunds', [
            'reason' => 'One returned item',
            'items' => [['sale_item_id' => $saleItemId, 'quantity' => 1]],
        ])->assertCreated()->json('data');
        $this->assertDatabaseHas('commission_entries', ['sale_item_id' => $saleItemId, 'reversed_amount' => '100.00']);
        $this->assertDatabaseHas('commission_adjustments', ['refund_id' => $firstRefund['id'], 'amount' => '100.00']);

        $entryId = (int) DB::table('commission_entries')->where('sale_item_id', $saleItemId)->value('id');
        $this->patchJson('/api/v1/commissions/'.$entryId.'/status', ['status' => 'approved'])->assertOk();
        $this->patchJson('/api/v1/commissions/'.$entryId.'/status', [
            'status' => 'paid',
            'payout_reference' => 'PAY-2026-001',
        ])->assertOk()->assertJsonPath('data.status', 'paid');

        $this->postJson('/api/v1/sales/'.$sale['id'].'/refunds', [
            'reason' => 'Remaining items returned',
            'items' => [['sale_item_id' => $saleItemId, 'quantity' => 2]],
        ])->assertCreated();
        $this->assertDatabaseHas('commission_entries', [
            'sale_item_id' => $saleItemId,
            'earned_amount' => '300.00',
            'reversed_amount' => '300.00',
            'status' => 'paid',
            'payout_reference' => 'PAY-2026-001',
        ]);
        $this->assertDatabaseCount('commission_adjustments', 2);
        $this->getJson('/api/v1/commissions')->assertOk()
            ->assertJsonPath('meta.summary.earned', '300.00')
            ->assertJsonPath('meta.summary.reversed', '300.00');
    }

    private function salonContext(): array
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'Barber Shop', 'industry' => 'barbershop', 'timezone' => 'Africa/Nairobi']);
        $business->settings = ['enabled_modules' => ['appointments']];
        $business->save();
        $user->businesses()->attach($business, ['role' => 'owner']);
        $branch = Branch::create([
            'business_id' => $business->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
            'is_default' => true,
            'is_active' => true,
        ]);

        return [$user, $business, $branch];
    }
}
