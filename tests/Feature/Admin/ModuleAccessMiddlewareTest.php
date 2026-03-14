<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ModuleAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_with_view_only_users_permission_cannot_create_users(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        Permission::create(['name' => 'module.users.view']);

        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo('module.users.view');

        $admin = $this->makeUser(['email' => 'admin@example.com']);
        $admin->assignRole($adminRole);

        $response = $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New User',
            'username' => 'newuser',
            'email' => 'newuser@example.com',
            'password' => 'Password@123',
            'password_confirmation' => 'Password@123',
            'role' => 'Admin',
            'is_active' => true,
        ]);

        $response->assertForbidden();
    }

    public function test_unmapped_protected_routes_are_denied_when_strict_mode_is_enabled(): void
    {
        config()->set('erp.module_access.strict_unmapped', true);

        Route::middleware(['web', 'auth', 'erp.module'])
            ->get('/_test/admin-unmapped-strict', static fn () => response('ok', 200))
            ->name('admin.unmapped.strict');

        $adminRole = Role::create(['name' => 'Admin']);
        $admin = $this->makeUser(['email' => 'strict@example.com']);
        $admin->assignRole($adminRole);

        $response = $this->actingAs($admin)->get('/_test/admin-unmapped-strict');

        $response->assertForbidden();
    }

    public function test_unmapped_protected_routes_are_allowed_when_strict_mode_is_disabled(): void
    {
        config()->set('erp.module_access.strict_unmapped', false);

        Route::middleware(['web', 'auth', 'erp.module'])
            ->get('/_test/admin-unmapped-relaxed', static fn () => response('ok', 200))
            ->name('admin.unmapped.relaxed');

        $adminRole = Role::create(['name' => 'Admin']);
        $admin = $this->makeUser(['email' => 'relaxed@example.com']);
        $admin->assignRole($adminRole);

        $response = $this->actingAs($admin)->get('/_test/admin-unmapped-relaxed');

        $response->assertOk();
    }

    public function test_mapped_products_store_route_requires_create_permission(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        Permission::create(['name' => 'module.products.view']);

        Route::middleware(['web', 'auth', 'erp.module'])
            ->post('/_test/admin-products-probe-store', static fn () => response('ok', 200))
            ->name('admin.products.probe.store');

        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo('module.products.view');

        $admin = $this->makeUser(['email' => 'products-view-only@example.com']);
        $admin->assignRole($adminRole);

        $forbiddenResponse = $this->actingAs($admin)->post('/_test/admin-products-probe-store');
        $forbiddenResponse->assertForbidden();

        $createPermission = Permission::create(['name' => 'module.products.create']);
        $adminRole->givePermissionTo($createPermission);

        $allowedResponse = $this->actingAs($admin)->post('/_test/admin-products-probe-store');
        $allowedResponse->assertOk();
    }

    public function test_mapped_tickets_delete_route_requires_delete_permission(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        Permission::create(['name' => 'module.tickets.view']);

        Route::middleware(['web', 'auth', 'erp.module'])
            ->delete('/_test/tickets-probe-delete', static fn () => response('ok', 200))
            ->name('tickets.probe.destroy');

        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo('module.tickets.view');

        $admin = $this->makeUser(['email' => 'tickets-view-only@example.com']);
        $admin->assignRole($adminRole);

        $forbiddenResponse = $this->actingAs($admin)->delete('/_test/tickets-probe-delete');
        $forbiddenResponse->assertForbidden();

        $deletePermission = Permission::create(['name' => 'module.tickets.delete']);
        $adminRole->givePermissionTo($deletePermission);

        $allowedResponse = $this->actingAs($admin)->delete('/_test/tickets-probe-delete');
        $allowedResponse->assertOk();
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'user_'.Str::lower(Str::random(8)),
            'is_active' => true,
        ], $attributes));
    }
}
