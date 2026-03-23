<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\BoxSize;
use App\Models\Commission;
use App\Models\CompanyMaster;
use App\Models\DistOrder;
use App\Models\Franchisee;
use App\Models\HsnMaster;
use App\Models\InventoryLedger;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SaltMaster;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReportControllerTest extends TestCase
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

    public function test_commission_report_renders_and_exports_csv(): void
    {
        $admin = $this->makeAdminUser([
            'module.reports_commissions.view',
            'view reports',
        ]);

        $franchisee = $this->createFranchisee('Commission Shop');
        $order = DistOrder::create([
            'order_number' => 'ORD-COMM-001',
            'franchisee_id' => $franchisee->id,
            'user_id' => $admin->id,
            'status' => 'accepted',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'sgst_amount' => 0,
            'cgst_amount' => 0,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 1000,
        ]);

        Commission::create([
            'user_id' => $admin->id,
            'dist_order_id' => $order->id,
            'type' => 'purchase_commission',
            'cr_dr' => 'Cr',
            'base_amount' => 1000,
            'commission_percent' => 10,
            'gross_commission' => 100,
            'tds_percent' => 5,
            'tds_amount' => 5,
            'net_payable' => 95,
            'description' => 'Commission for ORD-COMM-001',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('reports.commissions'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Commission/Index')
                ->where('summary.total_gross', 100)
                ->where('summary.total_net', 95)
                ->where('commissions.data.0.order_ref', 'ORD-COMM-001')
            );

        $response = $this->actingAs($admin)
            ->get(route('reports.commissions', ['format' => 'csv']));

        $response->assertOk();
        $this->assertStringContainsString('commission_report_', (string) $response->headers->get('content-disposition'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('ORD-COMM-001', $content);
        $this->assertStringContainsString('purchase_commission', $content);
    }

    public function test_near_expiry_dispatch_csv_export_contains_risky_batch(): void
    {
        $admin = $this->makeAdminUser([
            'module.reports_stock.view',
            'view reports',
        ]);

        $support = $this->createSupportRecords();
        $franchisee = $this->createFranchisee('Risk Shop', 'RISK-001');
        $product = $this->createProduct($support, [
            'product_name' => 'Risk Product',
            'sku' => 'RISK-PROD',
        ]);

        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => 'RISK-BATCH-1',
            'expiry_date' => now()->addDays(14)->toDateString(),
            'mrp' => 125,
            'location_type' => 'franchisee',
            'location_id' => $franchisee->id,
            'transaction_type' => 'RECEIVE',
            'reference_type' => 'seed',
            'reference_id' => 1,
            'qty_in' => 12,
            'qty_out' => 0,
            'rate' => 80,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.stock.near-expiry-dispatch', ['days' => 30, 'format' => 'csv']));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Risk Shop', $content);
        $this->assertStringContainsString('Risk Product', $content);
        $this->assertStringContainsString('RISK-BATCH-1', $content);
    }

    public function test_franchisee_sales_csv_export_contains_sales_rows(): void
    {
        $admin = $this->makeAdminUser([
            'module.reports_bi.view',
            'view reports',
        ]);

        $shopA = $this->createFranchisee('Alpha Store', 'ALPHA-01');
        $shopB = $this->createFranchisee('Beta Store', 'BETA-01');

        SalesInvoice::create([
            'bill_no' => 'INV-ALPHA-1',
            'franchisee_id' => $shopA->id,
            'user_id' => $admin->id,
            'date_time' => now()->subDays(3),
            'sub_total' => 200,
            'total_discount_amount' => 0,
            'total_tax_amount' => 24,
            'other_charges' => 0,
            'total_amount' => 224,
            'status' => 'completed',
        ]);

        SalesInvoice::create([
            'bill_no' => 'INV-BETA-1',
            'franchisee_id' => $shopB->id,
            'user_id' => $admin->id,
            'date_time' => now()->subDays(2),
            'sub_total' => 100,
            'total_discount_amount' => 0,
            'total_tax_amount' => 12,
            'other_charges' => 0,
            'total_amount' => 112,
            'status' => 'completed',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.bi.franchisee-sales', ['days' => 30, 'format' => 'csv']));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Alpha Store', $content);
        $this->assertStringContainsString('Beta Store', $content);
        $this->assertStringContainsString('Top Performer', $content);
    }

    public function test_growth_csv_export_includes_trend_franchise_and_product_rows(): void
    {
        $admin = $this->makeAdminUser([
            'module.reports_bi.view',
            'view reports',
        ]);

        $support = $this->createSupportRecords();
        $franchisee = $this->createFranchisee('Growth Shop', 'GROW-01');
        $product = $this->createProduct($support, [
            'product_name' => 'Growth Product',
            'sku' => 'GROW-PROD',
        ]);

        $currentInvoice = SalesInvoice::create([
            'bill_no' => 'INV-GROW-CUR',
            'franchisee_id' => $franchisee->id,
            'user_id' => $admin->id,
            'date_time' => now()->subDays(5),
            'sub_total' => 300,
            'total_discount_amount' => 0,
            'total_tax_amount' => 36,
            'other_charges' => 0,
            'total_amount' => 336,
            'status' => 'completed',
        ]);

        SalesInvoice::create([
            'bill_no' => 'INV-GROW-PREV',
            'franchisee_id' => $franchisee->id,
            'user_id' => $admin->id,
            'date_time' => now()->subDays(40),
            'sub_total' => 150,
            'total_discount_amount' => 0,
            'total_tax_amount' => 18,
            'other_charges' => 0,
            'total_amount' => 168,
            'status' => 'completed',
        ]);

        SalesInvoice::create([
            'bill_no' => 'INV-GROW-YEAR',
            'franchisee_id' => $franchisee->id,
            'user_id' => $admin->id,
            'date_time' => now()->subYear()->subDays(3),
            'sub_total' => 120,
            'total_discount_amount' => 0,
            'total_tax_amount' => 14.4,
            'other_charges' => 0,
            'total_amount' => 134.4,
            'status' => 'completed',
        ]);

        SalesInvoiceItem::create([
            'sales_invoice_id' => $currentInvoice->id,
            'product_id' => $product->id,
            'batch_no' => 'GROW-BATCH',
            'exp_date' => now()->addMonths(6)->toDateString(),
            'qty' => 2,
            'free_qty' => 0,
            'mrp' => 150,
            'rate' => 150,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'taxable_amount' => 300,
            'gst_percent' => 12,
            'gst_amount' => 36,
            'total_amount' => 336,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('reports.bi.growth', ['days' => 30, 'format' => 'csv']));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Trend', $content);
        $this->assertStringContainsString('Franchise', $content);
        $this->assertStringContainsString('Product', $content);
        $this->assertStringContainsString('Growth Shop', $content);
        $this->assertStringContainsString('Growth Product', $content);
    }

    private function makeAdminUser(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin']);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'username' => 'admin_' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createFranchisee(string $shopName, ?string $shopCode = null): Franchisee
    {
        return Franchisee::create([
            'shop_name' => $shopName,
            'shop_code' => $shopCode,
            'owner_name' => 'Owner Name',
            'mobile' => (string) random_int(9000000000, 9999999999),
            'status' => 'active',
        ]);
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
            'sku' => 'SKU-' . Str::upper(Str::random(6)),
            'product_code' => 'PRD-' . random_int(100000, 999999),
            'barcode' => null,
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
        ], $overrides));
    }
}
