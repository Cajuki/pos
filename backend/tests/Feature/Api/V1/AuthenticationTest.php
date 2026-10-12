<?php

namespace Tests\Feature\Api\V1;

use App\Mail\TeamInvitationMail;
use App\Models\Business;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_registration_creates_owner_membership_and_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register-business', [
            'business_name' => 'Demo Retail Ltd',
            'name' => 'Amina Mwangi',
            'email' => 'amina@example.test',
            'password' => 'Correct-horse-2026!',
            'password_confirmation' => 'Correct-horse-2026!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.business.name', 'Demo Retail Ltd')
            ->assertJsonPath('data.business.currency', 'KES')
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email'], 'business' => ['id']]]);

        $this->assertDatabaseHas('business_user', ['role' => 'owner']);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => User::class]);
        $this->assertDatabaseHas('business_subscriptions', [
            'business_id' => $response->json('data.business.id'),
            'status' => 'active',
        ]);
    }

    public function test_business_registration_configures_industry_currency_and_profile(): void
    {
        $response = $this->postJson('/api/v1/auth/register-business', [
            'business_name' => 'Harbor Pharmacy',
            'industry' => 'pharmacy',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'business_phone' => '+1 555 0100',
            'business_email' => 'hello@harbor.example',
            'business_address' => '12 Main Street',
            'name' => 'Amina Mwangi',
            'email' => 'owner@example.test',
            'password' => 'Correct-horse-2026!',
            'password_confirmation' => 'Correct-horse-2026!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.business.industry', 'pharmacy')
            ->assertJsonPath('data.business.currency', 'USD')
            ->assertJsonPath('data.business.timezone', 'America/New_York')
            ->assertJsonPath('data.business.modules.0', 'products');
        $this->assertDatabaseHas('businesses', [
            'id' => $response->json('data.business.id'),
            'industry' => 'pharmacy',
            'currency' => 'USD',
        ]);
        $this->assertDatabaseHas('subscription_plans', ['code' => 'starter']);
    }

    public function test_business_registration_rejects_unknown_industries(): void
    {
        $this->postJson('/api/v1/auth/register-business', [
            'business_name' => 'Unknown Retail',
            'industry' => 'unknown-kind',
            'name' => 'Amina Mwangi',
            'email' => 'unknown@example.test',
            'password' => 'Correct-horse-2026!',
            'password_confirmation' => 'Correct-horse-2026!',
        ])->assertUnprocessable()->assertJsonValidationErrors('industry');
        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_business_registration_rejects_passwords_that_do_not_meet_security_requirements(): void
    {
        $this->postJson('/api/v1/auth/register-business', [
            'business_name' => 'Demo Retail Ltd',
            'name' => 'Amina Mwangi',
            'email' => 'amina@example.test',
            'password' => 'alllowercasepassword',
            'password_confirmation' => 'alllowercasepassword',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('businesses', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_issues_token_and_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonStructure(['data' => ['token', 'user', 'businesses']]);
    }

    public function test_platform_admin_login_includes_authorized_business_workspaces(): void
    {
        $platformAdmin = User::factory()->create([
            'password' => 'correct-horse-battery',
            'is_platform_admin' => true,
        ]);
        $business = Business::create(['name' => 'Platform Managed Store']);

        $this->postJson('/api/v1/auth/login', [
            'email' => $platformAdmin->email,
            'password' => 'correct-horse-battery',
        ])->assertOk()
            ->assertJsonPath('data.businesses.0.id', $business->id)
            ->assertJsonPath('data.businesses.0.role', 'platform_admin')
            ->assertJsonPath('data.businesses.0.permissions.0', '*');
    }

    public function test_business_context_is_limited_to_authenticated_members(): void
    {
        $user = User::factory()->create();
        $otherBusiness = Business::create(['name' => 'Private Business']);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->withHeader('X-Business-ID', $otherBusiness->id)
            ->getJson('/api/v1/business/current')
            ->assertNotFound();

        $business = Business::create(['name' => 'Member Business']);
        $user->businesses()->attach($business, ['role' => 'manager']);

        $this->withToken($token)
            ->withHeader('X-Business-ID', $business->id)
            ->getJson('/api/v1/business/current')
            ->assertOk()
            ->assertJsonPath('data.id', $business->id)
            ->assertJsonPath('data.role', 'manager');
    }

    public function test_business_owner_can_create_and_list_staff_members(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = Business::create(['name' => 'Staff Store']);
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->postJson('/api/v1/business/staff', [
            'name' => 'Sam Cashier',
            'email' => 'sam@example.test',
            'role' => 'cashier',
        ])->assertStatus(202)
            ->assertJsonPath('data.role', 'cashier')
            ->assertJsonPath('data.status', 'invited');

        Mail::assertSent(TeamInvitationMail::class, fn (TeamInvitationMail $mail): bool => $mail->hasTo('sam@example.test')
            && str_contains($mail->invitationUrl, '/accept-invite?token='));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('team_invitations', ['email' => 'sam@example.test', 'role' => 'cashier']);
        $this->getJson('/api/v1/business/staff')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.email', 'sam@example.test')
            ->assertJsonPath('data.1.status', 'invited');
    }

    public function test_invited_team_member_can_accept_and_create_their_own_password(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = Business::create(['name' => 'Staff Store']);
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->postJson('/api/v1/business/staff', [
            'name' => 'Sam Cashier',
            'email' => 'sam@example.test',
            'role' => 'cashier',
        ])->assertStatus(202);

        $mail = Mail::sent(TeamInvitationMail::class)->first();
        parse_str((string) parse_url($mail->invitationUrl, PHP_URL_QUERY), $query);
        $token = $query['token'];

        $this->getJson('/api/v1/auth/team-invitations/'.$token)
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Staff Store')
            ->assertJsonPath('data.email', 'sam@example.test');

        $this->postJson('/api/v1/auth/team-invitations/'.$token.'/accept', [
            'password' => 'Strong-pass-2026!',
            'password_confirmation' => 'Strong-pass-2026!',
        ])->assertOk()
            ->assertJsonPath('data.business.id', $business->id)
            ->assertJsonPath('data.business.role', 'cashier')
            ->assertJsonPath('data.user.email', 'sam@example.test');

        $this->assertDatabaseHas('business_user', ['business_id' => $business->id, 'role' => 'cashier']);
        $this->assertDatabaseMissing('team_invitations', ['email' => 'sam@example.test', 'accepted_at' => null]);
        $this->postJson('/api/v1/auth/team-invitations/'.$token.'/accept', [
            'password' => 'Strong-pass-2026!',
            'password_confirmation' => 'Strong-pass-2026!',
        ])->assertStatus(410);
    }

    public function test_non_admin_member_cannot_manage_staff(): void
    {
        $manager = User::factory()->create();
        $business = Business::create(['name' => 'Staff Store']);
        $manager->businesses()->attach($business, ['role' => 'manager']);
        $this->actingAs($manager, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->postJson('/api/v1/business/staff', [
            'name' => 'Sam Cashier',
            'email' => 'sam@example.test',
            'role' => 'cashier',
        ])->assertForbidden();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_staff_invitations_enforce_the_active_subscription_user_limit(): void
    {
        Mail::fake();
        $owner = User::factory()->create();
        $business = Business::create(['name' => 'Limited Team Store']);
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $business->subscriptions()->create([
            'subscription_plan_id' => SubscriptionPlan::query()->where('code', 'starter')->value('id'),
            'status' => 'active',
            'starts_at' => now(),
        ]);
        $business->activeSubscription()->firstOrFail()->plan->update([
            'limits' => ['users' => 1, 'products' => 2000, 'locations' => 1],
        ]);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->postJson('/api/v1/business/staff', [
            'name' => 'Sam Cashier',
            'email' => 'sam@example.test',
            'role' => 'cashier',
        ])->assertUnprocessable()->assertJsonValidationErrors('team');

        $this->assertDatabaseCount('team_invitations', 0);
        Mail::assertNothingSent();
    }

    public function test_business_admin_can_save_pos_settings_for_all_members_to_use(): void
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => 'Settings Store']);
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $settings = [
            'name' => 'Updated Settings Store',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
            'settings' => [
                'business_phone' => '+1 555 0100',
                'business_email' => 'store@example.test',
                'business_address' => '12 Main Street',
                'tax_number' => 'TAX-123',
                'tax_enabled' => true,
                'tax_rate' => '8.25',
                'tax_inclusive' => false,
                'discount_enabled' => true,
                'max_discount_percent' => '15.00',
                'discount_enabled' => true,
                'max_discount_percent' => '15.00',
                'payment_methods' => ['card', 'cash'],
                'default_payment_method' => 'card',
                'receipt_show_business_details' => true,
                'receipt_footer' => 'Thank you.',
            ],
        ];

        $this->putJson('/api/v1/business/settings', $settings)
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.settings.tax_rate', '8.25')
            ->assertJsonPath('data.settings.default_payment_method', 'card');

        $this->getJson('/api/v1/business/settings')
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Settings Store')
            ->assertJsonPath('data.settings.payment_methods.0', 'card');
    }

    public function test_non_admin_member_cannot_update_pos_settings(): void
    {
        $manager = User::factory()->create();
        $business = Business::create(['name' => 'Settings Store']);
        $manager->businesses()->attach($business, ['role' => 'manager']);
        $this->actingAs($manager, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->putJson('/api/v1/business/settings', [
            'name' => 'Not allowed',
            'currency' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'settings' => [],
        ])->assertForbidden();

        $this->assertDatabaseHas('businesses', ['id' => $business->id, 'name' => 'Settings Store']);
    }

    public function test_cashier_permissions_allow_checkout_but_not_product_management(): void
    {
        $cashier = User::factory()->create();
        $business = Business::create(['name' => 'Cashier Store']);
        $cashier->businesses()->attach($business, ['role' => 'cashier']);
        $this->actingAs($cashier, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/business/settings')
            ->assertOk()
            ->assertJsonPath('data.role', 'cashier');
        $this->getJson('/api/v1/business/current')
            ->assertOk()
            ->assertJsonPath('data.permissions.7', 'settings.read');
        $this->postJson('/api/v1/products', [
            'sku' => 'DENIED-001',
            'name' => 'Denied product',
            'unit_price' => 10,
        ])->assertForbidden();
        $this->putJson('/api/v1/business/settings', [
            'name' => 'Not allowed',
            'currency' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'settings' => [],
        ])->assertForbidden();
        $this->postJson('/api/v1/cash-registers', [
            'name' => 'Unapproved till',
            'code' => 'UNAPPROVED',
        ])->assertForbidden();
        $this->postJson('/api/v1/cash-registers/open', ['opening_balance' => '0.00'])->assertCreated();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_business_owners_can_define_least_privilege_custom_roles(): void
    {
        $owner = User::factory()->create();
        $business = Business::create(['name' => 'Custom Roles Store']);
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $this->putJson('/api/v1/business/settings', [
            'name' => $business->name,
            'currency' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'settings' => [
                ...$business->posSettings(),
                'role_permissions' => ['stock-counter' => ['inventory.read', 'inventory.manage']],
            ],
        ])->assertOk()->assertJsonPath('data.settings.role_permissions.stock-counter.1', 'inventory.manage');

        $cashier = User::factory()->create();
        $cashier->businesses()->attach($business, ['role' => 'stock-counter']);
        $this->actingAs($cashier, 'sanctum')->withHeader('X-Business-ID', $business->id);
        $this->getJson('/api/v1/inventory')->assertOk();
        $this->postJson('/api/v1/sales', [
            'payment_method' => 'cash',
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
    }
}
