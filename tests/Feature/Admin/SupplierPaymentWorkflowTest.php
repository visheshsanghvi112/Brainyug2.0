<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePasswordResetCompleted;
use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierPaymentWorkflowTest extends TestCase
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

    public function test_supplier_payment_auto_allocates_against_oldest_open_invoices(): void
    {
        $admin = $this->makeAdminUser([
            'module.suppliers.view',
            'module.suppliers.create',
            'module.suppliers.update',
        ]);

        $supplier = $this->createSupplier();
        $olderInvoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-OLD-001',
            'invoice_date' => now()->subDays(15)->toDateString(),
            'total_amount' => 500,
        ]);
        $newerInvoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-NEW-001',
            'invoice_date' => now()->subDays(5)->toDateString(),
            'total_amount' => 400,
        ]);

        $ledgerService = app(LedgerService::class);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 500, reference: $olderInvoice, paymentMode: 'credit', narration: 'Older supplier invoice', transactionDate: $olderInvoice->invoice_date);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 400, reference: $newerInvoice, paymentMode: 'credit', narration: 'Newer supplier invoice', transactionDate: $newerInvoice->invoice_date);

        $this->actingAs($admin)
            ->post(route('admin.suppliers.payments.store', $supplier), [
                'amount' => 550,
                'payment_date' => now()->toDateString(),
                'payment_mode' => 'bank',
                'narration' => 'Bank settlement',
            ])
            ->assertRedirect(route('admin.suppliers.show', $supplier));

        $paymentLedger = $supplier->financialLedgers()
            ->where('transaction_type', 'PAYMENT_MADE')
            ->latest('id')
            ->firstOrFail();

        $this->assertDatabaseHas('financial_ledgers', [
            'id' => $paymentLedger->id,
            'ledgerable_type' => Supplier::class,
            'ledgerable_id' => $supplier->id,
            'transaction_type' => 'PAYMENT_MADE',
            'debit' => 550,
            'payment_mode' => 'bank',
        ]);

        $this->assertDatabaseHas('supplier_payment_allocations', [
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $olderInvoice->id,
            'financial_ledger_id' => $paymentLedger->id,
            'amount' => 500,
        ]);

        $this->assertDatabaseHas('supplier_payment_allocations', [
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $newerInvoice->id,
            'financial_ledger_id' => $paymentLedger->id,
            'amount' => 50,
        ]);
    }

    public function test_supplier_show_uses_invoice_level_outstanding_after_returns_and_historical_payments(): void
    {
        $admin = $this->makeAdminUser([
            'module.suppliers.view',
            'module.suppliers.create',
            'module.suppliers.update',
        ]);

        $supplier = $this->createSupplier();
        $invoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-SNAP-001',
            'invoice_date' => now()->subDays(20)->toDateString(),
            'due_days' => 5,
            'total_amount' => 1000,
        ]);

        PurchaseReturn::create([
            'return_number' => 'PRET-001',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->subDays(10)->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 200,
            'sgst_amount' => 0,
            'cgst_amount' => 0,
            'igst_amount' => 0,
            'total_amount' => 200,
            'status' => 'approved',
            'reason' => 'Damaged stock',
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
        ]);

        $ledgerService = app(LedgerService::class);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 1000, reference: $invoice, paymentMode: 'credit', narration: 'Approved purchase invoice', transactionDate: $invoice->invoice_date);
        $ledgerService->recordEntry($supplier, 'PURCHASE_RETURN', debit: 200, credit: 0, reference: null, paymentMode: 'adjustment', narration: 'Approved return', transactionDate: now()->subDays(10)->toDateString());
        $ledgerService->recordEntry($supplier, 'PAYMENT_MADE', debit: 300, credit: 0, reference: null, paymentMode: 'bank', narration: 'Historical payment', transactionDate: now()->subDays(8)->toDateString());

        $this->actingAs($admin)
            ->get(route('admin.suppliers.show', $supplier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/Suppliers/Show')
                ->where('summary.open_invoice_exposure', 500)
                ->where('summary.overdue_exposure', 500)
                ->where('summary.overdue_invoices', 1)
                ->where('recentInvoices.0.invoice_number', 'PINV-SNAP-001')
                ->where('recentInvoices.0.gross_amount', 1000)
                ->where('recentInvoices.0.return_adjusted_amount', 200)
                ->where('recentInvoices.0.paid_amount', 300)
                ->where('recentInvoices.0.outstanding_amount', 500)
                ->where('recentInvoices.0.is_overdue', true)
            );
    }

    public function test_supplier_payment_reversal_reopens_invoice_outstanding_and_writes_negative_allocations(): void
    {
        $admin = $this->makeAdminUser([
            'module.suppliers.view',
            'module.suppliers.create',
            'module.suppliers.update',
        ]);

        $supplier = $this->createSupplier();
        $invoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-REVERSAL-001',
            'invoice_date' => now()->subDays(7)->toDateString(),
            'due_days' => 3,
            'total_amount' => 500,
        ]);

        $ledgerService = app(LedgerService::class);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 500, reference: $invoice, paymentMode: 'credit', narration: 'Approved purchase invoice', transactionDate: $invoice->invoice_date);

        $this->actingAs($admin)
            ->post(route('admin.suppliers.payments.store', $supplier), [
                'amount' => 300,
                'payment_date' => now()->subDays(1)->toDateString(),
                'payment_mode' => 'bank',
                'narration' => 'Settlement to reverse',
            ])
            ->assertRedirect(route('admin.suppliers.show', $supplier));

        $paymentLedger = $supplier->financialLedgers()
            ->where('transaction_type', 'PAYMENT_MADE')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.suppliers.payments.reverse', [$supplier, $paymentLedger]), [
                'reason' => 'Bank entry posted twice',
                'reversal_date' => now()->toDateString(),
            ])
            ->assertRedirect(route('admin.suppliers.show', $supplier));

        $paymentLedger->refresh();

        $this->assertNotNull($paymentLedger->reversed_at);
        $this->assertSame($admin->id, $paymentLedger->reversed_by);
        $this->assertSame('Bank entry posted twice', $paymentLedger->reversal_reason);

        $reversalLedger = $supplier->financialLedgers()
            ->where('transaction_type', 'PAYMENT_REVERSAL')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(300.0, (float) $reversalLedger->credit);
        $this->assertSame($paymentLedger->id, $reversalLedger->reverses_financial_ledger_id);

        $this->assertDatabaseHas('supplier_payment_allocations', [
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'financial_ledger_id' => $reversalLedger->id,
            'amount' => -300,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suppliers.show', $supplier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/Suppliers/Show')
                ->where('summary.payments_made', 0)
                ->where('summary.open_invoice_exposure', 500)
                ->where('recentInvoices.0.invoice_number', 'PINV-REVERSAL-001')
                ->where('recentInvoices.0.paid_amount', 0)
                ->where('recentInvoices.0.outstanding_amount', 500)
            );
    }

    public function test_supplier_payment_reallocation_moves_settlement_between_invoices_without_reversing_payment(): void
    {
        $admin = $this->makeAdminUser([
            'module.suppliers.view',
            'module.suppliers.create',
            'module.suppliers.update',
        ]);

        $supplier = $this->createSupplier();
        $olderInvoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-REALLOC-OLD',
            'invoice_date' => now()->subDays(12)->toDateString(),
            'due_days' => 5,
            'total_amount' => 500,
        ]);
        $newerInvoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-REALLOC-NEW',
            'invoice_date' => now()->subDays(4)->toDateString(),
            'due_days' => 7,
            'total_amount' => 400,
        ]);

        $ledgerService = app(LedgerService::class);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 500, reference: $olderInvoice, paymentMode: 'credit', narration: 'Older supplier invoice', transactionDate: $olderInvoice->invoice_date);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 400, reference: $newerInvoice, paymentMode: 'credit', narration: 'Newer supplier invoice', transactionDate: $newerInvoice->invoice_date);

        $this->actingAs($admin)
            ->post(route('admin.suppliers.payments.store', $supplier), [
                'amount' => 300,
                'payment_date' => now()->subDay()->toDateString(),
                'payment_mode' => 'bank',
                'narration' => 'Needs allocation correction',
            ])
            ->assertRedirect(route('admin.suppliers.show', $supplier));

        $paymentLedger = $supplier->financialLedgers()
            ->where('transaction_type', 'PAYMENT_MADE')
            ->latest('id')
            ->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.suppliers.payments.reallocate', [$supplier, $paymentLedger]), [
                'reason' => 'Payment should settle the latest bill first',
                'reallocation_date' => now()->toDateString(),
                'allocations' => [
                    [
                        'purchase_invoice_id' => $newerInvoice->id,
                        'amount' => 300,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.suppliers.show', $supplier));

        $paymentLedger->refresh();

        $this->assertNull($paymentLedger->reversed_at);

        $this->assertDatabaseHas('supplier_payment_allocations', [
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $olderInvoice->id,
            'financial_ledger_id' => $paymentLedger->id,
            'amount' => -300,
        ]);

        $this->assertDatabaseHas('supplier_payment_allocations', [
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $newerInvoice->id,
            'financial_ledger_id' => $paymentLedger->id,
            'amount' => 300,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.suppliers.show', $supplier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/Suppliers/Show')
                ->where('summary.payments_made', 300)
                ->where('recentInvoices.0.invoice_number', 'PINV-REALLOC-OLD')
                ->where('recentInvoices.0.paid_amount', 0)
                ->where('recentInvoices.0.outstanding_amount', 500)
                ->where('recentInvoices.1.invoice_number', 'PINV-REALLOC-NEW')
                ->where('recentInvoices.1.paid_amount', 300)
                ->where('recentInvoices.1.outstanding_amount', 100)
            );
    }

    public function test_reversed_purchase_return_no_longer_reduces_supplier_invoice_exposure(): void
    {
        $admin = $this->makeAdminUser([
            'module.suppliers.view',
            'module.suppliers.create',
            'module.suppliers.update',
        ]);

        $supplier = $this->createSupplier();
        $invoice = $this->createApprovedInvoice($supplier, [
            'invoice_number' => 'PINV-REV-001',
            'invoice_date' => now()->subDays(10)->toDateString(),
            'due_days' => 2,
            'total_amount' => 900,
        ]);

        PurchaseReturn::create([
            'return_number' => 'PRET-REV-001',
            'supplier_id' => $supplier->id,
            'purchase_invoice_id' => $invoice->id,
            'return_date' => now()->subDays(6)->toDateString(),
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 250,
            'sgst_amount' => 0,
            'cgst_amount' => 0,
            'igst_amount' => 0,
            'total_amount' => 250,
            'status' => 'approved',
            'created_by' => $admin->id,
            'approved_by' => $admin->id,
            'reversed_by' => $admin->id,
            'reversed_at' => now()->subDays(2),
            'reversal_reason' => 'Supplier denied debit note',
        ]);

        $ledgerService = app(LedgerService::class);
        $ledgerService->recordEntry($supplier, 'PURCHASE', debit: 0, credit: 900, reference: $invoice, paymentMode: 'credit', narration: 'Approved purchase invoice', transactionDate: $invoice->invoice_date);

        $this->actingAs($admin)
            ->get(route('admin.suppliers.show', $supplier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Procurement/Suppliers/Show')
                ->where('summary.gross_returns', 0)
                ->where('summary.open_invoice_exposure', 900)
                ->where('recentInvoices.0.return_adjusted_amount', 0)
                ->where('recentInvoices.0.outstanding_amount', 900)
            );
    }

    private function makeAdminUser(array $permissions): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin']);

        foreach ($permissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $role->syncPermissions($permissions);

        $user = User::factory()->create([
            'username' => 'admin_' . Str::lower(Str::random(6)),
            'is_active' => true,
            'franchisee_id' => null,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function createSupplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'name' => 'Supplier ' . Str::upper(Str::random(4)),
            'code' => 'SUP-' . random_int(1000, 9999),
            'contact_person' => 'Accounts Desk',
            'phone' => '9876543210',
            'is_active' => true,
            'credit_days' => 7,
            'credit_limit' => 50000,
        ], $overrides));
    }

    private function createApprovedInvoice(Supplier $supplier, array $overrides = []): PurchaseInvoice
    {
        return PurchaseInvoice::create(array_merge([
            'invoice_number' => 'PINV-' . Str::upper(Str::random(6)),
            'supplier_invoice_no' => 'SUPINV-' . Str::upper(Str::random(4)),
            'supplier_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(),
            'due_days' => 0,
            'financial_year' => PurchaseInvoice::currentFinancialYear(),
            'subtotal' => 0,
            'discount_amount' => 0,
            'sgst_amount' => 0,
            'cgst_amount' => 0,
            'igst_amount' => 0,
            'round_off' => 0,
            'total_amount' => 0,
            'tax_type' => 'intra_state',
            'status' => 'approved',
        ], $overrides));
    }
}
