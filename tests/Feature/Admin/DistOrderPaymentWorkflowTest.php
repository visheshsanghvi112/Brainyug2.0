<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\DistOrder;
use App\Models\DistOrderPayment;
use App\Models\FinancialLedger;
use App\Models\Franchisee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DistOrderPaymentWorkflowTest extends TestCase
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

    public function test_franchisee_index_and_show_are_scoped_to_own_orders(): void
    {
        $ownerA = $this->makeFranchiseUser('fr_a', ['module.dist_orders.view']);
        $ownerB = $this->makeFranchiseUser('fr_b', ['module.dist_orders.view']);

        $ownOrder = $this->createDistOrder($ownerA, [
            'order_number' => 'ORD-TEST-A',
        ]);

        $otherOrder = $this->createDistOrder($ownerB, [
            'order_number' => 'ORD-TEST-B',
        ]);

        $this->actingAs($ownerA)
            ->get(route('admin.dist-orders.index'))
            ->assertOk()
            ->assertSee('ORD-TEST-A')
            ->assertDontSee('ORD-TEST-B');

        $this->actingAs($ownerA)
            ->get(route('admin.dist-orders.show', $ownOrder->id))
            ->assertOk()
            ->assertSee('ORD-TEST-A');

        $this->actingAs($ownerA)
            ->get(route('admin.dist-orders.show', $otherOrder->id))
            ->assertNotFound();
    }

    public function test_franchisee_payment_submission_stays_pending_and_cannot_exceed_outstanding(): void
    {
        $franchiseUser = $this->makeFranchiseUser('fr_pay', [
            'module.dist_orders.view',
            'module.dist_orders.create',
        ]);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-PAY-001',
            'total_amount' => 1000,
            'status' => 'dispatched',
        ]);

        $this->actingAs($franchiseUser)
            ->post(route('admin.dist-orders.payments.store', $order->id), [
                'amount' => 400,
                'payment_mode' => 'bank',
                'reference_no' => 'UTR-12345',
                'payment_date' => now()->toDateString(),
                'narration' => 'First installment',
            ])
            ->assertRedirect();

        $payment = DistOrderPayment::query()->where('dist_order_id', $order->id)->first();

        $this->assertNotNull($payment);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('400.00', (string) $payment->amount);
        $this->assertDatabaseCount('financial_ledgers', 0);

        $this->actingAs($franchiseUser)
            ->post(route('admin.dist-orders.payments.store', $order->id), [
                'amount' => 700,
                'payment_mode' => 'bank',
                'reference_no' => 'UTR-OVER',
                'payment_date' => now()->toDateString(),
            ])
            ->assertStatus(422);
    }

    public function test_ho_confirmation_posts_payment_received_ledger_entry(): void
    {
        $franchiseUser = $this->makeFranchiseUser('fr_conf', ['module.dist_orders.view']);
        $adminUser = $this->makeAdminUser('admin_conf', [
            'module.dist_orders.view',
            'module.dist_orders.update',
        ]);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-CONF-001',
            'total_amount' => 850,
            'status' => 'dispatched',
        ]);

        $payment = DistOrderPayment::create([
            'dist_order_id' => $order->id,
            'franchisee_id' => $franchiseUser->franchisee_id,
            'created_by' => $franchiseUser->id,
            'amount' => 325,
            'payment_mode' => 'neft',
            'reference_no' => 'NEFT-7788',
            'payment_date' => now()->toDateString(),
            'narration' => 'Bank transfer',
            'status' => 'pending',
        ]);

        $this->actingAs($adminUser)
            ->post(route('admin.dist-orders.payments.confirm', [
                'dist_order' => $order->id,
                'dist_order_payment' => $payment->id,
            ]))
            ->assertRedirect();

        $payment->refresh();

        $this->assertSame('confirmed', $payment->status);
        $this->assertNotNull($payment->financial_ledger_id);

        $this->assertDatabaseHas('financial_ledgers', [
            'id' => $payment->financial_ledger_id,
            'ledgerable_type' => Franchisee::class,
            'ledgerable_id' => $franchiseUser->franchisee_id,
            'transaction_type' => 'PAYMENT_RECEIVED',
            'credit' => 325,
            'payment_mode' => 'neft',
            'reference_type' => DistOrderPayment::class,
            'reference_id' => $payment->id,
        ]);

        $ledger = FinancialLedger::findOrFail($payment->financial_ledger_id);
        $this->assertSame(325.0, (float) $ledger->running_balance);
    }

    public function test_franchisee_can_open_own_ledger_when_permission_is_granted(): void
    {
        $franchiseUser = $this->makeFranchiseUser('fr_ledger', ['module.ledger.view']);

        FinancialLedger::create([
            'ledgerable_type' => Franchisee::class,
            'ledgerable_id' => $franchiseUser->franchisee_id,
            'transaction_date' => now()->toDateString(),
            'transaction_type' => 'PURCHASE',
            'voucher_no' => 'V-LEDGER01',
            'debit' => 500,
            'credit' => 0,
            'running_balance' => -500,
            'payment_mode' => 'credit',
            'narration' => 'Dispatched order',
        ]);

        $this->actingAs($franchiseUser)
            ->get(route('ledger.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Finance/Ledger/Index')
                ->where('context', 'Your Account Ledger')
            );
    }

    private function makeFranchiseUser(string $usernamePrefix, array $permissions): User
    {
        $role = $this->makeRole('Franchisee', $permissions);

        $franchisee = Franchisee::create([
            'shop_name' => 'Shop ' . Str::upper(Str::random(4)),
            'shop_code' => 'SC-' . random_int(1000, 9999),
            'owner_name' => 'Owner Name',
            'mobile' => (string) random_int(9000000000, 9999999999),
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'username' => $usernamePrefix . '_' . Str::lower(Str::random(6)),
            'is_active' => true,
            'franchisee_id' => $franchisee->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeAdminUser(string $usernamePrefix, array $permissions): User
    {
        $role = $this->makeRole('Admin', $permissions);

        $user = User::factory()->create([
            'username' => $usernamePrefix . '_' . Str::lower(Str::random(6)),
            'is_active' => true,
            'franchisee_id' => null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeRole(string $name, array $permissions): Role
    {
        $role = Role::firstOrCreate(['name' => $name]);

        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $role->syncPermissions($permissions);

        return $role;
    }

    private function createDistOrder(User $franchiseUser, array $overrides = []): DistOrder
    {
        return DistOrder::create(array_merge([
            'order_number' => 'ORD-' . Str::upper(Str::random(10)),
            'franchisee_id' => $franchiseUser->franchisee_id,
            'user_id' => $franchiseUser->id,
            'status' => 'pending',
            'subtotal' => 800,
            'discount_amount' => 0,
            'sgst_amount' => 0,
            'cgst_amount' => 0,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 800,
        ], $overrides));
    }
}