<?php

namespace Tests\Feature\POS;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\BoxSize;
use App\Models\CompanyMaster;
use App\Models\Franchisee;
use App\Models\HsnMaster;
use App\Models\InventoryLedger;
use App\Models\ItemCategory;
use App\Models\Product;
use App\Models\RackArea;
use App\Models\RackSection;
use App\Models\SalePayment;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceItem;
use App\Models\SaltMaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class POSControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_uses_hsn_tax_and_authoritative_invoice_totals(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'POS HSN Product',
            'sku' => 'POS-001',
            'barcode' => '7777777777',
            'rate_a' => null,
            'ptr' => 82,
            'pts' => 79,
            'mrp' => 100,
            'sgst' => 0,
            'cgst' => 0,
            'igst' => 0,
        ]);

        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => 'POS-BATCH-1',
            'expiry_date' => now()->addMonths(9),
            'mrp' => 100,
            'location_type' => 'franchisee',
            'location_id' => $user->franchisee_id,
            'transaction_type' => 'RECEIVE',
            'reference_type' => 'seed',
            'reference_id' => 1,
            'qty_in' => 10,
            'qty_out' => 0,
            'rate' => 82,
        ]);

        $response = $this->actingAs($user)->postJson(route('pos.checkout'), [
            'bill_no' => 'POS-TEST-001',
            'items' => [[
                'product_id' => $product->id,
                'batch_no' => 'POS-BATCH-1',
                'expiry_date' => now()->addMonths(9)->format('Y-m-d'),
                'mrp' => 100,
                'rate' => 82,
                'qty' => 2,
                'free_qty' => 0,
                'discount_percent' => 0,
            ]],
            'payment_mode' => 'cash',
            'cash_amount' => 184,
            'bank_amount' => 0,
            'credit_amount' => 0,
            'transaction_no' => null,
            'wallet_type' => null,
            'sub_total' => 0,
            'total_discount_amount' => 0,
            'total_tax_amount' => 0,
            'other_charges' => 0,
            'total_amount' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $invoice = SalesInvoice::where('bill_no', 'POS-TEST-001')->firstOrFail();
        $item = SalesInvoiceItem::where('sales_invoice_id', $invoice->id)->firstOrFail();

        $this->assertSame('164.00', (string) $invoice->sub_total);
        $this->assertSame('19.68', (string) $invoice->total_tax_amount);
        $this->assertSame('184.00', (string) $invoice->total_amount);
        $this->assertSame('12.00', (string) $item->gst_percent);
        $this->assertSame('19.68', (string) $item->gst_amount);
        $this->assertSame('183.68', (string) $item->total_amount);
        $this->assertSame(now()->addMonths(9)->format('Y-m-d'), optional($item->exp_date)->format('Y-m-d'));

        $this->assertDatabaseHas('sale_payments', [
            'sales_invoice_id' => $invoice->id,
            'payment_mode' => 'cash',
            'cash_amount' => 184,
        ]);
        $this->assertDatabaseHas('inventory_ledgers', [
            'reference_type' => 'sales_invoice',
            'reference_id' => $invoice->id,
            'transaction_type' => 'SALE',
            'qty_out' => 2,
            'batch_no' => 'POS-BATCH-1',
        ]);
    }

    public function test_process_return_rebalances_invoice_line_math_and_restocks_batch(): void
    {
        $this->withoutMiddleware([
            EnsureTwoFactorIsVerified::class,
            EnsurePasswordResetCompleted::class,
        ]);

        $user = $this->makeFranchiseUser();
        $support = $this->createSupportRecords();

        $product = $this->createProduct($support, [
            'product_name' => 'Return Product',
            'sku' => 'RET-001',
            'mrp' => 100,
            'rate_a' => 82,
        ]);

        $invoice = SalesInvoice::create([
            'bill_no' => 'POS-RET-001',
            'franchisee_id' => $user->franchisee_id,
            'user_id' => $user->id,
            'date_time' => now(),
            'sub_total' => 164.00,
            'total_discount_amount' => 0,
            'total_tax_amount' => 19.68,
            'other_charges' => 0,
            'total_amount' => 184.00,
            'status' => 'completed',
        ]);

        $item = SalesInvoiceItem::create([
            'sales_invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'batch_no' => 'RET-BATCH-1',
            'exp_date' => now()->addMonths(6)->format('Y-m-d'),
            'qty' => 2,
            'free_qty' => 0,
            'mrp' => 100,
            'rate' => 82,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'taxable_amount' => 164.00,
            'gst_percent' => 12.00,
            'gst_amount' => 19.68,
            'total_amount' => 183.68,
        ]);

        SalePayment::create([
            'sales_invoice_id' => $invoice->id,
            'payment_mode' => 'cash',
            'cash_amount' => 184,
            'bank_amount' => 0,
            'credit_amount' => 0,
        ]);

        $response = $this->actingAs($user)->postJson(route('pos.processReturn'), [
            'original_bill_no' => 'POS-RET-001',
            'items' => [[
                'sales_invoice_item_id' => $item->id,
                'return_qty' => 1,
            ]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('return_amount', 91.84);

        $item->refresh();
        $invoice->refresh();

        $this->assertSame('1.00', (string) $item->qty);
        $this->assertSame('82.00', (string) $item->taxable_amount);
        $this->assertSame('9.84', (string) $item->gst_amount);
        $this->assertSame('91.84', (string) $item->total_amount);
        $this->assertSame('82.00', (string) $invoice->sub_total);
        $this->assertSame('9.84', (string) $invoice->total_tax_amount);
        $this->assertSame('92.00', (string) $invoice->total_amount);

        $this->assertDatabaseHas('inventory_ledgers', [
            'reference_type' => 'sales_return',
            'reference_id' => $invoice->id,
            'transaction_type' => 'RETURN_SALE',
            'qty_in' => 1,
            'batch_no' => 'RET-BATCH-1',
        ]);
    }

    private function makeFranchiseUser(): User
    {
        Permission::create(['name' => 'module.pos.view']);
        Permission::create(['name' => 'module.pos.create']);
        Permission::create(['name' => 'module.pos.update']);

        $role = Role::create(['name' => 'Franchisee']);
        $role->givePermissionTo(['module.pos.view', 'module.pos.create', 'module.pos.update']);

        $franchisee = Franchisee::create([
            'shop_name' => 'Test Franchise',
            'owner_name' => 'Owner Name',
            'mobile' => '9999999999',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'username' => 'fr_' . Str::lower(Str::random(8)),
            'is_active' => true,
            'franchisee_id' => $franchisee->id,
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