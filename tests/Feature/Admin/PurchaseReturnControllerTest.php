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
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
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

    public function test_create_page_prefills_purchase_return_from_approved_invoice(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Prefill Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Prefill Product',
            'sku' => 'RET-PREFILL-001',
            'mrp' => 160,
            'is_active' => true,
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-2025-26-0105',
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
            'batch_no' => 'RET-PREFILL-BATCH',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonths(2)->toDateString(),
            'qty' => 5,
            'free_qty' => 1,
            'unit' => 'pcs',
            'mrp' => 160,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 72,
            'hsn_id' => $support['hsn']->id,
            'taxable_amount' => 600,
            'total_amount' => 672,
        ]);

        $approvedReturn = PurchaseReturn::create([
            'return_number' => 'PR-2025-26-0008',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 200,
            'sgst_amount' => 12,
            'cgst_amount' => 12,
            'igst_amount' => 0,
            'total_amount' => 224,
            'status' => 'approved',
            'reason' => 'Earlier return',
            'created_by' => $user->id,
            'approved_by' => $user->id,
        ]);

        $approvedReturn->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'RET-PREFILL-BATCH',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'qty' => 2,
            'rate' => 100,
            'gst_percent' => 12,
            'gst_amount' => 24,
            'total_amount' => 224,
        ]);

        $this->actingAs($user)
            ->get(route('admin.purchase-returns.create', ['purchase_invoice_id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/PurchaseReturns/CreateEdit')
                ->where('prefillInvoice.id', $invoice->id)
                ->where('prefillInvoice.supplier_id', $supplier->id)
                ->where('prefillInvoice.invoice_number', 'PI-2025-26-0105')
                ->where('prefillInvoice.items.0.batch_no', 'RET-PREFILL-BATCH')
                ->where('prefillInvoice.items.0.rate', 100)
                ->where('prefillInvoice.items.0.gst_percent', 12)
                ->where('prefillInvoice.items.0.max_qty', 4)
                ->where('prefillInvoice.items.0.qty', 4)
            );
    }

    public function test_purchase_return_export_supports_pdf_download(): void
    {
        $user = $this->makeSuperAdminUser();

        $supplier = Supplier::create([
            'name' => 'Return Export Supplier',
            'is_active' => true,
        ]);

        PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0999',
            'supplier_id' => $supplier->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'total_amount' => 112,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.purchase-returns.export', ['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_create_page_redirects_when_invoice_is_already_fully_returned(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Fully Returned Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Fully Returned Product',
            'sku' => 'RET-FULL-001',
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0113',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 200,
            'discount_amount' => 0,
            'sgst_amount' => 12,
            'cgst_amount' => 12,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 224,
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'FULL-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonths(2)->toDateString(),
            'qty' => 2,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 120,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 24,
            'taxable_amount' => 200,
            'total_amount' => 224,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0113',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 200,
            'sgst_amount' => 12,
            'cgst_amount' => 12,
            'igst_amount' => 0,
            'total_amount' => 224,
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
        ]);

        $purchaseReturn->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'FULL-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'qty' => 2,
            'rate' => 100,
            'gst_percent' => 12,
            'gst_amount' => 24,
            'total_amount' => 224,
        ]);

        $response = $this->actingAs($user)
            ->get(route('admin.purchase-returns.create', ['purchase_invoice_id' => $invoice->id]));

        $response->assertRedirect(route('admin.purchase-invoices.show', $invoice->id));
        $response->assertSessionHas('error');
    }

    public function test_store_rejects_linking_purchase_return_to_non_approved_invoice(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Draft Source Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Draft Source Product',
            'sku' => 'RET-DRAFT-001',
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0111',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
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
            'batch_no' => 'DRAFT-BATCH-1',
            'expiry_date' => now()->addMonths(12)->toDateString(),
            'qty' => 1,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 100,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 12,
            'taxable_amount' => 100,
            'total_amount' => 112,
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.purchase-returns.create'))
            ->post(route('admin.purchase-returns.store'), [
                'supplier_id' => $supplier->id,
                'purchase_invoice_id' => $invoice->id,
                'return_date' => now()->toDateString(),
                'items' => [[
                    'product_id' => $product->id,
                    'batch_no' => 'DRAFT-BATCH-1',
                    'expiry_date' => now()->addMonths(12)->toDateString(),
                    'qty' => 1,
                    'rate' => 100,
                    'gst_percent' => 12,
                ]],
            ]);

        $response->assertRedirect(route('admin.purchase-returns.create'));
        $response->assertSessionHasErrors('purchase_invoice_id');
        $this->assertDatabaseCount('purchase_returns', 0);
    }

    public function test_approve_rejects_when_linked_invoice_is_no_longer_approved(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Approval Guard Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Approval Guard Product',
            'sku' => 'RET-APPROVE-001',
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0112',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'discount_amount' => 0,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 112,
            'tax_type' => 'intra_state',
            'status' => 'cancelled',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'APPROVE-BATCH-1',
            'expiry_date' => now()->addMonths(12)->toDateString(),
            'qty' => 2,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 100,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 24,
            'taxable_amount' => 200,
            'total_amount' => 224,
        ]);

        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => 'APPROVE-BATCH-1',
            'expiry_date' => now()->addMonths(12)->toDateString(),
            'mrp' => 100,
            'location_type' => 'warehouse',
            'location_id' => 0,
            'transaction_type' => 'PURCHASE',
            'reference_type' => 'purchase_invoice',
            'reference_id' => $invoice->id,
            'qty_in' => 2,
            'qty_out' => 0,
            'rate' => 100,
            'created_by' => $user->id,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0012',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'total_amount' => 112,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $purchaseReturn->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'APPROVE-BATCH-1',
            'expiry_date' => now()->addMonths(12)->toDateString(),
            'qty' => 1,
            'rate' => 100,
            'gst_percent' => 12,
            'gst_amount' => 12,
            'total_amount' => 112,
        ]);

        $response = $this->actingAs($user)
            ->from(route('admin.purchase-returns.show', $purchaseReturn->id))
            ->post(route('admin.purchase-returns.approve', $purchaseReturn->id));

        $response->assertRedirect(route('admin.purchase-returns.show', $purchaseReturn->id));
        $response->assertSessionHasErrors('purchase_invoice_id');

        $purchaseReturn->refresh();
        $this->assertSame('draft', $purchaseReturn->status);
    }

    public function test_approved_purchase_return_can_be_reversed_with_compensating_stock_and_ledger_entries(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Reversal Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Reversal Product',
            'sku' => 'RET-REV-001',
            'mrp' => 140,
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0201',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->subDays(5)->toDateString(),
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
            'hsn_id' => $product->hsn_id ?? 1,
            'batch_no' => 'REV-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonths(1)->toDateString(),
            'qty' => 5,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 140,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 60,
            'taxable_amount' => 500,
            'total_amount' => 560,
        ]);

        InventoryLedger::create([
            'product_id' => $product->id,
            'batch_no' => 'REV-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'mfg_date' => now()->subMonths(1)->toDateString(),
            'mrp' => 140,
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

        app(LedgerService::class)->recordEntry(
            $supplier,
            'PURCHASE',
            debit: 0,
            credit: 560,
            reference: $invoice,
            paymentMode: 'credit',
            narration: 'Approved purchase invoice',
            transactionDate: $invoice->invoice_date
        );

        $purchaseReturn = PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0201',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 100,
            'sgst_amount' => 6,
            'cgst_amount' => 6,
            'igst_amount' => 0,
            'total_amount' => 112,
            'status' => 'draft',
            'created_by' => $user->id,
            'workflow_status' => 'reversed',
        ]);

        $purchaseReturn->items()->create([
            'product_id' => $product->id,
            'batch_no' => 'REV-BATCH-1',
            'expiry_date' => now()->addMonths(10)->toDateString(),
            'qty' => 1,
            'rate' => 100,
            'gst_percent' => 12,
            'gst_amount' => 12,
            'total_amount' => 112,
        ]);

        $this->actingAs($user)
            ->from(route('admin.purchase-returns.show', $purchaseReturn))
            ->post(route('admin.purchase-returns.approve', $purchaseReturn))
            ->assertRedirect(route('admin.purchase-returns.show', $purchaseReturn));

        $this->actingAs($user)
            ->from(route('admin.purchase-returns.show', $purchaseReturn))
            ->post(route('admin.purchase-returns.reverse', $purchaseReturn), [
                'reason' => 'Supplier accepted replacement stock instead',
            ])
            ->assertRedirect(route('admin.purchase-returns.show', $purchaseReturn))
            ->assertSessionHas('success');

        $purchaseReturn->refresh();

        $this->assertTrue($purchaseReturn->isReversed());
        $this->assertSame('reversed', $purchaseReturn->workflow_status);
        $this->assertSame('Supplier accepted replacement stock instead', $purchaseReturn->reversal_reason);
        $this->assertSame($user->id, $purchaseReturn->reversed_by);

        $netStock = InventoryLedger::query()
            ->where('product_id', $product->id)
            ->where('batch_no', 'REV-BATCH-1')
            ->where('location_type', 'warehouse')
            ->where('location_id', 0)
            ->get()
            ->sum(fn (InventoryLedger $ledger) => (float) $ledger->qty_in - (float) $ledger->qty_out);

        $this->assertSame(5.0, round((float) $netStock, 2));

        $this->assertDatabaseHas('inventory_ledgers', [
            'product_id' => $product->id,
            'batch_no' => 'REV-BATCH-1',
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'purchase_return',
            'reference_id' => $purchaseReturn->id,
            'qty_in' => 1,
            'qty_out' => 0,
        ]);

        $this->assertDatabaseHas('financial_ledgers', [
            'ledgerable_type' => Supplier::class,
            'ledgerable_id' => $supplier->id,
            'transaction_type' => 'PURCHASE_RETURN_REVERSAL',
            'credit' => 112,
            'reference_type' => PurchaseReturn::class,
            'reference_id' => $purchaseReturn->id,
        ]);
    }

    public function test_reversed_purchase_return_is_ignored_for_future_invoice_prefill_capacity(): void
    {
        $user = $this->makeSuperAdminUser();
        $support = $this->createSupportRecords();

        $supplier = Supplier::create([
            'name' => 'Reversed Capacity Supplier',
            'is_active' => true,
        ]);

        $product = $this->createProduct($support, [
            'product_name' => 'Reversed Capacity Product',
            'sku' => 'RET-REV-CAP-001',
        ]);

        $invoice = PurchaseInvoice::create([
            'invoice_number' => 'PI-' . PurchaseInvoice::currentFinancialYear() . '-0202',
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 200,
            'discount_amount' => 0,
            'sgst_amount' => 12,
            'cgst_amount' => 12,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 224,
            'tax_type' => 'intra_state',
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'hsn_id' => $product->hsn_id ?? 1,
            'batch_no' => 'REV-CAP-BATCH-1',
            'expiry_date' => now()->addMonths(9)->toDateString(),
            'mfg_date' => now()->subMonths(2)->toDateString(),
            'qty' => 2,
            'free_qty' => 0,
            'unit' => 'pcs',
            'mrp' => 120,
            'rate' => 100,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'gst_percent' => 12,
            'gst_amount' => 24,
            'taxable_amount' => 200,
            'total_amount' => 224,
        ]);

        $purchaseReturn = PurchaseReturn::create([
            'return_number' => 'PR-' . PurchaseInvoice::currentFinancialYear() . '-0202',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 200,
            'sgst_amount' => 12,
            'cgst_amount' => 12,
            'igst_amount' => 0,
            'total_amount' => 224,
            'status' => 'approved',
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'reversed_by' => $user->id,
            'reversed_at' => now(),
            'reversal_reason' => 'Supplier declined the pickup',
            'workflow_status' => 'reversed',
        ]);

        $purchaseReturn->items()->create([
            'product_id' => $product->id,
            'hsn_id' => $product->hsn_id ?? 1,
            'batch_no' => 'REV-CAP-BATCH-1',
            'expiry_date' => now()->addMonths(9)->toDateString(),
            'qty' => 2,
            'rate' => 100,
            'gst_percent' => 12,
            'gst_amount' => 24,
            'total_amount' => 224,
        ]);

        $this->actingAs($user)
            ->get(route('admin.purchase-returns.create', ['purchase_invoice_id' => $invoice->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/PurchaseReturns/CreateEdit')
                ->where('prefillInvoice.id', $invoice->id)
                ->where('prefillInvoice.items.0.batch_no', 'REV-CAP-BATCH-1')
                ->where('prefillInvoice.items.0.max_qty', 2)
                ->where('prefillInvoice.items.0.qty', 2)
            );
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
