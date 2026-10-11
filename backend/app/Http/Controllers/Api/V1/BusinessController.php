<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\TeamInvitationMail;
use App\Models\Business;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\BusinessPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessController extends Controller
{
    public function industries(): JsonResponse
    {
        return response()->json(['data' => collect(config('pos.industries'))
            ->map(fn (array $industry, string $key): array => [
                'key' => $key,
                'label' => $industry['label'],
                'modules' => $industry['modules'],
            ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $permissions = app(BusinessPermissions::class);
        $businesses = ($request->user()->is_platform_admin
            ? Business::query()->get()
            : $request->user()->businesses()->get())
            ->map(fn (Business $business): array => [
            ...$business->only(['id', 'name', 'currency', 'timezone']),
            'industry' => $business->industry,
            'modules' => $business->industryModules(),
            'role' => $request->user()->is_platform_admin
                ? 'platform_admin'
                : $business->pivot->role,
            'permissions' => $permissions->forBusiness($request->user(), $business),
        ]);

        return response()->json(['data' => $businesses]);
    }

    public function show(Request $request, Business $business): JsonResponse
    {
        $membership = $request->user()->businesses()->whereKey($business->getKey())->first();
        abort_unless($membership || $request->user()->is_platform_admin, 404);

        return response()->json([
            'data' => [
                ...$business->only(['id', 'name', 'currency', 'timezone']),
                'industry' => $business->industry,
                'modules' => $business->industryModules(),
                'role' => $membership?->pivot->role ?? 'platform_admin',
                'permissions' => app(BusinessPermissions::class)->forBusiness($request->user(), $business),
            ],
        ]);
    }

    public function staff(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->authorizeStaffManagement($request, $business);

        $staff = $business->users()->get()->map(fn (User $user): array => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->pivot->role,
            'status' => 'active',
        ]);
        $invitations = $business->teamInvitations()
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->get()
            ->map(fn (TeamInvitation $invitation): array => [
                'id' => 'invitation-'.$invitation->id,
                'name' => $invitation->name,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => 'invited',
            ]);

        return response()->json(['data' => $staff->concat($invitations)->values()]);
    }

    public function providers(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $providers = $business->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]);

        return response()->json(['data' => $providers]);
    }

    public function addStaff(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->authorizeStaffManagement($request, $business);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in([
                ...array_keys(array_diff_key(config('pos.roles'), ['owner' => true, 'platform_admin' => true])),
                ...array_keys($business->posSettings()['role_permissions']),
            ])],
        ]);

        $email = strtolower(trim($validated['email']));
        $plainTextToken = Str::random(64);
        $expiresAt = now()->addDays(7);

        DB::transaction(function () use ($business, $email, $expiresAt, $plainTextToken, $request, $validated): void {
            $business->newQuery()->whereKey($business->getKey())->lockForUpdate()->firstOrFail();
            $subscription = $business->activeSubscription()->with('plan')->first();
            $userLimit = $subscription?->plan?->limits['users'] ?? null;
            $activeMembers = $business->users()->count();
            $pendingInvitations = $business->teamInvitations()
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->where('email', '!=', $email)
                ->distinct('email')
                ->count('email');
            if ($userLimit !== null && $activeMembers + $pendingInvitations >= $userLimit) {
                throw ValidationException::withMessages([
                    'team' => "This business has reached its {$userLimit}-user subscription limit.",
                ]);
            }

            $invitation = $business->teamInvitations()
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->lockForUpdate()
                ->first() ?? new TeamInvitation;
            $invitation->fill([
                'business_id' => $business->id,
                'invited_by_user_id' => $request->user()->id,
                'name' => $validated['name'],
                'email' => $email,
                'role' => $validated['role'],
                'token_hash' => hash('sha256', $plainTextToken),
                'expires_at' => $expiresAt,
                'accepted_at' => null,
            ]);
            $invitation->save();

            $invitationUrl = rtrim(config('app.frontend_url'), '/').'/accept-invite?token='.rawurlencode($plainTextToken);
            Mail::to($email)->send(new TeamInvitationMail(
                $validated['name'],
                $business->name,
                $validated['role'],
                $invitationUrl,
                $expiresAt->toDayDateTimeString(),
            ));
        });

        return response()->json([
            'data' => [
                'name' => $validated['name'],
                'email' => $email,
                'role' => $validated['role'],
                'status' => 'invited',
            ],
        ], 202);
    }

    public function showInvitation(string $token): JsonResponse
    {
        $invitation = $this->findValidInvitation($token);

        return response()->json(['data' => [
            'name' => $invitation->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'business_name' => $invitation->business->name,
            'expires_at' => $invitation->expires_at,
        ]]);
    }

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:12', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/', 'confirmed'],
        ]);

        $accepted = DB::transaction(function () use ($token, $validated): array {
            $invitation = TeamInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if (! $invitation || $invitation->accepted_at || $invitation->expires_at->isPast()) {
                abort(410, 'This invitation is invalid or has expired.');
            }

            if (User::query()->where('email', $invitation->email)->exists()) {
                abort(409, 'An account already exists for this email address. Sign in before joining this team.');
            }

            $business = $invitation->business;
            $user = User::create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => $validated['password'],
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->businesses()->attach($business, ['role' => $invitation->role]);
            $invitation->update(['accepted_at' => now()]);

            return [
                'token' => $user->createToken('pos-web')->plainTextToken,
                'user' => $user,
                'business' => [
                    ...$business->only(['id', 'name', 'currency', 'timezone']),
                    'industry' => $business->industry,
                    'modules' => $business->industryModules(),
                    'role' => $invitation->role,
                ],
            ];
        });

        return response()->json(['data' => $accepted]);
    }

    private function findValidInvitation(string $token): TeamInvitation
    {
        $invitation = TeamInvitation::query()
            ->with('business')
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_unless($invitation, 410, 'This invitation is invalid or has expired.');

        return $invitation;
    }

    public function settings(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $role = $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role')
            ?? ($request->user()->is_platform_admin ? 'platform_admin' : null);
        $subscription = $business->activeSubscription()->with('plan:id,code,name,features,limits')->first();

        return response()->json(['data' => [
            ...$business->only(['id', 'name', 'currency', 'timezone']),
            'industry' => $business->industry,
            'modules' => $business->industryModules(),
            'module_options' => config('pos.industries.'.$business->industry.'.modules', []),
            'role' => $role,
            'settings' => $business->posSettings(),
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'plan' => $subscription->plan,
            ] : null,
        ]]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $this->authorizeStaffManagement($request, $business);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'industry' => ['sometimes', 'string', Rule::in(array_keys(config('pos.industries')))],
            'currency' => ['required', 'string', Rule::in(['KES', 'USD', 'UGX', 'TZS', 'RWF', 'EUR', 'GBP', 'ZAR'])],
            'timezone' => ['required', 'timezone:all'],
            'settings' => ['required', 'array'],
            'settings.enabled_modules' => ['sometimes', 'array'],
            'settings.enabled_modules.*' => [
                'required',
                'distinct',
                Rule::in(config('pos.industries.'.$request->input('industry', $business->industry).'.modules', [])),
            ],
            'settings.role_permissions' => ['sometimes', 'array'],
            'settings.role_permissions.*' => ['required', 'array'],
            'settings.role_permissions.*.*' => ['required', 'distinct', Rule::in(array_diff(config('pos.permissions'), ['business.manage', 'staff.manage', 'settings.manage']))],
            'settings.business_phone' => ['nullable', 'string', 'max:40'],
            'settings.business_email' => ['nullable', 'email', 'max:255'],
            'settings.business_address' => ['nullable', 'string', 'max:255'],
            'settings.tax_number' => ['nullable', 'string', 'max:80'],
            'settings.tax_enabled' => ['required', 'boolean'],
            'settings.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_if:settings.tax_enabled,true'],
            'settings.tax_inclusive' => ['required', 'boolean'],
            'settings.discount_enabled' => ['required', 'boolean'],
            'settings.max_discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'settings.payment_methods' => ['required', 'array', 'min:1'],
            'settings.payment_methods.*' => ['required', 'distinct', Rule::in(['cash', 'mpesa', 'card', 'bank', 'credit'])],
            'settings.default_payment_method' => ['required', Rule::in(['cash', 'mpesa', 'card', 'bank', 'credit'])],
            'settings.receipt_show_business_details' => ['required', 'boolean'],
            'settings.receipt_footer' => ['nullable', 'string', 'max:250'],
        ]);

        if (! in_array($validated['settings']['default_payment_method'], $validated['settings']['payment_methods'], true)) {
            throw ValidationException::withMessages([
                'settings.default_payment_method' => 'The default payment method must be enabled.',
            ]);
        }
        foreach (array_keys($validated['settings']['role_permissions'] ?? []) as $roleName) {
            if (! preg_match('/^[a-z][a-z0-9_-]{1,39}$/', $roleName)
                || array_key_exists($roleName, config('pos.roles'))) {
                throw ValidationException::withMessages([
                    'settings.role_permissions' => 'Custom role names must be valid, unique role slugs and cannot use reserved administrator roles.',
                ]);
            }
        }
        $validated['settings']['role_permissions'] ??= $business->posSettings()['role_permissions'];
        $industry = $validated['industry'] ?? $business->industry;

        foreach (['business_phone', 'business_email', 'business_address', 'tax_number', 'receipt_footer'] as $field) {
            $validated['settings'][$field] ??= '';
        }
        $validated['settings']['enabled_modules'] ??= $industry === $business->industry
            ? $business->posSettings()['enabled_modules']
            : config('pos.industries.'.$industry.'.modules', []);
        $validated['settings']['tax_rate'] ??= '0.00';
        if (! $validated['settings']['discount_enabled']) {
            $validated['settings']['max_discount_percent'] = '0.00';
        }

        $business->update([
            'name' => $validated['name'],
            'industry' => $industry,
            'currency' => $validated['currency'],
            'timezone' => $validated['timezone'],
            'settings' => $validated['settings'],
        ]);

        $subscription = $business->activeSubscription()->with('plan:id,code,name,features,limits')->first();

        return response()->json(['data' => [
            ...$business->fresh()->only(['id', 'name', 'currency', 'timezone']),
            'industry' => $industry,
            'modules' => $business->industryModules(),
            'module_options' => config('pos.industries.'.$business->industry.'.modules', []),
            'role' => $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role'),
            'settings' => $business->posSettings(),
            'subscription' => $subscription ? [
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'ends_at' => $subscription->ends_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'plan' => $subscription->plan,
            ] : null,
        ]]);
    }

    private function authorizeStaffManagement(Request $request, Business $business): void
    {
        $role = $request->user()->businesses()->whereKey($business->getKey())->value('business_user.role');

        abort_unless($request->user()->is_platform_admin || in_array($role, ['owner', 'admin'], true), 403, 'Only business owners and admins can manage staff.');
    }
}
