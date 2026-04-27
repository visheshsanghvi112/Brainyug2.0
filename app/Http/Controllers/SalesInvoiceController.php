<?php

namespace App\Http\Controllers;

use App\Models\Franchisee;
use App\Models\PosOverrideAudit;
use App\Models\SalesReturn;
use App\Services\InventoryService;
use App\Services\LedgerService;
use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SalesInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $query = SalesInvoice::with(['customer:id,name,mobile', 'user:id,name', 'items.product:id,product_name'])
            ->latest('date_time');

        // Scope: Franchisee/Staff see only their shop's bills
        if ($franchiseeId) {
            $query->where('franchisee_id', $franchiseeId);
        } elseif ($user->isAdmin() || $user->isAccount()) {
            // Admin sees all — optionally filter by franchisee
            if ($request->filled('franchisee_id')) {
                $query->where('franchisee_id', $request->franchisee_id);
            }
        } else {
            abort(403);
        }

        // Filters
        if ($request->filled('search')) {
            $query->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($request) {
                $q->where('bill_no', 'like', "%{$request->search}%")
                  ->orWhereHas('customer', fn($q2) => $q2->where('name', 'like', "%{$request->search}%")
                      ->orWhere('mobile', 'like', "%{$request->search}%"));
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date_time', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date_time', '<=', $request->date_to);
        }

        $invoices = $query->paginate(25)->withQueryString();

        // Summary stats for the current filter window
        $totals = (clone $query->getQuery())->reorder()->selectRaw('
            COUNT(*) as bill_count,
            SUM(total_amount) as total_revenue,
            SUM(total_discount_amount) as total_discount,
            SUM(total_tax_amount) as total_tax
        ')->first();

        return Inertia::render('POS/Invoices/Index', [
            'invoices' => $invoices,
            'totals'   => $totals,
            'filters'  => $request->only(['search', 'status', 'date_from', 'date_to', 'franchisee_id']),
        ]);
    }

    public function show(SalesInvoice $salesInvoice, Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        // Authorization: franchisee can only view their own bills
        if ($franchiseeId && $salesInvoice->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        $salesInvoice->load([
            'customer',
            'doctor',
            'franchisee:id,shop_name,shop_code,address,gst_number,mobile',
            'user:id,name',
            'items.product:id,product_name,sku',
            'payments',
        ]);

        return Inertia::render('POS/Invoices/Show', [
            'invoice' => $salesInvoice,
        ]);
    }

    /**
     * Cancel a bill (admin or same-shop user only).
     * Inventory reversal is handled separately if needed.
     */
    public function cancel(SalesInvoice $salesInvoice, Request $request, InventoryService $inventoryService, LedgerService $ledgerService)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $validated = $request->validate([
            'override_request_id' => ['required', 'string', 'max:80'],
            'override_token' => ['required', 'string', 'max:120'],
            'override_reason' => ['required', 'string', 'max:160'],
            'override_snapshot' => ['required', 'array'],
            'override_snapshot.item_count' => ['required', 'integer', 'min:1'],
            'override_snapshot.max_line_discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'override_snapshot.bill_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'override_snapshot.total_amount' => ['required', 'numeric', 'min:0'],
        ]);

        $approval = $this->resolveCancelOverrideApproval(
            franchiseeId: (int) ($franchiseeId ?: $salesInvoice->franchisee_id),
            cashierUserId: (int) $user->id,
            token: (string) ($validated['override_token'] ?? ''),
            requestId: (string) ($validated['override_request_id'] ?? ''),
            action: 'cancel_invoice_override'
        );

        if (!$approval) {
            abort(422, 'Supervisor approval required to cancel this invoice.');
        }

        $approvedReason = trim((string) ($approval['reason'] ?? ''));
        $providedReason = trim((string) ($validated['override_reason'] ?? ''));
        if ($providedReason === '' || strcasecmp($providedReason, $approvedReason) !== 0) {
            abort(422, 'Supervisor reason mismatch. Please request approval again.');
        }

        $approvedSnapshot = $this->normalizeOverrideSnapshot($approval['approval_snapshot'] ?? null);
        $providedSnapshot = $this->normalizeOverrideSnapshot($validated['override_snapshot'] ?? null);
        if (!$approvedSnapshot || !$providedSnapshot || $approvedSnapshot !== $providedSnapshot) {
            abort(422, 'Supervisor approval no longer matches this cancellation payload. Please re-approve.');
        }

        $validated['override_cache_key'] = !empty($approval['__cache_key']) ? (string) $approval['__cache_key'] : null;
        $validated['override_audit_id'] = !empty($approval['audit_id']) ? (int) $approval['audit_id'] : null;

        DB::transaction(function () use ($salesInvoice, $franchiseeId, $inventoryService, $ledgerService, $validated) {
            $lockedInvoice = SalesInvoice::query()
                ->whereKey($salesInvoice->id)
                ->lockForUpdate()
                ->with(['items.product', 'payments'])
                ->firstOrFail();

            if ($franchiseeId && $lockedInvoice->franchisee_id !== $franchiseeId) {
                abort(403);
            }

            if ($lockedInvoice->status === 'cancelled') {
                abort(422, 'Bill is already cancelled.');
            }

            $hasReturns = SalesReturn::query()
                ->where('sales_invoice_id', $lockedInvoice->id)
                ->lockForUpdate()
                ->exists();

            if ($hasReturns) {
                abort(422, 'Invoice has linked return entries. Cancel is blocked; use return workflow only.');
            }

            $hasCreditCollections = DB::table('customer_credit_collections')
                ->where('sales_invoice_id', $lockedInvoice->id)
                ->lockForUpdate()
                ->exists();

            if ($hasCreditCollections) {
                abort(422, 'Invoice has credit collections posted. Cancel is blocked; reverse collections first.');
            }

            $approvedSnapshot = $this->normalizeOverrideSnapshot($validated['override_snapshot'] ?? null) ?? [];
            $derivedSnapshot = [
                'item_count' => (int) $lockedInvoice->items->count(),
                'max_line_discount' => 0.0,
                'bill_discount_percent' => 0.0,
                'total_amount' => round((float) $lockedInvoice->total_amount, 2),
            ];

            if (($approvedSnapshot['item_count'] ?? 0) !== $derivedSnapshot['item_count'] ||
                abs((float) ($approvedSnapshot['total_amount'] ?? 0) - (float) $derivedSnapshot['total_amount']) > 0.01) {
                abort(422, 'Supervisor approval no longer matches this invoice snapshot. Please re-approve.');
            }

            foreach ($lockedInvoice->items as $item) {
                $factor = (float) ($item->product?->conversion_factor ?? 1);
                if ($factor <= 0) {
                    $factor = 1.0;
                }

                $stockQty = round((((float) $item->qty + (float) ($item->free_qty ?? 0)) / $factor), 4);
                if ($stockQty <= 0) {
                    continue;
                }

                $inventoryService->recordSaleReturn([
                    'product_id' => (int) $item->product_id,
                    'batch_no' => (string) $item->batch_no,
                    'expiry_date' => $item->exp_date,
                    'mrp' => $item->mrp,
                    'franchisee_id' => (int) $lockedInvoice->franchisee_id,
                    'qty' => $stockQty,
                    'rate' => $item->rate,
                    'reference_id' => $lockedInvoice->id,
                    'created_by' => auth()->id(),
                ]);
            }

            DB::table('sale_payments')
                ->where('sales_invoice_id', $lockedInvoice->id)
                ->update([
                    'cash_amount' => 0,
                    'bank_amount' => 0,
                    'credit_amount' => 0,
                    'updated_at' => now(),
                ]);

            $franchisee = Franchisee::query()->findOrFail((int) $lockedInvoice->franchisee_id);
            $ledgerService->recordEntry(
                ledgerable: $franchisee,
                transactionType: 'POS_CANCEL',
                debit: round((float) $lockedInvoice->total_amount, 2),
                credit: 0,
                reference: $lockedInvoice,
                paymentMode: (string) ($lockedInvoice->payments->first()?->payment_mode ?? 'cancel'),
                narration: "POS invoice cancellation [{$lockedInvoice->bill_no}]",
                transactionDate: now()->toDateString(),
            );

            $lockedInvoice->update(['status' => 'cancelled']);

            if (!empty($validated['override_audit_id'])) {
                PosOverrideAudit::query()
                    ->where('id', (int) $validated['override_audit_id'])
                    ->where('status', 'approved')
                    ->update([
                        'sales_invoice_id' => $lockedInvoice->id,
                        'checkout_snapshot' => $validated['override_snapshot'] ?? null,
                        'status' => 'consumed',
                        'consumed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if (!empty($validated['override_cache_key'])) {
                Cache::forget((string) $validated['override_cache_key']);
            }
        });

        return back()->with('success', "Bill {$salesInvoice->bill_no} cancelled with stock and ledger reversal.");
    }

    /**
     * Export bills to a CSV file (Excel compatible).
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        $query = SalesInvoice::with(['customer:id,name', 'user:id,name'])
            ->latest('date_time');

        if ($franchiseeId) {
            $query->where('franchisee_id', $franchiseeId);
        } elseif ($user->isAdmin() || $user->isAccount()) {
            if ($request->filled('franchisee_id')) {
                $query->where('franchisee_id', $request->franchisee_id);
            }
        } else {
            abort(403);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date_time', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date_time', '<=', $request->date_to);
        }

        $invoices = $query->with('payments:id,sales_invoice_id,cash_amount,bank_amount')->get();

        $filename = "sales_invoices_" . date('Y-m-d_H-i-s') . ".csv";

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$filename",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = [
            'Bill No', 'Date', 'Customer', 'Cashier', 'Status', 
            'Total Amount', 'Discount', 'Tax', 'Round Off', 'Paid Amount'
        ];

        $callback = function () use ($invoices, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($invoices as $invoice) {
                fputcsv($file, [
                    $invoice->bill_no,
                    $invoice->date_time->format('Y-m-d H:i'),
                    $invoice->customer?->name ?? 'Walk-in',
                    $invoice->user?->name ?? 'System',
                    ucfirst($invoice->status),
                    $invoice->total_amount,
                    $invoice->total_discount_amount,
                    $invoice->total_tax_amount,
                    round((float) ($invoice->round_off ?? 0), 2),
                    $invoice->payments->sum(function ($payment) {
                        return (float) $payment->cash_amount + (float) $payment->bank_amount;
                    }),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Print View for Thermal or A4 Receipt.
     * Accessible only to the invoice owner or admin.
     */
    public function print(SalesInvoice $salesInvoice, Request $request)
    {
        $user = $request->user();
        $franchiseeId = $this->resolveFranchiseeId($user);

        if ($franchiseeId && $salesInvoice->franchisee_id !== $franchiseeId) {
            abort(403);
        }

        $salesInvoice->load([
            'customer',
            'franchisee',
            'user:id,name',
            'items.product',
        ]);

        return Inertia::render('POS/Invoices/Print', [
            'invoice' => $salesInvoice,
            'printPreferences' => [
                'receipt_layout' => data_get($user->preferences, 'receipt_layout', 'thermal'),
                'auto_print_after_checkout' => (bool) data_get($user->preferences, 'auto_print_after_checkout', true),
                'printer_type' => data_get($user->preferences, 'printer_type', 'thermal'),
                'printer_connection' => data_get($user->preferences, 'printer_connection', 'system_spooler'),
                'printer_paper_width' => data_get($user->preferences, 'printer_paper_width', '80mm'),
                'printer_ip' => data_get($user->preferences, 'printer_ip'),
                'printer_port' => (int) data_get($user->preferences, 'printer_port', 9100),
                'printer_name' => data_get($user->preferences, 'printer_name'),
                'printer_driver' => data_get($user->preferences, 'printer_driver', 'browser_native'),
                'print_copies' => (int) data_get($user->preferences, 'print_copies', 1),
                'auto_cut_receipt' => (bool) data_get($user->preferences, 'auto_cut_receipt', true),
                'open_cash_drawer' => (bool) data_get($user->preferences, 'open_cash_drawer', false),
                'epos_timeout_ms' => (int) data_get($user->preferences, 'epos_timeout_ms', 5000),
                'bill_logo_url' => data_get($user->preferences, 'bill_logo_url'),
                'bill_header_line_1' => data_get($user->preferences, 'bill_header_line_1'),
                'bill_header_line_2' => data_get($user->preferences, 'bill_header_line_2'),
            ],
        ]);
    }

    private function resolveFranchiseeId($user): ?int
    {
        $franchiseeId = method_exists($user, 'getEffectiveFranchiseeId')
            ? $user->getEffectiveFranchiseeId()
            : ($user->franchisee_id ?? null);

        return $franchiseeId ? (int) $franchiseeId : null;
    }

    private function resolveCancelOverrideApproval(int $franchiseeId, int $cashierUserId, string $token, string $requestId, string $action): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $key = $this->overrideApprovalCacheKey($franchiseeId, $cashierUserId, $token);
        $payload = Cache::get($key);
        if (!is_array($payload)) {
            return null;
        }

        if (($payload['action'] ?? null) !== $action || ($payload['request_id'] ?? null) !== $requestId) {
            return null;
        }

        if ((int) ($payload['cashier_user_id'] ?? 0) !== $cashierUserId) {
            return null;
        }

        $payload['__cache_key'] = $key;

        return $payload;
    }

    private function overrideApprovalCacheKey(int $franchiseeId, int $cashierUserId, string $token): string
    {
        return 'pos_override:' . $franchiseeId . ':' . $cashierUserId . ':' . hash('sha256', $token);
    }

    private function normalizeOverrideSnapshot($snapshot): ?array
    {
        if (!is_array($snapshot)) {
            return null;
        }

        return [
            'item_count' => (int) ($snapshot['item_count'] ?? 0),
            'max_line_discount' => round((float) ($snapshot['max_line_discount'] ?? 0), 2),
            'bill_discount_percent' => round((float) ($snapshot['bill_discount_percent'] ?? 0), 2),
            'total_amount' => round((float) ($snapshot['total_amount'] ?? 0), 2),
        ];
    }
}
