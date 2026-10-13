<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\StaffJobTitle;
use App\Models\StaffProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalonStaffController extends Controller
{
    private const PREDEFINED_TITLES = [
        'Barber', 'Hairdresser / Hair Stylist', 'Nail Technician', 'Beauty Therapist',
        'Receptionist / Front Desk Attendant', 'Salon Manager', 'Salon Assistant / Shampooist',
        'Makeup Artist', 'Massage Therapist', 'Braider / Loc Specialist', 'Pedicurist',
        'Cashier', 'Inventory Officer', 'Accountant / Bookkeeper', 'Cleaner', 'Owner / Director',
    ];

    public function index(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'on_leave'])],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('business_id', $business->id)],
            'sort' => ['nullable', Rule::in(['name', 'created_at', 'employment_started_on'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = StaffProfile::query()->where('business_id', $business->id)
            ->with(['jobTitles:id,name', 'branches:id,name', 'services:id,name', 'user:id,name,email'])
            ->when(isset($validated['search']), fn (Builder $builder) => $builder->where(function (Builder $nested) use ($validated): void {
                $search = '%'.mb_strtolower($validated['search']).'%';
                $nested->whereRaw('LOWER(name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(employee_id) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$search]);
            }))
            ->when(isset($validated['status']), fn (Builder $builder) => $builder->where('employment_status', $validated['status']))
            ->when(isset($validated['branch_id']), fn (Builder $builder) => $builder->whereHas('branches', fn (Builder $branch) => $branch->whereKey($validated['branch_id'])));

        $staff = $query->orderBy($validated['sort'] ?? 'name', $validated['direction'] ?? 'asc')->paginate($validated['per_page'] ?? 25);
        $canViewCompensation = $this->canManageCompensation($request, $business);
        $items = collect($staff->items())->map(fn (StaffProfile $profile): array => $this->staffData($profile, $canViewCompensation));
        $onDuty = StaffProfile::query()->where('business_id', $business->id)->where('employment_status', 'active')
            ->whereHas('attendanceRecords', fn (Builder $attendance) => $attendance->whereNull('clocked_out_at'))
            ->count();
        $onLeave = StaffProfile::query()->where('business_id', $business->id)->where('employment_status', 'on_leave')->count();

        return response()->json(['data' => $items, 'meta' => [
            'current_page' => $staff->currentPage(), 'last_page' => $staff->lastPage(), 'total' => $staff->total(),
            'summary' => ['total' => StaffProfile::where('business_id', $business->id)->count(), 'active' => StaffProfile::where('business_id', $business->id)->where('employment_status', 'active')->count(), 'on_duty' => $onDuty, 'on_leave' => $onLeave],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $this->validatedProfile($request, $business);
        $profile = DB::transaction(function () use ($validated, $business, $request): StaffProfile {
            $profile = StaffProfile::create($this->profileAttributes($validated, $business, $request));
            $this->syncAssignments($profile, $validated, $business);
            $this->audit($request, $business, 'staff.profile_created', $profile->id, ['name' => $profile->name]);

            return $profile;
        });

        return response()->json(['data' => $this->staffData($profile->load(['jobTitles:id,name', 'branches:id,name', 'services:id,name', 'user:id,name,email']), $this->canManageCompensation($request, $business))], 201);
    }

    public function update(Request $request, int $staff): JsonResponse
    {
        $business = $request->attributes->get('business');
        $profile = StaffProfile::query()->where('business_id', $business->id)->findOrFail($staff);
        $validated = $this->validatedProfile($request, $business, $profile);
        DB::transaction(function () use ($profile, $validated, $business, $request): void {
            $old = ['employment_status' => $profile->employment_status, 'employee_id' => $profile->employee_id];
            $profile->update($this->profileAttributes($validated, $business, $request));
            $this->syncAssignments($profile, $validated, $business);
            $this->audit($request, $business, 'staff.profile_updated', $profile->id, ['employment_status' => $old['employment_status'], 'employee_id' => $old['employee_id']]);
        });

        return response()->json(['data' => $this->staffData($profile->refresh()->load(['jobTitles:id,name', 'branches:id,name', 'services:id,name', 'user:id,name,email']), $this->canManageCompensation($request, $business))]);
    }

    public function updateStatus(Request $request, int $staff): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate(['employment_status' => ['required', Rule::in(['active', 'inactive', 'on_leave'])]]);
        $profile = StaffProfile::query()->where('business_id', $business->id)->findOrFail($staff);
        $before = $profile->employment_status;
        $profile->update(['employment_status' => $validated['employment_status']]);
        $this->audit($request, $business, 'staff.status_changed', $profile->id, ['employment_status' => $before]);

        return response()->json(['data' => ['id' => $profile->id, 'employment_status' => $profile->employment_status]]);
    }

    public function jobTitles(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        foreach (self::PREDEFINED_TITLES as $name) {
            StaffJobTitle::query()->firstOrCreate(['business_id' => $business->id, 'slug' => Str::slug($name)], ['name' => $name, 'is_predefined' => true]);
        }

        return response()->json(['data' => StaffJobTitle::query()->where('business_id', $business->id)->where('is_enabled', true)->orderBy('name')->get(['id', 'name', 'slug', 'default_permissions', 'is_predefined'])]);
    }

    public function createJobTitle(Request $request): JsonResponse
    {
        $business = $request->attributes->get('business');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'default_permissions' => ['sometimes', 'array'],
            'default_permissions.*' => ['string', Rule::in(config('pos.permissions'))],
        ]);
        $title = StaffJobTitle::create(['business_id' => $business->id, 'name' => $validated['name'], 'slug' => Str::slug($validated['name']), 'default_permissions' => $validated['default_permissions'] ?? [], 'is_predefined' => false]);
        $this->audit($request, $business, 'staff.job_title_created', $title->id, ['name' => $title->name]);

        return response()->json(['data' => $title], 201);
    }

    private function validatedProfile(Request $request, Business $business, ?StaffProfile $profile = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'employee_id' => ['nullable', 'string', 'max:64', Rule::unique('staff_profiles', 'employee_id')->where('business_id', $business->id)->ignore($profile?->id)],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contract', 'other'])],
            'employment_started_on' => ['nullable', 'date'],
            'employment_status' => ['required', Rule::in(['active', 'inactive', 'on_leave'])],
            'job_title_ids' => ['required', 'array', 'min:1'],
            'job_title_ids.*' => ['integer', 'distinct', Rule::exists('staff_job_titles', 'id')->where('business_id', $business->id)->where('is_enabled', true)],
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('business_id', $business->id)->where('is_active', true)],
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('business_id', $business->id)->where('product_type', 'service')->where('is_active', true)],
            'permission_overrides' => ['sometimes', 'array'],
            'permission_overrides.*' => ['string', Rule::in(array_diff(config('pos.permissions'), ['business.manage', 'staff.manage', 'settings.manage']))],
            'commission_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'commission_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'product_commission_enabled' => ['sometimes', 'boolean'],
            'product_commission_value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'salary_amount' => ['nullable', 'numeric', 'min:0'],
            'salary_currency' => ['nullable', 'string', 'size:3'],
        ]);

        if (! $this->canManageCompensation($request, $business) && (array_key_exists('salary_amount', $validated) || array_key_exists('salary_currency', $validated))) {
            throw ValidationException::withMessages(['salary_amount' => 'You are not authorized to manage compensation.']);
        }

        return $validated;
    }

    private function profileAttributes(array $validated, Business $business, Request $request): array
    {
        $attributes = collect($validated)->only(['name', 'phone', 'email', 'employee_id', 'employment_type', 'employment_started_on', 'employment_status', 'permission_overrides', 'commission_type', 'commission_value', 'product_commission_enabled', 'product_commission_value'])->all();
        $attributes['business_id'] = $business->id;
        if ($this->canManageCompensation($request, $business)) {
            $attributes['salary_amount'] = $validated['salary_amount'] ?? null;
            $attributes['salary_currency'] = $validated['salary_currency'] ?? null;
        }

        return $attributes;
    }

    private function syncAssignments(StaffProfile $profile, array $validated, Business $business): void
    {
        $profile->jobTitles()->sync(collect($validated['job_title_ids'])->mapWithKeys(fn (int $id, int $index): array => [$id => ['is_primary' => $index === 0]])->all());
        DB::table('staff_profile_branches')->where('staff_profile_id', $profile->id)->delete();
        foreach ($validated['branch_ids'] as $branchId) {
            DB::table('staff_profile_branches')->insert(['staff_profile_id' => $profile->id, 'business_id' => $business->id, 'branch_id' => $branchId]);
        }
        DB::table('staff_service_assignments')->where('staff_profile_id', $profile->id)->delete();
        foreach ($validated['service_ids'] ?? [] as $serviceId) {
            DB::table('staff_service_assignments')->insert(['staff_profile_id' => $profile->id, 'business_id' => $business->id, 'product_id' => $serviceId, 'commission_eligible' => true]);
        }
    }

    private function staffData(StaffProfile $profile, bool $canViewCompensation): array
    {
        $data = [
            'id' => $profile->id, 'name' => $profile->name, 'phone' => $profile->phone, 'email' => $profile->email,
            'employee_id' => $profile->employee_id, 'employment_type' => $profile->employment_type,
            'employment_started_on' => $profile->employment_started_on?->toDateString(), 'employment_status' => $profile->employment_status,
            'account_status' => $profile->user_id ? 'active' : 'profile_only', 'user_id' => $profile->user_id,
            'job_titles' => $profile->jobTitles->map(fn (StaffJobTitle $title): array => ['id' => $title->id, 'name' => $title->name, 'is_primary' => (bool) $title->pivot->is_primary]),
            'branches' => $profile->branches->map->only(['id', 'name']), 'services' => $profile->services->map->only(['id', 'name']),
            'commission_type' => $profile->commission_type, 'commission_value' => $profile->commission_value,
        ];
        if ($canViewCompensation) {
            $data['salary_amount'] = $profile->salary_amount;
            $data['salary_currency'] = $profile->salary_currency;
        }

        return $data;
    }

    private function canManageCompensation(Request $request, Business $business): bool
    {
        return $request->user()->is_platform_admin || in_array(
            $request->user()->businesses()->whereKey($business->id)->value('business_user.role'), ['owner', 'admin'], true,
        );
    }

    private function audit(Request $request, Business $business, string $action, int $entityId, array $newValues): void
    {
        DB::table('audit_logs')->insert(['business_id' => $business->id, 'user_id' => $request->user()->id, 'action' => $action, 'entity_type' => StaffProfile::class, 'entity_id' => (string) $entityId, 'old_values' => null, 'new_values' => json_encode($newValues, JSON_THROW_ON_ERROR), 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 65535), 'created_at' => now()]);
    }
}
