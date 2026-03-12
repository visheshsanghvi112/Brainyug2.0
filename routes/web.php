<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialLedgerController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\ShopVisitController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\Admin\DistOrderController;
use App\Http\Controllers\Admin\FranchiseeController;
use App\Http\Controllers\Admin\HsnMasterController;
use App\Http\Controllers\Admin\MasterCatalogController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseInvoiceController;
use App\Http\Controllers\Admin\PurchaseReturnController;
use App\Http\Controllers\Admin\SaltMasterController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\B2b\CartController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::get('/', static function () {
    return Inertia::render('Welcome', [
        'canLogin'       => Route::has('login'),
        'canRegister'    => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion'     => PHP_VERSION,
    ]);
})->name('home');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', '2fa'])->group(function () {

    Route::get('/2fa/verify', [\App\Http\Controllers\Auth\TwoFactorAuthController::class, 'index'])->name('2fa.index');
    Route::post('/2fa/verify', [\App\Http\Controllers\Auth\TwoFactorAuthController::class, 'verify'])->name('2fa.verify');

    // ── Profile ────────────────────────────────────────────────────────────
    Route::prefix('profile')->name('profile.')->controller(ProfileController::class)->group(function () {
        Route::get('/',    'edit')->name('edit');
        Route::patch('/',  'update')->name('update');
        Route::patch('/preferences', 'updatePreferences')->name('update-preferences');
        Route::delete('/', 'destroy')->name('destroy');

        // 2FA Routes
        Route::post('/2fa/request', 'requestTwoFactorAuth')->name('2fa.request');
        Route::post('/2fa/confirm', 'confirmTwoFactorAuth')->name('2fa.confirm');
        Route::post('/2fa/disable', 'disableTwoFactorAuth')->name('2fa.disable');
    });

    /*
    |--------------------------------------------------------------------------
    | Admin / HO Back-office
    | Every sub-group adds its own role middleware so no route is left
    | wide-open to plain Franchisee / Payment Manager accounts by accident.
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->name('admin.')->group(function () {

        // ── User Management  (Super Admin only) ────────────────────────────
        Route::middleware('role:Super Admin')
            ->resource('users', UserController::class)
            ->whereNumber('user');

        // ── Product Master  (Super Admin + territory heads + Sales Staff) ──
        Route::middleware('role:Super Admin|State Head|Zone Head|District Head|Sister Head|Sales Staff')
            ->group(function () {
                // Export routes (must be registered before resource to avoid {product} match)
                Route::get('products/export/excel', [ProductController::class, 'exportExcel'])->name('products.export.excel');
                Route::get('products/export/pdf',   [ProductController::class, 'exportPdf'])->name('products.export.pdf');

                Route::resource('products', ProductController::class)->whereNumber('product');

                // AJAX helpers used by CreateEdit form, POS, and Purchase Invoice
                Route::get('products/ajax/hsn-tax',       [ProductController::class, 'hsnTax'])->name('products.hsnTax');
                Route::get('products/ajax/rack-areas',    [ProductController::class, 'rackAreas'])->name('products.rackAreas');
                Route::get('products/ajax/check-name',    [ProductController::class, 'checkProductName'])->name('products.checkName');
                Route::get('products/ajax/search',        [ProductController::class, 'search'])->name('products.search');

                Route::resource('hsn-masters', HsnMasterController::class)
                    ->except(['create', 'show', 'edit'])
                    ->whereNumber('hsn_master');
                Route::resource('salt-masters', SaltMasterController::class)
                    ->except(['create', 'show', 'edit'])
                    ->whereNumber('salt_master');
            });

        // ── Item Categories & Company Masters  (Super Admin only) ──────────
        Route::middleware('role:Super Admin')->group(function () {
            Route::get(   'categories',                [MasterCatalogController::class, 'categoriesIndex'])->name('categories.index');
            Route::post(  'categories',                [MasterCatalogController::class, 'categoriesStore'])->name('categories.store');
            Route::put(   'categories/{itemCategory}', [MasterCatalogController::class, 'categoriesUpdate'])->name('categories.update');
            Route::delete('categories/{itemCategory}', [MasterCatalogController::class, 'categoriesDestroy'])->name('categories.destroy');

            Route::get(   'companies',                 [MasterCatalogController::class, 'companiesIndex'])->name('companies.index');
            Route::post(  'companies',                 [MasterCatalogController::class, 'companiesStore'])->name('companies.store');
            Route::put(   'companies/{companyMaster}', [MasterCatalogController::class, 'companiesUpdate'])->name('companies.update');
            Route::delete('companies/{companyMaster}', [MasterCatalogController::class, 'companiesDestroy'])->name('companies.destroy');
        });

        // ── Franchise Network  (Super Admin + territory heads) ─────────────
        Route::middleware('role:Super Admin|State Head|Zone Head|District Head|Sister Head')
            ->group(function () {
                Route::resource('franchisees', FranchiseeController::class)->whereNumber('franchisee');
                Route::post('franchisees/{franchisee}/approve',  [FranchiseeController::class, 'approve'])->name('franchisees.approve');
                Route::post('franchisees/{franchisee}/reject',   [FranchiseeController::class, 'reject'])->name('franchisees.reject');
                Route::post('franchisees/{franchisee}/activate', [FranchiseeController::class, 'activate'])->name('franchisees.activate');
                Route::post('franchisees/{franchisee}/suspend',  [FranchiseeController::class, 'suspend'])->name('franchisees.suspend');
            });

        // ── Procurement & Inventory  (Super Admin + Distributor) ───────────
        Route::middleware('role:Super Admin|Distributor')->group(function () {
            Route::resource('suppliers', SupplierController::class)->whereNumber('supplier');

            Route::get('purchase-invoices/export', [PurchaseInvoiceController::class, 'export'])->name('purchase-invoices.export');
            Route::resource('purchase-invoices', PurchaseInvoiceController::class)->whereNumber('purchase_invoice');
            Route::post('purchase-invoices/{purchase_invoice}/approve', [PurchaseInvoiceController::class, 'approve'])->name('purchase-invoices.approve');
            Route::post('purchase-invoices/{purchase_invoice}/cancel',  [PurchaseInvoiceController::class, 'cancel'])->name('purchase-invoices.cancel');
            Route::get('purchase-invoices/{purchase_invoice}/print', [PurchaseInvoiceController::class, 'print'])->name('purchase-invoices.print');

            Route::get('purchase-returns/export', [PurchaseReturnController::class, 'export'])->name('purchase-returns.export');
            Route::resource('purchase-returns', PurchaseReturnController::class)->whereNumber('purchase_return');
            Route::post('purchase-returns/{purchase_return}/approve', [PurchaseReturnController::class, 'approve'])->name('purchase-returns.approve');
            Route::post('purchase-returns/{purchase_return}/cancel',  [PurchaseReturnController::class, 'cancel'])->name('purchase-returns.cancel');
            Route::get('purchase-returns/{purchase_return}/print', [PurchaseReturnController::class, 'print'])->name('purchase-returns.print');

            // Manual stock correction with full audit trail via InventoryService
            Route::get( 'stock/adjust', [StockAdjustmentController::class, 'index'])->name('stock.adjust');
            Route::post('stock/adjust', [StockAdjustmentController::class, 'store'])->name('stock.adjust.store');
        });

        // ── B2B Distribution Orders ─────────────────────────────────────────
        // READ: scoped inside the controller (franchisee sees own, HO sees all)
        // WRITE: only dispatch-capable roles
        Route::resource('dist-orders', DistOrderController::class)
            ->only(['index', 'show'])
            ->whereNumber('dist_order');

        Route::middleware('role:Super Admin|State Head|Zone Head|District Head|Distributor')
            ->group(function () {
                Route::post('dist-orders/{dist_order}/accept',   [DistOrderController::class, 'accept'])->name('dist-orders.accept');
                Route::post('dist-orders/{dist_order}/dispatch', [DistOrderController::class, 'dispatchOrder'])->name('dist-orders.dispatch');
                Route::post('dist-orders/{dist_order}/reject',   [DistOrderController::class, 'reject'])->name('dist-orders.reject');
            });
    });

    /*
    |--------------------------------------------------------------------------
    | B2B Portal — Franchisee places bulk orders from HO
    |--------------------------------------------------------------------------
    */
    Route::prefix('b2b')->name('b2b.')
        ->middleware('role:Franchisee|Super Admin')
        ->group(function () {
            Route::get(   '/cart',          [CartController::class, 'index'])->name('cart.index');
            Route::post(  '/cart/add',      [CartController::class, 'addToCart'])->name('cart.add');
            Route::delete('/cart/{item}',   [CartController::class, 'remove'])->name('cart.remove')->whereNumber('item');
            Route::post(  '/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');
        });

    /*
    |--------------------------------------------------------------------------
    | Retail POS — Franchisee daily billing
    |--------------------------------------------------------------------------
    */
    Route::prefix('pos')->name('pos.')
        ->middleware('role:Franchisee|Franchisee Staff|Super Admin')
        ->group(function () {
            Route::get( '/',         [POSController::class, 'index'])->name('index');
            Route::post('/checkout', [POSController::class, 'checkout'])->name('checkout');

            // AJAX helpers
            Route::post('/search-product',    [POSController::class, 'searchProduct'])->name('searchProduct');
            Route::post('/batches',           [POSController::class, 'getProductBatches'])->name('getProductBatches');
            Route::post('/check-stock',       [POSController::class, 'checkStock'])->name('checkStock');
            Route::post('/customer-lookup',   [POSController::class, 'lookupCustomer'])->name('lookupCustomer');
            Route::post('/customers/search',  [POSController::class, 'searchCustomers'])->name('searchCustomers');
            Route::post('/customers/store',   [POSController::class, 'storeCustomer'])->name('storeCustomer');
            Route::post('/doctors/search',    [POSController::class, 'searchDoctors'])->name('searchDoctors');
            Route::post('/doctors/store',     [POSController::class, 'storeDoctor'])->name('storeDoctor');
            Route::get( '/bill-number',       [POSController::class, 'nextBillNumber'])->name('nextBillNumber');
            Route::post('/credit-info',       [POSController::class, 'customerCreditInfo'])->name('customerCreditInfo');
            Route::post('/return',            [POSController::class, 'processReturn'])->name('processReturn');

            // Invoice browser — list, detail, print, cancel
            Route::get( '/invoices',                       [SalesInvoiceController::class, 'index'])->name('invoices.index');
            Route::get( '/invoices/export',                [SalesInvoiceController::class, 'export'])->name('invoices.export');
            Route::get( '/invoices/{salesInvoice}',        [SalesInvoiceController::class, 'show'])->name('invoices.show')->whereNumber('salesInvoice');
            Route::post('/invoices/{salesInvoice}/cancel', [SalesInvoiceController::class, 'cancel'])->name('invoices.cancel')->whereNumber('salesInvoice');
            Route::get( '/invoices/{salesInvoice}/print',  [SalesInvoiceController::class, 'print'])->name('invoices.print')->whereNumber('salesInvoice');

            // Sales returns
            Route::get( '/returns',        [SalesReturnController::class, 'index'])->name('returns.index');
            Route::get( '/returns/create', [SalesReturnController::class, 'create'])->name('returns.create');
            Route::post('/returns',        [SalesReturnController::class, 'store'])->name('returns.store');
        });

    /*
    |--------------------------------------------------------------------------
    | Customer Directory  (Franchisee + Super Admin)
    |--------------------------------------------------------------------------
    */
    Route::resource('customers', CustomerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middleware('role:Franchisee|Franchisee Staff|Super Admin')
        ->whereNumber('customer');

    /*
    |--------------------------------------------------------------------------
    | Expenses  (Franchisee + Super Admin + Payment Manager)
    |--------------------------------------------------------------------------
    */
    Route::resource('expenses', ExpenseController::class)
        ->only(['index', 'create', 'store'])
        ->middleware('role:Franchisee|Super Admin|Payment Manager')
        ->whereNumber('expense');

    /*
    |--------------------------------------------------------------------------
    | Help Desk — Support Tickets
    | All authenticated users can raise & view tickets.
    | Status changes restricted to HO / territory heads.
    |--------------------------------------------------------------------------
    */
    Route::resource('tickets', SupportTicketController::class)
        ->only(['index', 'create', 'store', 'show'])
        ->whereNumber('ticket');

    Route::post('tickets/{ticket}/reply', [SupportTicketController::class, 'reply'])
        ->name('tickets.reply')
        ->whereNumber('ticket');

    Route::patch('tickets/{ticket}/status', [SupportTicketController::class, 'updateStatus'])
        ->name('tickets.update-status')
        ->middleware('role:Super Admin|State Head|Zone Head|District Head|Sister Head')
        ->whereNumber('ticket');

    /*
    |--------------------------------------------------------------------------
    | Meetings
    | All authenticated users can view meetings and RSVP.
    | Only territory heads / Super Admin can schedule or change the status.
    |--------------------------------------------------------------------------
    */
    Route::resource('meetings', MeetingController::class)
        ->only(['index', 'show'])
        ->whereNumber('meeting');

    Route::middleware('role:Super Admin|State Head|Zone Head|District Head|Sister Head')
        ->group(function () {
            Route::get( 'meetings/create',          [MeetingController::class, 'create'])->name('meetings.create');
            Route::post('meetings',                 [MeetingController::class, 'store'])->name('meetings.store');
            Route::patch('meetings/{meeting}/status', [MeetingController::class, 'updateStatus'])
                ->name('meetings.update-status')
                ->whereNumber('meeting');
        });

    Route::post('meetings/{meeting}/rsvp', [MeetingController::class, 'rsvp'])
        ->name('meetings.rsvp')
        ->whereNumber('meeting');

    /*
    |--------------------------------------------------------------------------
    | Shop Visit Audits
    | Territory heads create audits; franchisees read their own.
    | Controller enforces the ownership/scope internally.
    |--------------------------------------------------------------------------
    */
    Route::resource('shop-visits', ShopVisitController::class)
        ->only(['index', 'show'])
        ->whereNumber('shop_visit');

    Route::middleware('role:Super Admin|State Head|Zone Head|District Head|Sister Head')
        ->group(function () {
            Route::get( 'shop-visits/create', [ShopVisitController::class, 'create'])->name('shop-visits.create');
            Route::post('shop-visits',        [ShopVisitController::class, 'store'])->name('shop-visits.store');
        });

    /*
    |--------------------------------------------------------------------------
    | Financial Ledger
    |--------------------------------------------------------------------------
    */
    Route::get('/ledger', [FinancialLedgerController::class, 'index'])->name('ledger.index');

    /*
    |--------------------------------------------------------------------------
    | Reports & GST Compliance
    |--------------------------------------------------------------------------
    */
    Route::prefix('reports')->name('reports.')->group(function () {

        // Stock — HO, territory heads, franchisees, distributors
        Route::get('/stock/summary',    [ReportController::class, 'stockSummary'])->name('stock.summary');
        Route::get('/stock/current',    [ReportController::class, 'stockCurrent'])->name('stock.current');
        Route::get('/stock/expiry',     [ReportController::class, 'stockExpiry'])->name('stock.expiry');
        Route::get('/stock/non-moving', [ReportController::class, 'stockNonMoving'])->name('stock.non-moving');

        // GST compliance
        Route::get('/gst/gstr1', [ReportController::class, 'gstr1'])->name('gst.gstr1');
        Route::get('/gst/gstr2', [ReportController::class, 'gstr2'])->name('gst.gstr2');

        // MIS / Business Intelligence
        Route::get('/bi/top-products', [ReportController::class, 'topProducts'])->name('bi.top-products');

        // Commissions (controller scopes to current user unless Super Admin)
        Route::get('/commissions', [ReportController::class, 'commissions'])->name('commissions');
    });
});

require __DIR__.'/auth.php';
