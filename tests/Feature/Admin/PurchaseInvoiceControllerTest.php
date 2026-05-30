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
use App\Services\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
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

    public function test_draft_invoice_show_exposes_edit_action_and_update_changes_totals(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Editable Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Editable Product',
            'sku' => 'PUR-EDIT-001',
            'mrp' => 200,
            'is_active' => true,
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0100',
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'EDIT-REF-1',
            'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'discount_amount' => 0,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 112,
            'tax_type' => 'intra_state',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'EDIT-BATCH-1',
            'expiry_date' => now()->addYear()->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'qty' => 1,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 200,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 12,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 100,
            'total_amount' => 112,
        ]);

        $this->actingAs($user)
            ->get(route('admin.purchase-invoices.show', $invoice->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/PurchaseInvoices/Show')
                ->where('actions.can_edit', true)
                ->where('actions.can_approve', true)
                ->where('actions.can_cancel', true)
            );

        $this->actingAs($user)
            ->put(route('admin.purchase-invoices.update', $invoice->id), [
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => 'EDIT-REF-1A',
                'invoice_date' => now()->toDateString(),
                'received_date' => now()->toDateString(),
                'due_days' => 15,
                'tax_type' => 'intra_state',
                'items' => [[
                    'product_id' => $product->id,
                    'batch_no' => 'EDIT-BATCH-2',
                    'expiry_date' => now()->addYear()->toDateString(),
                    'mfg_date' => now()->subMonth()->toDateString(),
                    'qty' => 2,
                    'free_qty' => 1,
                    'mrp' => 200,
                    'rate' => 125,
                    'discount_percent' => 10,
                    'gst_percent' => 12,
                    'hsn_id' => $support['hsn']->id,
                ]],
            ])
            ->assertRedirect(route('admin.purchase-invoices.show', $invoice->id));

        $invoice->refresh();
        $item = $invoice->items()->firstOrFail();

        $this->assertSame('EDIT-REF-1A', $invoice->supplier_invoice_no);
        $this->assertSame(225.0, (float) $invoice->subtotal);
        $this->assertSame(13.5, (float) $invoice->sgst_amount);
        $this->assertSame(13.5, (float) $invoice->cgst_amount);
        $this->assertSame(252.0, (float) $invoice->total_amount);
        $this->assertSame('EDIT-BATCH-2', $item->batch_no);
        $this->assertSame(225.0, (float) $item->taxable_amount);
        $this->assertSame(27.0, (float) $item->gst_amount);
    }

    public function test_approved_invoice_update_is_blocked(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Locked Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Locked Product',
            'sku' => 'PUR-LOCK-001',
            'mrp' => 150,
            'is_active' => true,
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0101',
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => 'LOCK-REF-1',
            'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'discount_amount' => 0,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 112,
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'LOCK-BATCH-1',
            'expiry_date' => now()->addYear()->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'qty' => 1,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 150,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 12,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 100,
            'total_amount' => 112,
        ]);

        $this->actingAs($user)
            ->from(route('admin.purchase-invoices.show', $invoice->id))
            ->put(route('admin.purchase-invoices.update', $invoice->id), [
                'supplier_id' => $supplier->id,
                'supplier_invoice_no' => 'LOCK-REF-2',
                'invoice_date' => now()->toDateString(),
                'received_date' => now()->toDateString(),
                'due_days' => 0,
                'tax_type' => 'intra_state',
                'items' => [[
                    'product_id' => $product->id,
                    'batch_no' => 'LOCK-BATCH-2',
                    'expiry_date' => now()->addYear()->toDateString(),
                    'mfg_date' => now()->subMonth()->toDateString(),
                    'qty' => 2,
                    'free_qty' => 0,
                    'mrp' => 150,
                    'rate' => 120,
                    'discount_percent' => 0,
                    'gst_percent' => 12,
                    'hsn_id' => $support['hsn']->id,
                ]],
            ])
            ->assertRedirect(route('admin.purchase-invoices.show', $invoice->id))
            ->assertSessionHas('error', 'Only draft invoices can be updated.');

        $invoice->refresh();
        $item = $invoice->items()->firstOrFail();

        $this->assertSame('LOCK-REF-1', $invoice->supplier_invoice_no);
        $this->assertSame('LOCK-BATCH-1', $item->batch_no);
        $this->assertSame(112.0, (float) $invoice->total_amount);
    }

    public function test_purchase_invoice_export_supports_excel_download(): void
    {
        $user = $this->makeSuperAdminUser();

        $supplier = Supplier::create([
            'name' => 'Export Supplier',
            'is_active' => true,
        ]);

        PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0999',
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
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.purchase-invoices.export', ['format' => 'excel']));

        $response->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('content-disposition'));
    }

    public function test_cancel_is_blocked_when_linked_purchase_returns_exist(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Linked Return Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Linked Return Product',
            'sku' => 'PUR-LINK-001',
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0102',
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
            'batch_no' => 'LINK-BATCH-1',
            'expiry_date' => now()->addMonths(8)->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'qty' => 5,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 120,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 60,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 500,
            'total_amount' => 560,
        ]);

        PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0102',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'total_amount' => 112,
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.purchase-invoices.show', $invoice->id))
            ->post(route('admin.purchase-invoices.cancel', $invoice->id));

        $response->assertRedirect(route('admin.purchase-invoices.show', $invoice->id));
        $response->assertSessionHasErrors('status');

        $invoice->refresh();
        $this->assertSame('approved', $invoice->status);
    }

    public function test_show_exposes_return_summary_and_hides_cancel_when_returns_are_active(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Return Summary Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Return Summary Product',
            'sku' => 'PUR-SUM-001',
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0103',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 300,
            'discount_amount' => 0,
            'sgst_amount' => 18,
            'cgst_amount' => 18,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 336,
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'SUM-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonth()->toDateString(),
            'qty' => 5,
            'free_qty' => 1,
            'unit' => 'pcs',
            'mrp' => 100,
            'rate' => 50,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 36,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 300,
            'total_amount' => 336,
        ]);

        $approvedReturn = PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0103',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'total_amount' => 112,
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
        ]);

        $approvedReturn->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'SUM-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'qty' => 2,
            'rate' => 50,
            'gst_percent' => 12,
            'gst_amount' => 12,
            'total_amount' => 112,
        ]);

        $draftReturn = PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0104',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 50,
            'sgst_amount' => 3,
            'cgst_amount' => 3,
            'igst_amount' => 0,
            'total_amount' => 56,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $draftReturn->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'SUM-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'qty' => 1,
            'rate' => 50,
            'gst_percent' => 12,
            'gst_amount' => 6,
            'total_amount' => 56,
        ]);

        $this->actingAs($user)
            ->get(route('admin.purchase-invoices.show', $invoice->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/PurchaseInvoices/Show')
                ->where('actions.can_cancel', false)
                ->where('actions.can_create_return', true)
                ->where('returnSummary.purchased_qty', 6)
                ->where('returnSummary.approved_returned_qty', 2)
                ->where('returnSummary.draft_return_qty', 1)
                ->where('returnSummary.remaining_returnable_qty', 4)
                ->where('linkedReturns.0.return_number', 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0103')
            );
    }

    public function test_inventory_service_normalizes_batch_numbers_and_inherits_batch_metadata(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Batch Guard Product',
            'sku' => 'BATCH-GUARD-001',
        ]);

        $inventoryService = app(InventoryService::class);

        $inventoryService->recordPurchase([
            'product_id' => $product->id,
            'batch_no' => ' t250820 ',
            'expiry_date' => '2027-02-01',
            'mfg_date' => '2025-03-01',
            'mrp' => 65,
            'qty' => 10,
            'free_qty' => 0,
            'rate' => 40,
            'reference_id' => 1001,
            'created_by' => $user->id,
        ]);

        $inventoryService->recordDispatch([
            'product_id' => $product->id,
            'batch_no' => 'T250820',
            'franchisee_id' => 123,
            'qty' => 2,
            'rate' => 40,
            'order_id' => 2001,
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->id,
            'batch_no' => 'T250820',
            'location_type' => 'warehouse',
            'transaction_type' => 'PURCHASE',
        ]);

        $received = InventoryLedger::query()
            ->where('product_id', $product->id)
            ->where('batch_no', 'T250820')
            ->where('location_type', 'franchisee')
            ->where('location_id', 123)
            ->where('transaction_type', 'RECEIVE')
            ->firstOrFail();

        $this->assertSame('2027-02-01', $received->expiry_date->toDateString());
        $this->assertSame('2025-03-01', $received->mfg_date->toDateString());
        $this->assertSame(65.0, (float) $received->mrp);
    }

    public function test_inventory_service_blocks_conflicting_metadata_for_existing_product_batch(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();
        $product = $this->createProduct($support, [
            'product_name' => 'Duplicate Batch Product',
            'sku' => 'DUP-BATCH-001',
        ]);

        $inventoryService = app(InventoryService::class);

        $inventoryService->recordPurchase([
            'product_id' => $product->id,
            'batch_no' => 'T250820',
            'expiry_date' => '2027-02-01',
            'mfg_date' => '2025-03-01',
            'mrp' => 65,
            'qty' => 10,
            'free_qty' => 0,
            'rate' => 40,
            'reference_id' => 1001,
            'created_by' => $user->id,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Batch metadata conflict');

        $inventoryService->recordPurchase([
            'product_id' => $product->id,
            'batch_no' => 'T250820',
            'expiry_date' => '2027-02-01',
            'mfg_date' => '2025-03-01',
            'mrp' => 60.94,
            'qty' => 5,
            'free_qty' => 0,
            'rate' => 35,
            'reference_id' => 1002,
            'created_by' => $user->id,
        ]);
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
