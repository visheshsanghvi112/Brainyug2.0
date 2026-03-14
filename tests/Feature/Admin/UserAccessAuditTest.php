<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_update_writes_access_change_audit_record(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $adminRole = Role::create(['name' => 'Admin']);

        $actor = $this->makeUser([
            'email' => 'superadmin@example.com',
            'name' => 'Super Admin Actor',
        ]);
        $actor->assignRole($superAdminRole);

        $target = $this->makeUser([
            'email' => 'target@example.com',
            'name' => 'Target User',
        ]);
        $target->assignRole($adminRole);

        $response = $this->actingAs($actor)->put(route('admin.users.update', $target->id), [
            'name' => 'Target User Updated',
            'username' => $target->username,
            'email' => $target->email,
            'phone' => '9988776655',
            'role' => 'Admin',
            'is_active' => true,
            'module_override_enabled' => true,
            'module_permissions' => [
                'users' => [
                    'view' => true,
                    'create' => true,
                    'update' => false,
                    'delete' => false,
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('user_access_change_audits', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'event_type' => 'updated',
        ]);
    }

    public function test_support_access_start_stays_operational_if_impersonation_audit_table_is_missing(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $superAdminRole = Role::create(['name' => 'Super Admin']);

        $actor = $this->makeUser(['email' => 'impersonator@example.com']);
        $actor->assignRole($superAdminRole);

        $target = $this->makeUser(['email' => 'support-target@example.com']);

        Schema::dropIfExists('impersonation_audits');

        $response = $this->actingAs($actor)->post(route('admin.users.support-access', $target->id), [
            'reason' => 'Need to troubleshoot billing issue in production.',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($target);
    }

    public function test_user_delete_writes_access_change_audit_record(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $superAdminRole = Role::create(['name' => 'Super Admin']);
        $adminRole = Role::create(['name' => 'Admin']);

        $actor = $this->makeUser([
            'email' => 'delete-actor@example.com',
            'name' => 'Delete Actor',
        ]);
        $actor->assignRole($superAdminRole);

        $target = $this->makeUser([
            'email' => 'delete-target@example.com',
            'name' => 'Delete Target',
        ]);
        $target->assignRole($adminRole);

        $response = $this->actingAs($actor)->delete(route('admin.users.destroy', $target->id));

        $response->assertRedirect();

        $this->assertDatabaseHas('user_access_change_audits', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'event_type' => 'deleted',
        ]);
    }

    public function test_admin_can_open_user_access_audit_index(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        Permission::create(['name' => 'module.users.view']);

        $adminRole = Role::create(['name' => 'Admin']);
        $adminRole->givePermissionTo('module.users.view');

        $admin = $this->makeUser(['email' => 'audit-viewer@example.com']);
        $admin->assignRole($adminRole);

        $response = $this->actingAs($admin)->get(route('admin.user-access.audits'));

        $response->assertOk();
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'user_'.Str::lower(Str::random(8)),
            'is_active' => true,
        ], $attributes));
    }
}
