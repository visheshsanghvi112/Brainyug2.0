<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admins_receive_the_admin_dashboard(): void
    {
        $role = Role::create(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('dashboard.title', 'Executive Dashboard')
            ->where('dashboard.role', 'Super Admin')
            ->has('dashboard.stats', 6)
        );
    }

    public function test_franchisees_receive_their_operational_dashboard(): void
    {
        $role = Role::create(['name' => 'Franchisee']);
        $user = User::factory()->create();
        $user->assignRole($role);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dashboard')
            ->where('dashboard.title', 'Franchisee Dashboard')
            ->where('dashboard.role', 'Franchisee')
            ->has('dashboard.stats', 4)
        );
    }
}