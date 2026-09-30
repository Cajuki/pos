<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
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
        $owner = User::factory()->create();
        $business = Business::create(['name' => 'Staff Store']);
        $owner->businesses()->attach($business, ['role' => 'owner']);
        $this->actingAs($owner, 'sanctum')->withHeader('X-Business-ID', $business->id);

        $this->postJson('/api/v1/business/staff', [
            'name' => 'Sam Cashier',
            'email' => 'sam@example.test',
            'password' => 'Strong-pass-2026!',
            'role' => 'cashier',
        ])->assertCreated()->assertJsonPath('data.role', 'cashier');

        $this->getJson('/api/v1/business/staff')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.email', 'sam@example.test');
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
            'password' => 'Strong-pass-2026!',
            'role' => 'cashier',
        ])->assertForbidden();

        $this->assertDatabaseCount('users', 1);
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
