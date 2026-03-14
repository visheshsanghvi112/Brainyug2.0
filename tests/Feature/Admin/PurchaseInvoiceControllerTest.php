<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\BoxSize;
use App\Models\CompanyMaster;
use App\Models\HsnMaster;
use App\Models\InventoryLedger;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SaltMaster;
use App\Models\Supplier;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceControllerTest extends TestCase
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

    public function test_store_rolls_up_discount_amount_from_line_items(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Acme Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Purchase Product',
            'sku' => 'PUR-001',
            'mrp' => 150,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post(route('admin.purchase-invoices.store'), [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'SINV-1001',
            'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(),
            'due_days' => 30,
            'tax_type' => 'intra_state',
            'items' => [[
                'product_id' => $product->id,
                'batch_no' => 'BATCH-1',
                'expiry_date' => now()->addYear()->toDateString(),
                'mfg_date' => now()->subMonth()->toDateString(),
                'qty' => 2,
                'free_qty' => 1,
                'mrp' => 150,
                'rate' => 100,
                'discount_percent' => 10,
                'gst_percent' => 12,
                'hsn_id' => $support['hsn']->id,
            ]],
        ]);

        $response->assertRedirect(route('admin.purchase-invoices.index'));

        $invoice = PurchaseInvoice::query()->firstOrFail();
        $item = $invoice->items()->firstOrFail();

        $this->assertSame(20.0, (float) $invoice->discount_amount);
        $this->assertSame(180.0, (float) $invoice->subtotal);
        $this->assertSame(10.8, (float) $invoice->sgst_amount);
        $this->assertSame(10.8, (float) $invoice->cgst_amount);
        $this->assertSame(202.0, (float) $invoice->total_amount);

        $this->assertSame(20.0, (float) $item->discount_amount);
        $this->assertSame(180.0, (float) $item->taxable_amount);
        $this->assertSame(21.6, (float) $item->gst_amount);
        $this->assertSame(201.6, (float) $item->total_amount);
    }

    public function test_cancel_prevents_approved_invoice_reversal_when_batch_stock_is_consumed(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Stock Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Stock Product',
            'sku' => 'PUR-002',
            'mrp' => 120,
            'is_active' => true,
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-2025-26-0001',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 500,
            'discount_amount' => 0,
            'sgst_amount' => 0,
            'cgst_amount' => 0,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 500,
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'REV-BATCH-1',
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'qty' => 5,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 120,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 0,
            'gst_amount' => 0,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 500,
            'total_amount' => 500,
        ]);

        $inventoryService = app(InventoryService::class);

        $inventoryService->recordPurchase([
            'product_id' => $product->id,
            'batch_no' => 'REV-BATCH-1',
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'mrp' => 120,
            'qty' => 5,
            'free_qty' => 0,
            'rate' => 100,
            'reference_id' => $invoice->id,
            'created_by' => $user->id,
        ]);

        $inventoryService->recordAdjustment([
            'product_id' => $product->id,
            'batch_no' => 'REV-BATCH-1',
            'expiry_date' => now()->addMonths(6)->toDateString(),
            'mrp' => 120,
            'location_type' => 'warehouse',
            'location_id' => 0,
            'qty' => -3,
            'rate' => 100,
            'created_by' => $user->id,
            'remarks' => 'Consumption simulation',
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.purchase-invoices.show', $invoice->id))
            ->post(route('admin.purchase-invoices.cancel', $invoice->id));

        $response->assertRedirect(route('admin.purchase-invoices.show', $invoice->id));
        $response->assertSessionHasErrors('status');

        $invoice->refresh();
        $this->assertSame('approved', $invoice->status);

        $stock = (float) InventoryLedger::query()
            ->where('product_id', $product->id)
            ->where('batch_no', 'REV-BATCH-1')
            ->where('location_type', 'warehouse')
            ->where('location_id', 0)
            ->selectRaw('COALESCE(SUM(qty_in),0) - COALESCE(SUM(qty_out),0) as stock')
            ->value('stock');

        $this->assertSame(2.0, $stock);
    }

    private function makeSuperAdminUser(): User
    {
        $role = Role::firstOrCreate(['name' => 'Super Admin']);

        $user = User::factory()->create([
            'username' => 'sa_' . Str::lower(Str::random(8)),
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
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
        ], $overrides));
    }
}