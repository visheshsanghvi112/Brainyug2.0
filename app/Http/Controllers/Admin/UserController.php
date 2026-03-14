<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\DashboardAccessPolicy;
use App\Support\ErpModuleAccess;
use App\Support\AccessChangeAudit;
use App\Support\DashboardViewProfile;
use App\Support\ErpRole;
use App\Models\User;
use App\Models\Franchisee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->with(['roles', 'franchisee'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->input('search'));
                $query->where(function ($nested) use ($search) {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('role'), function ($query) use ($request) {
                $query->role((string) $request->input('role'));
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('is_active', $request->input('status') === 'active');
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $roles = Role::query()
            ->whereIn('name', ErpRole::canonicalRoles())
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Master/Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'filters' => $request->only(['search', 'role', 'status']),
        ]);
    }

    public function create(Request $request)
    {
        $roles = Role::query()
            ->whereIn('name', ErpRole::canonicalRoles())
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Master/Users/CreateEdit', [
            'roles' => $roles,
            'franchisees' => Franchisee::select('id', 'shop_name as name', 'shop_code as franch_id')->get(),
            'dashboardViewOptions' => DashboardViewProfile::options(),
            'canManageDashboardView' => (bool) $request->user()?->isSuperAdmin(),
            'selectedDashboardView' => DashboardViewProfile::AUTO,
            'dashboardSectionOptions' => DashboardAccessPolicy::sectionOptions(),
            'selectedDashboardSections' => [],
            'dashboardLandingRouteOptions' => DashboardAccessPolicy::landingRouteOptions(),
            'selectedDashboardLandingRoute' => null,
            'modulePermissionOptions' => ErpModuleAccess::moduleOptions(),
            'selectedModulePermissions' => [],
            'moduleOverrideEnabled' => false,
            'roleModuleTemplates' => $this->roleModuleTemplates(),
        ]);
    }

    public function store(Request $request)
    {
        $allowedRoles = $this->allowedRoleNames($request);
        $actor = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|unique:users|alpha_dash',
            'email' => 'required|email|unique:users,email',
            'phone' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', Rule::in($allowedRoles)],
            'franchisee_id' => 'nullable|exists:franchisees,id',
            'is_active' => 'boolean',
            'dashboard_view' => ['nullable', Rule::in(DashboardViewProfile::allowedValues())],
            'dashboard_visible_sections' => ['nullable', 'array'],
            'dashboard_visible_sections.*' => [Rule::in(DashboardAccessPolicy::allSections())],
            'dashboard_landing_route' => ['nullable', Rule::in(DashboardAccessPolicy::allowedLandingRoutes())],
            'module_override_enabled' => ['nullable', 'boolean'],
            'module_permissions' => ['nullable', 'array'],
        ]);

        $this->validateModulePermissionMatrix((array) ($validated['module_permissions'] ?? []));

        if ($validated['role'] === 'Franchisee' && empty($validated['franchisee_id'])) {
            return back()->withErrors(['franchisee_id' => 'A franchise link is required for franchise users.']);
        }

        if ($validated['role'] !== 'Franchisee' && !empty($validated['franchisee_id'])) {
            return back()->withErrors(['franchisee_id' => 'Only Franchisee role can be linked to a franchise.']);
        }

        $validated['username'] = Str::lower(trim($validated['username']));
        $validated['email'] = Str::lower(trim($validated['email']));
        $validated['name'] = trim($validated['name']);

        DB::beginTransaction();
        $afterSnapshot = null;
        try {
            $user = User::create([
                'name' => $validated['name'],
                'username' => $validated['username'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'parent_id' => $request->user()?->id,
                'franchisee_id' => $validated['franchisee_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            if ($request->user()?->isSuperAdmin()) {
                $preferences = $user->preferences ?? [];
                data_set($preferences, 'dashboard.view', (string) ($validated['dashboard_view'] ?? DashboardViewProfile::AUTO));
                data_set($preferences, 'dashboard.sections', array_values($validated['dashboard_visible_sections'] ?? []));
                data_set($preferences, 'dashboard.landing_route', $validated['dashboard_landing_route'] ?? null);

                if ((bool) ($validated['module_override_enabled'] ?? false)) {
                    data_set(
                        $preferences,
                        'module_access',
                        ErpModuleAccess::normalizeSubmittedMatrix((array) ($validated['module_permissions'] ?? []))
                    );
                } else {
                    unset($preferences['module_access']);
                }

                $user->forceFill(['preferences' => $preferences])->save();
            }

            $user->assignRole($validated['role']);

            $user->load('roles');
            $afterSnapshot = $this->buildUserAccessSnapshot($user);
            
            DB::commit();

            if ($actor) {
                AccessChangeAudit::record(
                    actor: $actor,
                    targetUserId: $user->id,
                    eventType: 'created',
                    beforeState: null,
                    afterState: $afterSnapshot,
                    summary: 'User account created',
                    request: $request
                );
            }

            Log::info("User created successfully. User ID: {$user->id}");
            return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Provisioning Failure', ['exception' => $e]);
            return redirect()->back()->with('error', 'Unable to create user right now.');
        }
    }

    public function edit(User $user)
    {
        $roles = Role::query()
            ->whereIn('name', ErpRole::canonicalRoles())
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Master/Users/CreateEdit', [
            'user' => $user->load('roles'),
            'roles' => $roles,
            'franchisees' => Franchisee::select('id', 'shop_name as name', 'shop_code as franch_id')->get(),
            'dashboardViewOptions' => DashboardViewProfile::options(),
            'canManageDashboardView' => (bool) request()->user()?->isSuperAdmin(),
            'selectedDashboardView' => DashboardViewProfile::assignedFor($user),
            'dashboardSectionOptions' => DashboardAccessPolicy::sectionOptions(),
            'selectedDashboardSections' => (array) data_get($user->preferences ?? [], 'dashboard.sections', []),
            'dashboardLandingRouteOptions' => DashboardAccessPolicy::landingRouteOptions(),
            'selectedDashboardLandingRoute' => data_get($user->preferences ?? [], 'dashboard.landing_route'),
            'modulePermissionOptions' => ErpModuleAccess::moduleOptions(),
            'selectedModulePermissions' => ErpModuleAccess::effectiveMatrixFor($user),
            'moduleOverrideEnabled' => is_array(data_get($user->preferences ?? [], 'module_access')),
            'roleModuleTemplates' => $this->roleModuleTemplates(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $allowedRoles = $this->allowedRoleNames($request);
        $actor = $request->user();
        $beforeSnapshot = $this->buildUserAccessSnapshot($user->load('roles'));

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|alpha_dash|unique:users,username,' . $user->id,
            'email' => 'required|email|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:15|regex:/^([0-9\s\-\+\(\)]*)$/',
            'password' => 'nullable|string|min:8|confirmed',
            'role' => ['required', Rule::in($allowedRoles)],
            'franchisee_id' => 'nullable|exists:franchisees,id',
            'is_active' => 'boolean',
            'dashboard_view' => ['nullable', Rule::in(DashboardViewProfile::allowedValues())],
            'dashboard_visible_sections' => ['nullable', 'array'],
            'dashboard_visible_sections.*' => [Rule::in(DashboardAccessPolicy::allSections())],
            'dashboard_landing_route' => ['nullable', Rule::in(DashboardAccessPolicy::allowedLandingRoutes())],
            'module_override_enabled' => ['nullable', 'boolean'],
            'module_permissions' => ['nullable', 'array'],
        ]);

        $this->validateModulePermissionMatrix((array) ($validated['module_permissions'] ?? []));

        $validated['username'] = Str::lower(trim($validated['username']));
        $validated['email'] = Str::lower(trim($validated['email']));

        if ($validated['role'] === 'Franchisee' && empty($validated['franchisee_id'])) {
            return back()->withErrors(['franchisee_id' => 'A franchise link is required for franchise users.']);
        }

        if ($validated['role'] !== 'Franchisee' && !empty($validated['franchisee_id'])) {
            return back()->withErrors(['franchisee_id' => 'Only Franchisee role can be linked to a franchise.']);
        }

        if ($user->hasRole('Super Admin') && $validated['role'] !== 'Super Admin' && User::role('Super Admin')->count() <= 1) {
             return redirect()->back()->with('error', 'Cannot demote the last Super Admin account.');
        }

        if ($user->id === $request->user()?->id && !($validated['is_active'] ?? true)) {
            return redirect()->back()->with('error', 'You cannot deactivate your own active session.');
        }

        DB::beginTransaction();
        $afterSnapshot = null;
        try {
            $updateData = [
                'name' => trim($validated['name']),
                'username' => $validated['username'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'franchisee_id' => $validated['franchisee_id'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ];

            if (!empty($validated['password'])) {
                $updateData['password'] = Hash::make($validated['password']);
            }

            $user->update($updateData);

            if ($request->user()?->isSuperAdmin()) {
                $preferences = $user->preferences ?? [];
                data_set($preferences, 'dashboard.view', (string) ($validated['dashboard_view'] ?? DashboardViewProfile::AUTO));
                data_set($preferences, 'dashboard.sections', array_values($validated['dashboard_visible_sections'] ?? []));
                data_set($preferences, 'dashboard.landing_route', $validated['dashboard_landing_route'] ?? null);

                if ((bool) ($validated['module_override_enabled'] ?? false)) {
                    data_set(
                        $preferences,
                        'module_access',
                        ErpModuleAccess::normalizeSubmittedMatrix((array) ($validated['module_permissions'] ?? []))
                    );
                } else {
                    unset($preferences['module_access']);
                }

                $user->forceFill(['preferences' => $preferences])->save();
            }

            $user->syncRoles([$validated['role']]);

            $user->load('roles');
            $afterSnapshot = $this->buildUserAccessSnapshot($user);

            DB::commit();

            if ($actor) {
                AccessChangeAudit::record(
                    actor: $actor,
                    targetUserId: $user->id,
                    eventType: 'updated',
                    beforeState: $beforeSnapshot,
                    afterState: $afterSnapshot,
                    summary: 'User access profile updated',
                    request: $request
                );
            }

            return redirect()->route('admin.users.index')->with('success', 'User updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Synchronization Failure', ['exception' => $e]);
            return redirect()->back()->with('error', 'Unable to update user right now.');
        }
    }

    public function destroy(User $user)
    {
        $actor = auth()->user();

        if ($user->id === auth()->id()) {
            return redirect()->back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->hasRole('Super Admin') && User::role('Super Admin')->count() <= 1) {
             return redirect()->back()->with('error', 'Cannot delete the last Super Admin account.');
        }

        $beforeSnapshot = $this->buildUserAccessSnapshot($user->load('roles'));

        DB::beginTransaction();
        try {
            $deletedUserId = $user->id;
            $user->delete();
            DB::commit();

            if ($actor) {
                AccessChangeAudit::record(
                    actor: $actor,
                    targetUserId: $deletedUserId,
                    eventType: 'deleted',
                    beforeState: $beforeSnapshot,
                    afterState: null,
                    summary: 'User account deleted',
                    request: request()
                );
            }

            return redirect()->back()->with('success', 'User deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('User Archive Failure', ['exception' => $e]);
            return redirect()->back()->with('error', 'Unable to delete user right now.');
        }
    }

    private function allowedRoleNames(Request $request): array
    {
        $roles = ErpRole::canonicalRoles();

        if (!$request->user()?->isSuperAdmin()) {
            $roles = array_values(array_filter($roles, fn (string $role) => $role !== 'Super Admin'));
        }

        return Role::query()
            ->whereIn('name', $roles)
            ->pluck('name')
            ->all();
    }

    private function validateModulePermissionMatrix(array $matrix): void
    {
        if ($matrix === []) {
            return;
        }

        $allowedModules = array_keys(ErpModuleAccess::modules());
        $allowedActions = ErpModuleAccess::actions();

        foreach ($matrix as $moduleKey => $actions) {
            if (!in_array((string) $moduleKey, $allowedModules, true)) {
                throw ValidationException::withMessages([
                    'module_permissions' => 'Unknown module in permission matrix: '.$moduleKey,
                ]);
            }

            if (!is_array($actions)) {
                throw ValidationException::withMessages([
                    'module_permissions' => 'Invalid permission structure for module: '.$moduleKey,
                ]);
            }

            foreach ($actions as $actionKey => $value) {
                if (!in_array((string) $actionKey, $allowedActions, true)) {
                    throw ValidationException::withMessages([
                        'module_permissions' => 'Unknown action "'.$actionKey.'" for module: '.$moduleKey,
                    ]);
                }

                if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
                    throw ValidationException::withMessages([
                        'module_permissions' => 'Permission values must be boolean for module: '.$moduleKey,
                    ]);
                }
            }
        }
    }

    private function roleModuleTemplates(): array
    {
        $templates = [];

        foreach (ErpRole::canonicalRoles() as $roleName) {
            $templates[$roleName] = ErpModuleAccess::roleMatrix($roleName);
        }

        return $templates;
    }

    private function buildUserAccessSnapshot(User $user): array
    {
        $preferences = is_array($user->preferences) ? $user->preferences : [];

        return [
            'name' => (string) $user->name,
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'role' => (string) ($user->getRoleNames()->first() ?? ''),
            'is_active' => (bool) $user->is_active,
            'franchisee_id' => $user->franchisee_id,
            'dashboard_view' => (string) data_get($preferences, 'dashboard.view', DashboardViewProfile::AUTO),
            'dashboard_visible_sections' => array_values((array) data_get($preferences, 'dashboard.sections', [])),
            'dashboard_landing_route' => data_get($preferences, 'dashboard.landing_route'),
            'module_override_enabled' => is_array(data_get($preferences, 'module_access')),
            'module_access' => ErpModuleAccess::normalizeSubmittedMatrix((array) data_get($preferences, 'module_access', [])),
        ];
    }
}
