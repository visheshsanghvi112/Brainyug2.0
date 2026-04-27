<?php

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinancialLedgerController;
use App\Http\Controllers\FranchiseApplicationController;
use App\Http\Controllers\FranchiseStaffController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesInvoiceController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\ShopVisitController;
use App\Http\Controllers\SupportTicketController;
use App\Http\Controllers\Admin\DistOrderController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\FranchiseeController;
use App\Http\Controllers\Admin\FranchiseePurchaseController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\SupportAccessAuditController;
use App\Http\Controllers\Auth\ForcedPasswordResetController;
use App\Http\Controllers\Admin\FranchiseProvisioningController;
use App\Http\Controllers\Admin\FranchiseRegistrationController;
use App\Http\Controllers\Admin\HsnMasterController;
use App\Http\Controllers\Admin\MasterCatalogController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\PurchaseInvoiceController;
use App\Http\Controllers\Admin\PurchaseOrderController;
use App\Http\Controllers\Admin\PurchaseReturnController;
use App\Http\Controllers\Admin\SaltMasterController;
use App\Http\Controllers\Admin\StockAlertController;
use App\Http\Controllers\Admin\StockAdjustmentController;
use App\Http\Controllers\Admin\SupplierController;
use App\Http\Controllers\Admin\UserAccessAuditController;
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

Route::get('/franchise/apply', [FranchiseApplicationController::class, 'create'])->name('franchise.apply');
Route::post('/franchise/apply', [FranchiseApplicationController::class, 'store'])->name('franchise.apply.store');

