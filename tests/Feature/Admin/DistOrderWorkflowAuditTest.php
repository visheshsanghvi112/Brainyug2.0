<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\B2bCart;
use App\Models\B2bCartItem;
use App\Models\BoxSize;
use App\Models\Commission;
use App\Models\CompanyMaster;
use App\Models\DistOrder;
use App\Models\DistOrderItem;
use App\Models\FinancialLedger;
use App\Models\Franchisee;
use App\Models\HsnMaster;
use App\Models\InventoryLedger;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SaltMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DistOrderWorkflowAuditTest extends TestCase
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

    public function test_checkout_logs_initial_submission_for_b2b_orders(): void
    {
        $franchiseUser = $this->makeFranchiseUser([
            'module.b2b_cart.view',
            'module.b2b_cart.create',
        ]);
        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Workflow Test Product',
            'sku' => 'WF-CHECKOUT-' . Str::upper(Str::random(4)),
            'rate_a' => 120,
        ]);

        $cart = B2bCart::create([
            'franchisee_id' => $franchiseUser->franchisee_id,
            'user_id' => $franchiseUser->id,
            'subtotal' => 240,
            'total_amount' => 240,
        ]);

        B2bCartItem::create([
            'b2b_cart_id' => $cart->id,
            'product_id' => $product->id,
            'qty' => 100,
            'free_qty' => 0,
            'rate' => 120,
            'total_amount' => 12000,
        ]);

        $this->actingAs($franchiseUser)
            ->post(route('b2b.cart.checkout'))
            ->assertRedirect(route('admin.dist-orders.index'));

        $order = DistOrder::query()
            ->with('statusLogs')
            ->where('user_id', $franchiseUser->id)
            ->firstOrFail();

        $this->assertSame('pending', $order->status);
        $this->assertDatabaseHas('dist_order_status_logs', [
            'dist_order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'pending',
            'actor_user_id' => $franchiseUser->id,
            'note' => 'Order submitted by franchisee.',
        ]);
    }

    public function test_accepting_an_order_writes_a_workflow_log_entry(): void
    {
        $admin = $this->makeAdminUser(['module.dist_orders.view', 'module.dist_orders.update']);
        $franchiseUser = $this->makeFranchiseUser(['module.dist_orders.view']);
        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Workflow Accept Product',
            'sku' => 'WF-ACCEPT-' . Str::upper(Str::random(4)),
        ]);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-ACCEPT-001',
            'status' => 'pending',
            'locked_by' => $admin->id,
            'locked_at' => now(),
        ]);

        $item = $this->createDistOrderItem($order, $product, [
            'request_qty' => 5,
            'rate' => 90,
        ]);

        $this->seedWarehouseStock($product, 'BATCH-ACCEPT', 10, $admin->id);

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.accept', $order->id), [
                'items' => [[
                    'id' => $item->id,
                    'approved_qty' => 5,
                    'free_qty' => 0,
                    'rate' => 90,
                    'discount_percent' => 0,
                ]],
            ])
            ->assertRedirect();

        $order->refresh();
        $item->refresh();

        $this->assertSame('accepted', $order->status);
        $this->assertNull($item->batch_no);
        $this->assertDatabaseHas('dist_order_status_logs', [
            'dist_order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'accepted',
            'actor_user_id' => $admin->id,
            'note' => 'Order commercially approved by HO. Awaiting warehouse allocation.',
        ]);
    }

    public function test_dispatching_an_order_writes_a_workflow_log_entry_and_exposes_timeline(): void
    {
        $admin = $this->makeAdminUser(['module.dist_orders.view', 'module.dist_orders.update']);
        $franchiseUser = $this->makeFranchiseUser(['module.dist_orders.view']);
        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Workflow Dispatch Product',
            'sku' => 'WF-DISPATCH-' . Str::upper(Str::random(4)),
        ]);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-DISPATCH-001',
            'status' => 'accepted',
            'subtotal' => 450,
            'total_amount' => 504,
            'locked_by' => $admin->id,
            'locked_at' => now(),
            'accepted_by' => $admin->id,
            'accepted_at' => now()->subHour(),
        ]);

        $this->createDistOrderItem($order, $product, [
            'request_qty' => 5,
            'approved_qty' => 5,
            'free_qty' => 1,
            'rate' => 90,
            'gst_percent' => 12,
            'taxable_amount' => 450,
            'gst_amount' => 54,
            'total_amount' => 504,
        ]);

        $this->seedWarehouseStock($product, 'BATCH-DISPATCH', 20, $admin->id);

        $item = $order->items()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.allocate', $order->id), [
                'items' => [[
                    'id' => $item->id,
                    'batch_no' => 'BATCH-DISPATCH',
                ]],
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('allocated', $order->status);
        $this->assertDatabaseHas('dist_order_status_logs', [
            'dist_order_id' => $order->id,
            'from_status' => 'accepted',
            'to_status' => 'allocated',
            'actor_user_id' => $admin->id,
            'note' => 'Order batch allocation finalized and ready for dispatch desk.',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.dispatch', $order->id), [
                'courier_name' => 'Blue Dart',
                'tracking_number' => 'TRACK-12345',
                'tracking_link' => 'https://example.com/track/TRACK-12345',
                'dispatch_date' => now()->toDateString(),
                'invoice_number' => 'INV-WF-001',
                'ebill_number' => 'EBILL-001',
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('dispatched', $order->status);
        $this->assertDatabaseHas('dist_order_status_logs', [
            'dist_order_id' => $order->id,
            'from_status' => 'allocated',
            'to_status' => 'dispatched',
            'actor_user_id' => $admin->id,
            'note' => 'Order dispatched from HO warehouse to franchisee.',
        ]);
        $this->assertDatabaseCount('inventory_ledgers', 3);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->id,
            'batch_no' => 'BATCH-DISPATCH',
            'location_type' => 'warehouse',
            'location_id' => 0,
            'transaction_type' => 'DISPATCH',
            'qty_out' => 6,
        ]);
        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->id,
            'batch_no' => 'BATCH-DISPATCH',
            'location_type' => 'franchisee',
            'location_id' => $franchiseUser->franchisee_id,
            'transaction_type' => 'RECEIVE',
            'qty_in' => 6,
        ]);
        $this->assertDatabaseHas('financial_ledgers', [
            'ledgerable_type' => Franchisee::class,
            'ledgerable_id' => $franchiseUser->franchisee_id,
            'transaction_type' => 'PURCHASE',
            'debit' => 504,
            'payment_mode' => 'CREDIT',
            'reference_type' => DistOrder::class,
            'reference_id' => $order->id,
            'narration' => 'B2B Stock Purchase - Invoice INV-WF-001 / Order ORD-DISPATCH-001',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dist-orders.show', $order->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Distribution/Orders/Show')
                ->where('workflowLabels.labels.dispatched', 'Dispatched')
                ->where('workflowLabels.labels.allocated', 'Allocated')
                ->has('order.status_logs', 2)
                ->where('order.status_logs.0.to_status', 'dispatched')
                ->where('order.status_logs.0.actor.name', $admin->name)
                ->where('order.status_logs.1.to_status', 'allocated')
            );

        $ledger = FinancialLedger::query()
            ->where('reference_type', DistOrder::class)
            ->where('reference_id', $order->id)
            ->firstOrFail();

        $this->assertSame((string) now()->toDateString(), (string) $ledger->transaction_date);
    }

    public function test_dispatch_requires_allocated_status(): void
    {
        $admin = $this->makeAdminUser(['module.dist_orders.view', 'module.dist_orders.update']);
        $franchiseUser = $this->makeFranchiseUser(['module.dist_orders.view']);
        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Workflow Gate Product',
            'sku' => 'WF-GATE-' . Str::upper(Str::random(4)),
        ]);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-GATE-001',
            'status' => 'accepted',
            'subtotal' => 450,
            'total_amount' => 504,
            'locked_by' => $admin->id,
            'locked_at' => now(),
            'accepted_by' => $admin->id,
            'accepted_at' => now()->subHour(),
        ]);

        $this->createDistOrderItem($order, $product, [
            'request_qty' => 5,
            'approved_qty' => 5,
            'free_qty' => 1,
            'rate' => 90,
            'gst_percent' => 12,
            'taxable_amount' => 450,
            'gst_amount' => 54,
            'total_amount' => 504,
        ]);

        $this->seedWarehouseStock($product, 'BATCH-GATE', 20, $admin->id);

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.dispatch', $order->id), [
                'courier_name' => 'Blue Dart',
                'tracking_number' => 'TRACK-GATE',
                'tracking_link' => 'https://example.com/track/TRACK-GATE',
                'dispatch_date' => now()->toDateString(),
                'invoice_number' => 'INV-GATE-001',
                'ebill_number' => 'EBILL-GATE-001',
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('accepted', $order->status);
        $this->assertDatabaseMissing('dist_order_status_logs', [
            'dist_order_id' => $order->id,
            'to_status' => 'dispatched',
        ]);
        $this->assertDatabaseCount('financial_ledgers', 0);
    }

    public function test_rejecting_an_order_writes_a_workflow_log_entry(): void
    {
        $admin = $this->makeAdminUser(['module.dist_orders.view', 'module.dist_orders.update']);
        $franchiseUser = $this->makeFranchiseUser(['module.dist_orders.view']);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-REJECT-001',
            'status' => 'pending',
            'locked_by' => $admin->id,
            'locked_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.reject', $order->id), [
                'rejection_reason' => 'Pricing mismatch in request.',
            ])
            ->assertRedirect();

        $order->refresh();

        $this->assertSame('rejected', $order->status);
        $this->assertDatabaseHas('dist_order_status_logs', [
            'dist_order_id' => $order->id,
            'from_status' => 'pending',
            'to_status' => 'rejected',
            'actor_user_id' => $admin->id,
            'note' => 'Order rejected by HO review team.',
        ]);
    }

    public function test_dispatch_generates_commission_for_ancestor_user_and_updates_order_total(): void
    {
        $admin = $this->makeAdminUser(['module.dist_orders.view', 'module.dist_orders.update']);
        $commissionRecipient = $this->makeCommissionRecipientUser(10, 5);
        $franchiseUser = $this->makeFranchiseUser(['module.dist_orders.view']);
        $franchiseUser->update(['parent_id' => $commissionRecipient->id]);

        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Commission Product',
            'sku' => 'WF-COMM-' . Str::upper(Str::random(4)),
            'is_commissionable' => true,
        ]);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-COMM-WF-001',
            'status' => 'accepted',
            'subtotal' => 450,
            'total_amount' => 504,
            'locked_by' => $admin->id,
            'locked_at' => now(),
            'accepted_by' => $admin->id,
            'accepted_at' => now()->subHour(),
        ]);

        $item = $this->createDistOrderItem($order, $product, [
            'request_qty' => 5,
            'approved_qty' => 5,
            'free_qty' => 1,
            'rate' => 90,
            'gst_percent' => 12,
            'taxable_amount' => 450,
            'gst_amount' => 54,
            'total_amount' => 504,
        ]);

        $this->seedWarehouseStock($product, 'BATCH-COMM', 20, $admin->id);

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.allocate', $order->id), [
                'items' => [[
                    'id' => $item->id,
                    'batch_no' => 'BATCH-COMM',
                ]],
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.dispatch', $order->id), [
                'courier_name' => 'Blue Dart',
                'tracking_number' => 'TRACK-COMM',
                'tracking_link' => 'https://example.com/track/TRACK-COMM',
                'dispatch_date' => now()->toDateString(),
                'invoice_number' => 'INV-COMM-001',
                'ebill_number' => 'EBILL-COMM-001',
            ])
            ->assertRedirect();

        $order->refresh();
        $commission = Commission::query()->where('dist_order_id', $order->id)->where('cr_dr', 'Cr')->firstOrFail();

        $this->assertSame($commissionRecipient->id, $commission->user_id);
        $this->assertSame('dispatch', $commission->trigger_event);
        $this->assertSame('pending', $commission->status);
        $this->assertSame(45.0, (float) $commission->gross_commission);
        $this->assertSame(42.75, (float) $commission->net_payable);
        $this->assertSame(45.0, (float) $order->total_commission);

        $this->assertDatabaseHas('financial_ledgers', [
            'ledgerable_type' => User::class,
            'ledgerable_id' => $commissionRecipient->id,
            'transaction_type' => 'COMMISSION',
            'credit' => 42.75,
            'reference_type' => Commission::class,
            'reference_id' => $commission->id,
        ]);
    }

    public function test_rejecting_an_order_reverses_existing_commissions_instead_of_deleting_them(): void
    {
        $admin = $this->makeAdminUser(['module.dist_orders.view', 'module.dist_orders.update']);
        $commissionRecipient = $this->makeCommissionRecipientUser(10, 5);
        $franchiseUser = $this->makeFranchiseUser(['module.dist_orders.view']);

        $order = $this->createDistOrder($franchiseUser, [
            'order_number' => 'ORD-COMM-REV-001',
            'status' => 'accepted',
            'locked_by' => $admin->id,
            'locked_at' => now(),
            'accepted_by' => $admin->id,
            'accepted_at' => now()->subHour(),
            'total_commission' => 45,
        ]);

        $original = Commission::create([
            'user_id' => $commissionRecipient->id,
            'dist_order_id' => $order->id,
            'type' => 'purchase_commission',
            'cr_dr' => 'Cr',
            'base_amount' => 450,
            'commission_percent' => 10,
            'gross_commission' => 45,
            'tds_percent' => 5,
            'tds_amount' => 2.25,
            'net_payable' => 42.75,
            'description' => 'Legacy early commission for test',
            'status' => 'pending',
            'trigger_event' => 'accepted',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dist-orders.reject', $order->id), [
                'rejection_reason' => 'Commercial terms withdrawn.',
            ])
            ->assertRedirect();

        $order->refresh();
        $original->refresh();
        $reversal = Commission::query()
            ->where('reverses_commission_id', $original->id)
            ->firstOrFail();

        $this->assertSame('rejected', $order->status);
        $this->assertSame(0.0, (float) $order->total_commission);
        $this->assertSame('reversed', $original->status);
        $this->assertSame($admin->id, $original->reversed_by);
        $this->assertNotNull($original->reversed_at);
        $this->assertSame('Commercial terms withdrawn.', $original->reversal_reason);

        $this->assertSame('Dr', $reversal->cr_dr);
        $this->assertSame('reversed', $reversal->status);
        $this->assertSame('reversal', $reversal->trigger_event);
        $this->assertSame($commissionRecipient->id, $reversal->user_id);
        $this->assertSame(42.75, (float) $reversal->net_payable);

        $this->assertDatabaseHas('financial_ledgers', [
            'ledgerable_type' => User::class,
            'ledgerable_id' => $commissionRecipient->id,
            'transaction_type' => 'COMMISSION_REVERSAL',
            'debit' => 42.75,
            'reference_type' => Commission::class,
            'reference_id' => $reversal->id,
        ]);
    }

    private function makeFranchiseUser(array $permissions): User
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
            'username' => 'fr_' . Str::lower(Str::random(6)),
            'is_active' => true,
            'franchisee_id' => $franchisee->id,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeAdminUser(array $permissions): User
    {
        $role = $this->makeRole('Admin', $permissions);

        $user = User::factory()->create([
            'username' => 'admin_' . Str::lower(Str::random(6)),
            'is_active' => true,
            'franchisee_id' => null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function makeCommissionRecipientUser(float $purchasePercent, float $tdsPercent): User
    {
        $franchisee = Franchisee::create([
            'shop_name' => 'Head Shop ' . Str::upper(Str::random(4)),
            'shop_code' => 'HD-' . random_int(1000, 9999),
            'owner_name' => 'Head Owner',
            'mobile' => (string) random_int(9000000000, 9999999999),
            'status' => 'active',
            'purchase_commission_percent' => $purchasePercent,
            'tds_percent' => $tdsPercent,
        ]);

        return User::factory()->create([
            'username' => 'head_' . Str::lower(Str::random(6)),
            'is_active' => true,
            'franchisee_id' => $franchisee->id,
        ]);
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

    private function createSupportRecords(): array
    {
        $company = CompanyMaster::create(['name' => 'Acme Pharma']);
        $category = ItemCategory::create(['name' => 'Tablet']);
        $salt = SaltMaster::create(['name' => 'Paracetamol']);
        $boxSize = (new BoxSize())->forceFill(['size_name' => '10x10']);
        $boxSize->save();
        $hsn = HsnMaster::create([
            'hsn_code' => '30049099',
            'cgst_percent' => 6,
            'sgst_percent' => 6,
            'igst_percent' => 12,
        ]);
        $rackSection = RackSection::create(['name' => 'Section A', 'status' => true]);
        $rackArea = RackArea::create([
            'rack_section_id' => $rackSection->id,
            'name' => 'A-01',
            'status' => true,
        ]);

        return compact('company', 'category', 'salt', 'boxSize', 'hsn', 'rackSection', 'rackArea');
    }

    private function createProduct(array $support, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'company_id' => $support['company']->id,
            'category_id' => $support['category']->id,
            'salt_id' => $support['salt']->id,
            'hsn_id' => $support['hsn']->id,
            'box_size_id' => $support['boxSize']->id,
            'product_name' => 'Default Product',
            'sku' => 'DEFAULT-' . Str::upper(Str::random(6)),
            'barcode' => null,
            'product_code' => 'PRD-' . random_int(100000, 999999),
            'product_type' => 'Normal',
            'mrp' => 100,
            'ptr' => 80,
            'pts' => 75,
            'cost' => 60,
            'rate_a' => 78,
            'conversion_factor' => 1,
            'rack_section_id' => $support['rackSection']->id,
            'rack_area_id' => $support['rackArea']->id,
            'is_active' => true,
            'hide' => false,
            'is_banned' => false,
            'is_commissionable' => false,
            'sgst' => 6,
            'cgst' => 6,
            'igst' => 12,
        ], $overrides));
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

    private function createDistOrderItem(DistOrder $order, Product $product, array $overrides = []): DistOrderItem
    {
        return DistOrderItem::create(array_merge([
            'dist_order_id' => $order->id,
            'product_id' => $product->id,
            'request_qty' => 5,
            'approved_qty' => null,
            'free_qty' => 0,
            'mrp' => 100,
            'rate' => 80,
            'discount_percent' => 0,
            'gst_percent' => 12,
            'taxable_amount' => 0,
            'gst_amount' => 0,
            'total_amount' => 0,
            'commission_amount' => 0,
        ], $overrides));
    }

    private function seedWarehouseStock(Product $product, string $batchNo, float $qty, int $userId): void
    {
        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => $batchNo,
            'expiry_date' => now()->addYear()->toDateString(),
            'mrp' => $product->mrp,
            'location_type' => 'warehouse',
            'location_id' => 0,
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'test_seed',
            'reference_id' => null,
            'qty_in' => $qty,
            'qty_out' => 0,
            'rate' => $product->franchiseRate(),
            'created_by' => $userId,
        ]);
    }
}
