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
use App\Models\PurchaseReturn;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SaltMaster;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseReturnControllerTest extends TestCase
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

    public function test_linked_purchase_return_uses_original_invoice_rate_and_gst_not_client_payload(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Return Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Return Product',
            'sku' => 'RET-PUR-001',
            'mrp' => 150,
            'is_active' => true,
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-2025-26-0099',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 500,
            'discount_amount' => 0,
            'sgst_amount' => 30,
            'cgst_amount' => 30,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 560,
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'RET-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonths(2)->toDateString(),
            'qty' => 5,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 150,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 60,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 500,
            'total_amount' => 560,
        ]);

        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => 'RET-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonths(2)->toDateString(),
            'mrp' => 150,
            'location_type' => 'warehouse',
            'location_id' => 0,
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'purchase_invoice',
            'reference_id' => $invoice->id,
            'qty_in' => 5,
            'qty_out' => 0,
            'rate' => 100,
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('admin.purchase-returns.store'), [
                'supplier_id' => $supplier->id,
                'purchase_invoice_id' => $invoice->id,
                'return_date' => now()->toDateString(),
                'reason' => 'Damaged strip',
                'items' => [[
                    'product_id' => $product->id,
                    'batch_no' => 'RET-BATCH-1',
                    'expiry_date' => now()->addYears(2)->toDateString(), // tampered
                    'qty' => 1,
                    'rate' => 999, // tampered
                    'gst_percent' => 99, // tampered
                    'reason' => 'damaged',
                ]],
            ]);

        $response->assertRedirect(route('admin.purchase-returns.index'));

        $return = PurchaseReturn::query()->firstOrFail();
        $item = $return->items()->firstOrFail();

        $this->assertSame(100.0, (float) $item->rate);
        $this->assertSame(12.0, (float) $item->gst_percent);
        $this->assertSame(12.0, (float) $item->gst_amount);
        $this->assertSame(112.0, (float) $item->total_amount);

        $this->assertSame(100.0, (float) $return->subtotal);
        $this->assertSame(6.0, (float) $return->sgst_amount);
        $this->assertSame(6.0, (float) $return->cgst_amount);
        $this->assertSame(112.0, (float) $return->total_amount);
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