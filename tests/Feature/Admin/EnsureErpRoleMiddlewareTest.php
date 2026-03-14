<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EnsureErpRoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipe_delimited_roles_allow_matching_user(): void
    {
        $this->registerProbeRoute('erp.role:Super Admin|Admin', '/_test/erp-role-pipe-allow', 'test.erp-role.pipe.allow');

        $adminRole = Role::create(['name' => 'Admin']);
        $user = $this->makeUser(['email' => 'erp-role-admin@example.com']);
        $user->assignRole($adminRole);

        $response = $this->actingAs($user)->get('/_test/erp-role-pipe-allow');

        $response->assertOk();
    }

    public function test_pipe_delimited_roles_block_non_matching_user(): void
    {
        $this->registerProbeRoute('erp.role:Super Admin|Admin', '/_test/erp-role-pipe-deny', 'test.erp-role.pipe.deny');

        $franchiseRole = Role::create(['name' => 'Franchisee']);
        $user = $this->makeUser(['email' => 'erp-role-franchise@example.com']);
        $user->assignRole($franchiseRole);

        $response = $this->actingAs($user)->get('/_test/erp-role-pipe-deny');

        $response->assertForbidden();
    }

    public function test_compatibility_role_alias_is_accepted_by_canonical_middleware_role(): void
    {
        $this->registerProbeRoute('erp.role:Zonal Head', '/_test/erp-role-alias', 'test.erp-role.alias');

        $legacyAliasRole = Role::create(['name' => 'Zone Head']);
        $user = $this->makeUser(['email' => 'erp-role-alias@example.com']);
        $user->assignRole($legacyAliasRole);

        $response = $this->actingAs($user)->get('/_test/erp-role-alias');

        $response->assertOk();
    }

    private function registerProbeRoute(string $middleware, string $uri, string $name): void
    {
        Route::middleware(['web', 'auth', $middleware])
            ->get($uri, static fn () => response('ok', 200))
            ->name($name);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'user_'.Str::lower(Str::random(8)),
            'is_active' => true,
        ], $attributes));
    }
}