Route::get('/dashboard', DashboardController::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', '2fa', 'force.password.reset', 'erp.module'])->group(function () {

    Route::get('/2fa/verify', [\App\Http\Controllers\Auth\TwoFactorAuthController::class, 'index'])->name('2fa.index');
    Route::post('/2fa/verify', [\App\Http\Controllers\Auth\TwoFactorAuthController::class, 'verify'])->name('2fa.verify');

    Route::get('/password/force-reset', [ForcedPasswordResetController::class, 'edit'])->name('password.force.edit');
    Route::put('/password/force-reset', [ForcedPasswordResetController::class, 'update'])->name('password.force.update');

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

    // ── Support Access Session Control (Super Admin/Admin controlled) ─────
    Route::post('impersonation/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonation.stop');

    Route::post('support-access/stop', [ImpersonationController::class, 'stop'])
        ->name('support-access.stop');

    /*
    |--------------------------------------------------------------------------
    | Admin / HO Back-office
    | Every sub-group adds its own role middleware so no route is left
    | wide-open to plain Franchisee / Payment Manager accounts by accident.
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->name('admin.')->group(function () {

        // ── User Management  (Super Admin only) ────────────────────────────
        Route::middleware('erp.role:Super Admin|Admin')
            ->resource('users', UserController::class)
            ->whereNumber('user');

        Route::middleware('erp.role:Super Admin')->group(function () {
            Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])
                ->name('users.impersonate')
                ->whereNumber('user');

            Route::post('users/{user}/support-access', [ImpersonationController::class, 'start'])
                ->name('users.support-access')
                ->whereNumber('user');

            Route::get('support-access/audits', [SupportAccessAuditController::class, 'index'])
                ->name('support-access.audits');
        });

        Route::middleware('erp.role:Super Admin|Admin')->group(function () {
            Route::get('user-access/audits', [UserAccessAuditController::class, 'index'])
                ->name('user-access.audits');
        });

        // ── Product Master  (platform + heads + sales team) ──
        Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head|Sales Team')
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
        Route::middleware('erp.role:Super Admin|Admin')->group(function () {
            Route::get(   'categories',                [MasterCatalogController::class, 'categoriesIndex'])->name('categories.index');
            Route::post(  'categories',                [MasterCatalogController::class, 'categoriesStore'])->name('categories.store');
            Route::put(   'categories/{itemCategory}', [MasterCatalogController::class, 'categoriesUpdate'])->name('categories.update');
            Route::delete('categories/{itemCategory}', [MasterCatalogController::class, 'categoriesDestroy'])->name('categories.destroy');

            Route::get(   'companies',                 [MasterCatalogController::class, 'companiesIndex'])->name('companies.index');
            Route::post(  'companies',                 [MasterCatalogController::class, 'companiesStore'])->name('companies.store');
            Route::put(   'companies/{companyMaster}', [MasterCatalogController::class, 'companiesUpdate'])->name('companies.update');
            Route::delete('companies/{companyMaster}', [MasterCatalogController::class, 'companiesDestroy'])->name('companies.destroy');

            Route::get(   'rack-layout',                               [MasterCatalogController::class, 'rackLayoutIndex'])->name('rack-layout.index');
            Route::post(  'rack-layout/sections',                      [MasterCatalogController::class, 'rackSectionStore'])->name('rack-layout.sections.store');
            Route::put(   'rack-layout/sections/{rackSection}',        [MasterCatalogController::class, 'rackSectionUpdate'])->name('rack-layout.sections.update');
            Route::delete('rack-layout/sections/{rackSection}',        [MasterCatalogController::class, 'rackSectionDestroy'])->name('rack-layout.sections.destroy');
            Route::post(  'rack-layout/areas',                         [MasterCatalogController::class, 'rackAreaStore'])->name('rack-layout.areas.store');
            Route::put(   'rack-layout/areas/{rackArea}',              [MasterCatalogController::class, 'rackAreaUpdate'])->name('rack-layout.areas.update');
            Route::delete('rack-layout/areas/{rackArea}',              [MasterCatalogController::class, 'rackAreaDestroy'])->name('rack-layout.areas.destroy');
        });

        // ── Franchise Registration Review  (platform + heads) ─────────────
        Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head')
            ->group(function () {
                Route::get('franchise-registrations', [FranchiseRegistrationController::class, 'index'])->name('franchise-registrations.index');
                Route::get('franchise-registrations/{franchisee}', [FranchiseRegistrationController::class, 'show'])->name('franchise-registrations.show')->whereNumber('franchisee');
                Route::post('franchise-registrations/{franchisee}/approve', [FranchiseRegistrationController::class, 'approve'])->name('franchise-registrations.approve')->whereNumber('franchisee');
                Route::post('franchise-registrations/{franchisee}/reject', [FranchiseRegistrationController::class, 'reject'])->name('franchise-registrations.reject')->whereNumber('franchisee');
            });

        // ── Franchise Network  (live franchise management) ────────────────
        Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head')
            ->group(function () {
                Route::resource('franchisees', FranchiseeController::class)->whereNumber('franchisee');
            });

        Route::middleware('erp.role:Super Admin|Admin')
            ->group(function () {
                Route::post('franchises/{franchisee}/activate', [FranchiseeController::class, 'activate'])->name('franchises.activate')->whereNumber('franchisee');
                Route::post('franchises/{franchisee}/suspend', [FranchiseeController::class, 'suspend'])->name('franchises.suspend')->whereNumber('franchisee');
                Route::post('franchises/{franchisee}/provision-owner', [FranchiseProvisioningController::class, 'store'])->name('franchises.provision-owner')->whereNumber('franchisee');
            });

        // ── Procurement & Inventory  (platform + distributer) ──────────────
        Route::middleware('erp.role:Super Admin|Admin|Distributer')->group(function () {
            Route::resource('suppliers', SupplierController::class)->whereNumber('supplier');
            Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'recordPayment'])->name('suppliers.payments.store')->whereNumber('supplier');
            Route::post('suppliers/{supplier}/payments/{financial_ledger}/reallocate', [SupplierController::class, 'reallocatePayment'])->name('suppliers.payments.reallocate')->whereNumber('supplier')->whereNumber('financial_ledger');
            Route::post('suppliers/{supplier}/payments/{financial_ledger}/reverse', [SupplierController::class, 'reversePayment'])->name('suppliers.payments.reverse')->whereNumber('supplier')->whereNumber('financial_ledger');

            // Purchase Orders (precursor to purchase invoices)
            Route::get('purchase-orders/export', [PurchaseOrderController::class, 'export'])->name('purchase-orders.export');
            Route::resource('purchase-orders', PurchaseOrderController::class)
                ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
                ->whereNumber('purchase_order');
            Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
            Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
            Route::post('purchase-orders/{purchase_order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
            Route::post('purchase-orders/{purchase_order}/convert-to-invoice', [PurchaseOrderController::class, 'convertToInvoice'])->name('purchase-orders.convert-to-invoice');
            Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

            Route::get('purchase-invoices/export', [PurchaseInvoiceController::class, 'export'])->name('purchase-invoices.export');
            Route::get('purchase-invoices/import-template', [PurchaseInvoiceController::class, 'importTemplate'])->name('purchase-invoices.import.template');
            Route::post('purchase-invoices/import', [PurchaseInvoiceController::class, 'importCsv'])->name('purchase-invoices.import.store');
            Route::resource('purchase-invoices', PurchaseInvoiceController::class)
                ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
                ->whereNumber('purchase_invoice');
            Route::post('purchase-invoices/{purchase_invoice}/approve', [PurchaseInvoiceController::class, 'approve'])->name('purchase-invoices.approve');
            Route::post('purchase-invoices/{purchase_invoice}/cancel',  [PurchaseInvoiceController::class, 'cancel'])->name('purchase-invoices.cancel');
            Route::get('purchase-invoices/{purchase_invoice}/print', [PurchaseInvoiceController::class, 'print'])->name('purchase-invoices.print');

            Route::get('purchase-returns/export', [PurchaseReturnController::class, 'export'])->name('purchase-returns.export');
            Route::resource('purchase-returns', PurchaseReturnController::class)
                ->only(['index', 'create', 'store', 'show'])
                ->whereNumber('purchase_return');
            Route::post('purchase-returns/{purchase_return}/approve', [PurchaseReturnController::class, 'approve'])->name('purchase-returns.approve');
            Route::post('purchase-returns/{purchase_return}/reverse', [PurchaseReturnController::class, 'reverse'])->name('purchase-returns.reverse');
            Route::post('purchase-returns/{purchase_return}/cancel',  [PurchaseReturnController::class, 'cancel'])->name('purchase-returns.cancel');
            Route::get('purchase-returns/{purchase_return}/print', [PurchaseReturnController::class, 'print'])->name('purchase-returns.print');

            // Manual stock correction with full audit trail via InventoryService
            Route::get( 'stock/adjust', [StockAdjustmentController::class, 'index'])->name('stock.adjust');
            Route::post('stock/adjust', [StockAdjustmentController::class, 'store'])->name('stock.adjust.store');

            // Stock alerts operations dashboard (pending/acknowledge/resolve)
            Route::get('stock-alerts', [StockAlertController::class, 'index'])->name('stock-alerts.index');
            Route::post('stock-alerts/{stock_alert}/acknowledge', [StockAlertController::class, 'acknowledge'])->name('stock-alerts.acknowledge')->whereNumber('stock_alert');

        });

        // ── Email Template Management  (platform admin only) ──────────────
        Route::middleware('erp.role:Super Admin|Admin')->group(function () {
            Route::resource('email-templates', EmailTemplateController::class)
                ->only(['index', 'create', 'store', 'edit', 'update', 'destroy'])
                ->whereNumber('email_template');
        });

        // Franchisee outside purchases (external vendor procurement)
        Route::middleware('erp.role:Super Admin|Admin|Distributer|Franchisee')->group(function () {
            Route::resource('franchisee-purchases', FranchiseePurchaseController::class)
                ->only(['index', 'create', 'store', 'show', 'edit', 'update'])
                ->whereNumber('franchisee_purchase');
            Route::post('franchisee-purchases/{franchisee_purchase}/approve', [FranchiseePurchaseController::class, 'approve'])
                ->name('franchisee-purchases.approve')
                ->whereNumber('franchisee_purchase');
            Route::post('franchisee-purchases/{franchisee_purchase}/reject', [FranchiseePurchaseController::class, 'reject'])
                ->name('franchisee-purchases.reject')
                ->whereNumber('franchisee_purchase');
            Route::post('franchisee-purchases/{franchisee_purchase}/cancel', [FranchiseePurchaseController::class, 'cancel'])
                ->name('franchisee-purchases.cancel')
                ->whereNumber('franchisee_purchase');
        });

        // ── B2B Distribution Orders ─────────────────────────────────────────
        // READ: franchisees see their own orders; HO/Distributer sees all — scoped in controller
        // WRITE: only dispatch-capable roles
        Route::middleware('erp.role:Super Admin|Admin|Distributer|Sales Team|Franchisee')->group(function () {
            Route::get('dist-orders/export', [DistOrderController::class, 'export'])
                ->name('dist-orders.export');

            Route::resource('dist-orders', DistOrderController::class)
                ->only(['index', 'show'])
                ->whereNumber('dist_order');

            Route::get('dist-orders/{dist_order}/picklist-pdf', [DistOrderController::class, 'picklistPdf'])
                ->name('dist-orders.picklist-pdf')
                ->whereNumber('dist_order');

            Route::get('dist-orders/{dist_order}/gst-invoice-pdf', [DistOrderController::class, 'gstInvoicePdf'])
                ->name('dist-orders.gst-invoice-pdf')
                ->whereNumber('dist_order');

            Route::post('dist-orders/{dist_order}/unlock', [DistOrderController::class, 'unlock'])
                ->name('dist-orders.unlock')
                ->whereNumber('dist_order');

            Route::post('dist-orders/{dist_order}/payments', [DistOrderController::class, 'submitPayment'])
                ->name('dist-orders.payments.store');

            Route::post('dist-orders/{dist_order}/reorder-to-cart', [DistOrderController::class, 'reorderToCart'])
                ->name('dist-orders.reorder-to-cart')
                ->whereNumber('dist_order');
        });

        Route::middleware('erp.role:Super Admin|Admin|Distributer|Sales Team|Account')
            ->group(function () {
                Route::post('dist-orders/{dist_order}/accept',   [DistOrderController::class, 'accept'])->name('dist-orders.accept');
                Route::post('dist-orders/{dist_order}/allocate', [DistOrderController::class, 'allocate'])->name('dist-orders.allocate');
                Route::post('dist-orders/{dist_order}/dispatch', [DistOrderController::class, 'dispatchOrder'])->name('dist-orders.dispatch');
                Route::post('dist-orders/{dist_order}/reject',   [DistOrderController::class, 'reject'])->name('dist-orders.reject');
                Route::post('dist-orders/{dist_order}/payments/{dist_order_payment}/confirm', [DistOrderController::class, 'confirmPayment'])->name('dist-orders.payments.confirm');
                Route::post('dist-orders/{dist_order}/payments/{dist_order_payment}/reject', [DistOrderController::class, 'rejectPayment'])->name('dist-orders.payments.reject');
            });
    });

    /*
    |--------------------------------------------------------------------------
    | B2B Portal — Franchisee places bulk orders from HO
    |--------------------------------------------------------------------------
    */
    Route::prefix('b2b')->name('b2b.')
        ->middleware('erp.role:Franchisee|Super Admin')
        ->group(function () {
            Route::get(   '/cart',          [CartController::class, 'index'])->name('cart.index');
            Route::post(  '/cart/add',      [CartController::class, 'addToCart'])->name('cart.add');
            Route::patch( '/cart/{item}',   [CartController::class, 'updateQty'])->name('cart.updateQty')->whereNumber('item');
            Route::delete('/cart/{item}',   [CartController::class, 'remove'])->name('cart.remove')->whereNumber('item');
            Route::post(  '/cart/checkout', [CartController::class, 'checkout'])->name('cart.checkout');
        });

    /*
    |--------------------------------------------------------------------------
    | Retail POS — Franchisee daily billing
    |--------------------------------------------------------------------------
    */
    Route::prefix('pos')->name('pos.')
        ->middleware('erp.role:Franchisee|Super Admin')
        ->group(function () {
            Route::get( '/',         [POSController::class, 'index'])->name('index');
            Route::get( '/settings',  [POSController::class, 'settings'])->name('settings');
            Route::patch('/settings', [POSController::class, 'updateSettings'])->name('settings.update');
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
            Route::get( '/shift/status',      [POSController::class, 'shiftStatus'])->name('shift.status');
            Route::post('/shift/open',        [POSController::class, 'openShift'])->name('shift.open');
            Route::post('/shift/close',       [POSController::class, 'closeShift'])->name('shift.close');
            Route::post('/estimate/print',    [POSController::class, 'estimatePrint'])->name('estimate.print');
            Route::post('/estimate/export',   [POSController::class, 'estimateExport'])->name('estimate.export');
            Route::post('/override/authorize', [POSController::class, 'authorizeOverride'])->name('override.authorize');
            Route::post('/credit-info',       [POSController::class, 'customerCreditInfo'])->name('customerCreditInfo');
            Route::post('/customer-recent-bills', [POSController::class, 'customerRecentBills'])->name('customerRecentBills');
            Route::get( '/bills/{salesInvoice}/items', [POSController::class, 'billItems'])->name('billItems')->whereNumber('salesInvoice');
            Route::post('/credit-collect',    [POSController::class, 'collectCredit'])->name('creditCollect');
            Route::post('/return',            [POSController::class, 'processReturn'])->name('processReturn');
            Route::post('/holds',             [POSController::class, 'saveHold'])->name('holds.save');
            Route::get( '/holds',             [POSController::class, 'listHolds'])->name('holds.index');
            Route::get( '/holds/{posHold}',   [POSController::class, 'loadHold'])->name('holds.show')->whereNumber('posHold');
            Route::post('/holds/{posHold}/release-lock', [POSController::class, 'releaseHoldLock'])->name('holds.releaseLock')->whereNumber('posHold');
            Route::delete('/holds/{posHold}', [POSController::class, 'cancelHold'])->name('holds.cancel')->whereNumber('posHold');
            Route::post('/quotations', [POSController::class, 'storeQuotation'])->name('quotations.store');
            Route::post('/quotations/customer', [POSController::class, 'customerQuotations'])->name('quotations.customer');
            Route::get( '/quotations/{salesQuotation}', [POSController::class, 'quotationDetails'])->name('quotations.show')->whereNumber('salesQuotation');

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
        ->middleware('erp.role:Franchisee|Super Admin')
        ->whereNumber('customer');

    Route::middleware('erp.role:Franchisee|Super Admin')->group(function () {
        Route::get('franchise/staff', [FranchiseStaffController::class, 'index'])->name('franchise.staff.index');
        Route::post('franchise/staff', [FranchiseStaffController::class, 'store'])->name('franchise.staff.store');
        Route::patch('franchise/staff/{franchiseStaff}', [FranchiseStaffController::class, 'update'])->name('franchise.staff.update')->whereNumber('franchiseStaff');
        Route::delete('franchise/staff/{franchiseStaff}', [FranchiseStaffController::class, 'destroy'])->name('franchise.staff.destroy')->whereNumber('franchiseStaff');
    });

    /*
    |--------------------------------------------------------------------------
    | Expenses  (Franchisee + Super Admin + Payment Manager)
    |--------------------------------------------------------------------------
    */
    Route::resource('expenses', ExpenseController::class)
        ->only(['index', 'create', 'store', 'edit', 'update'])
        ->middleware('erp.role:Franchisee|Super Admin|Admin|Account')
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
        ->middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head')
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

    Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head')
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
    | Notice Board / Announcements
    | All authenticated users can read active notices; HO admins manage them.
    |--------------------------------------------------------------------------
    */
    Route::get('announcements', [AnnouncementController::class, 'index'])
        ->name('announcements.index');

    Route::middleware('erp.role:Super Admin|Admin')->group(function () {
        Route::get('announcements/create', [AnnouncementController::class, 'create'])
            ->name('announcements.create');
        Route::post('announcements', [AnnouncementController::class, 'store'])
            ->name('announcements.store');
        Route::get('announcements/{announcement}/edit', [AnnouncementController::class, 'edit'])
            ->name('announcements.edit')
            ->whereNumber('announcement');
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])
            ->name('announcements.update')
            ->whereNumber('announcement');
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])
            ->name('announcements.destroy')
            ->whereNumber('announcement');
    });

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

    Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head')
        ->group(function () {
            Route::get( 'shop-visits/create', [ShopVisitController::class, 'create'])->name('shop-visits.create');
            Route::post('shop-visits',        [ShopVisitController::class, 'store'])->name('shop-visits.store');
        });

    /*
    |--------------------------------------------------------------------------
    | Financial Ledger
    |--------------------------------------------------------------------------
    */
    Route::get('/ledger', [FinancialLedgerController::class, 'index'])
        ->name('ledger.index')
        ->middleware('erp.role:Super Admin|Admin|Account|Franchisee');

    /*
    |--------------------------------------------------------------------------
    | Reports & GST Compliance
    |--------------------------------------------------------------------------
    */
    Route::prefix('reports')->name('reports.')->group(function () {

        // Stock — HO, territory heads, franchisees, distributors
        // Controller internally scopes data to the requesting user's role / location
        Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head|Distributer|Franchisee')
            ->group(function () {
                Route::get('/stock/summary',    [ReportController::class, 'stockSummary'])->name('stock.summary');
                Route::get('/stock/current',    [ReportController::class, 'stockCurrent'])->name('stock.current');
                Route::get('/stock/expiry',     [ReportController::class, 'stockExpiry'])->name('stock.expiry');
                Route::get('/stock/non-moving', [ReportController::class, 'stockNonMoving'])->name('stock.non-moving');
                Route::get('/sales/daily-register', [ReportController::class, 'dailySalesRegister'])->name('sales.daily-register');
            });

        // GST Compliance — HO admin + Account/Tax officer only
        Route::middleware('erp.role:Super Admin|Admin|Account')
            ->group(function () {
                Route::get('/gst/gstr1',  [ReportController::class, 'gstr1'])->name('gst.gstr1');
                Route::get('/gst/gstr2',  [ReportController::class, 'gstr2'])->name('gst.gstr2');
                Route::get('/gst/gstr3b', [ReportController::class, 'gstr3b'])->name('gst.gstr3b');
                Route::get('/compliance/tds', [ReportController::class, 'tds'])->name('compliance.tds');
            });

        Route::middleware('erp.role:Super Admin|Admin|Account|Distributer|Sales Team')
            ->group(function () {
                Route::get('/compliance/ewaybill', [ReportController::class, 'ewaybill'])->name('compliance.ewaybill');
            });

        // MIS / Business Intelligence — HO + territory heads only
        Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head')
            ->group(function () {
                Route::get('/bi/top-products', [ReportController::class, 'topProducts'])->name('bi.top-products');
                Route::get('/bi/franchisee-sales', [ReportController::class, 'franchiseeSales'])->name('bi.franchisee-sales');
                Route::get('/bi/growth', [ReportController::class, 'growth'])->name('bi.growth');
            });

        Route::middleware('erp.role:Super Admin|Admin|State Head|Regional Head|Zonal Head|District Head|Distributer')
            ->get('/stock/near-expiry-dispatch', [ReportController::class, 'nearExpiryDispatch'])
            ->name('stock.near-expiry-dispatch');

        // Finance — HO + Account role (payables are commercially sensitive)
        Route::middleware('erp.role:Super Admin|Admin|Account')
            ->get('/finance/vendor-outstanding', [ReportController::class, 'vendorOutstanding'])->name('finance.vendor-outstanding');

        // Commissions — all authenticated roles; controller scopes to current user unless Super Admin
        Route::get('/commissions', [ReportController::class, 'commissions'])->name('commissions');
    });
});

require __DIR__.'/auth.php';

