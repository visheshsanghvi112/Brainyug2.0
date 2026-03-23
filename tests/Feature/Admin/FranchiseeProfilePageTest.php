<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\District;
use App\Models\Franchisee;
use App\Models\State;
use App\Models\TerritoryAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FranchiseeProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);
    }

    public function test_admin_sees_lifecycle_and_provision_actions_on_live_franchise_profile(): void
    {
        $admin = $this->makeUserWithRole('Admin', ['module.franchisees.view']);
        $franchisee = $this->createFranchisee(status: 'active');

        $this->actingAs($admin)
            ->get(route('admin.franchisees.show', $franchisee))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Network/Franchisees/Profile')
                ->where('allowApproval', false)
                ->where('allowLifecycleActions', true)
                ->where('allowProvision', true)
            );
    }

    public function test_district_head_cannot_open_franchise_profile_outside_assigned_district(): void
    {
        $districtHead = $this->makeUserWithRole('District Head', ['module.franchisees.view']);
        $state = State::query()->forceCreate(['name' => 'Maharashtra']);
        $assignedDistrict = District::query()->forceCreate(['state_id' => $state->id, 'name' => 'Pune']);
        $otherDistrict = District::query()->forceCreate(['state_id' => $state->id, 'name' => 'Nashik']);

        TerritoryAssignment::create([
            'user_id' => $districtHead->id,
            'territory_type' => 'district',
            'territory_id' => $assignedDistrict->id,
        ]);

        $franchisee = Franchisee::create([
            'shop_name' => 'Outside Scope Store',
            'shop_code' => 'OUT-001',
            'owner_name' => 'Owner Name',
            'mobile' => '9999999999',
            'status' => 'active',
            'state_id' => $state->id,
            'district_id' => $otherDistrict->id,
        ]);

        $this->actingAs($districtHead)
            ->get(route('admin.franchisees.show', $franchisee))
            ->assertNotFound();
    }

    private function makeUserWithRole(string $roleName, array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'username' => Str::lower($roleName) . '_' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createFranchisee(string $status = 'active'): Franchisee
    {
        return Franchisee::create([
            'shop_name' => 'Live Store ' . Str::upper(Str::random(4)),
            'shop_code' => 'LIVE-' . random_int(100, 999),
            'owner_name' => 'Owner Name',
            'mobile' => (string) random_int(9000000000, 9999999999),
            'status' => $status,
        ]);
    }
}
