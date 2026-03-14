<?php

namespace App\Http\Controllers;

use App\Models\SalesInvoice;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SalesInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = SalesInvoice::with(['customer:id,name,mobile', 'user:id,name', 'items.product:id,product_name'])
            ->latest('date_time');

        // Scope: Franchisee/Staff see only their shop's bills
        if ($user->franchisee_id) {
            $query->where('franchisee_id', $user->franchisee_id);
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
            $query->where(function ($q) use ($request) {
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

        // Authorization: franchisee can only view their own bills
        if ($user->franchisee_id && $salesInvoice->franchisee_id !== $user->franchisee_id) {
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
    public function cancel(SalesInvoice $salesInvoice, Request $request)
    {
        $user = $request->user();

        if ($user->franchisee_id && $salesInvoice->franchisee_id !== $user->franchisee_id) {
            abort(403);
        }
        if ($salesInvoice->status === 'cancelled') {
            return back()->with('error', 'Bill is already cancelled.');
        }

        $salesInvoice->update(['status' => 'cancelled']);

        return back()->with('success', "Bill {$salesInvoice->bill_no} cancelled.");
    }

    /**
     * Export bills to a CSV file (Excel compatible).
     */
    public function export(Request $request)
    {
        $user = $request->user();

        $query = SalesInvoice::with(['customer:id,name', 'user:id,name'])
            ->latest('date_time');

        if ($user->franchisee_id) {
            $query->where('franchisee_id', $user->franchisee_id);
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

        $invoices = $query->get();

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
            'Total Amount', 'Discount', 'Tax', 'Paid Amount'
        ];

        $callback = function () use ($invoices, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($invoices as $invoice) {
                fputcsv($file, [
                    $invoice->bill_no,
                    $invoice->date_time->format('Y-m-d H:i'),
                    $invoice->customer->name ?? 'Walk-in',
                    $invoice->user->name ?? 'System',
                    ucfirst($invoice->status),
                    $invoice->total_amount,
                    $invoice->total_discount_amount,
                    $invoice->total_tax_amount,
                    $invoice->paid_amount,
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

        if ($user->franchisee_id && $salesInvoice->franchisee_id !== $user->franchisee_id) {
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
            ],
        ]);
    }
}
