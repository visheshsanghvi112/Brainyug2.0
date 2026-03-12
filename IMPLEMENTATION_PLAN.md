# BrainYug ERP v2.0 — Implementation Plan & Status

> **Architecture principle:** Every decision is made to be better than legacy — not a port.
> Legacy = direct SQL mutations, no audit trail, race conditions, hardcoded values.
> BrainYug ERP v2 = event-sourced inventory, typed services, immutable ledger, strict validation.

---

## TECH STACK

| Layer | Choice | Why better than legacy |
|---|---|---|
| Backend | Laravel 12, PHP 8.3 | Type safety, named arguments, match expressions |
| Frontend | Vue 3 + Inertia.js | SPA feel, no separate API surface for web UI |
| Styling | Tailwind CSS | Utility-first, no custom CSS drift |
| Auth | Laravel Sanctum | Web session + mobile API token from one setup |
| RBAC | Spatie Laravel Permission | DB-driven roles, not hardcoded `if type == 5` |
| Inventory | Event-sourced `inventory_ledgers` | Full audit trail; stock = SUM, never a mutable counter |
| Accounting | `financial_ledgers` (double-entry) | Replaces 6 scattered tbl_cash/tbl_bank/tbl_credit tables |
| DB | MySQL 8.0 | Row locking (`lockForUpdate`) for race condition protection |

---

## BUSINESS MODEL SUMMARY

```
HO (Head Office / Admin)
├── Manages product master, HSN, suppliers
├── Receives stock from suppliers (Purchase Invoice → GRN)
├── HO Warehouse stock tracked via inventory_ledgers WHERE location_type='warehouse'
├── Dispatches to franchisees (DistOrder → inventory_ledgers DISPATCH)
│
Franchisee Network
├── Places B2B orders to HO via portal
├── Receives dispatched stock (inventory_ledgers DISPATCH_IN)
├── Sells to retail customers via POS (inventory_ledgers SALE)
└── Their stock = inventory_ledgers WHERE location_type='franchisee' AND location_id=X
```

---

## PHASE STATUS OVERVIEW

| Phase | Status | Notes |
|---|---|---|
| 1. Foundation | ✅ Done | Auth, roles, app shell |
| 2. Product Master | ✅ Done | 50+ fields, all legacy attributes covered |
| 3. Franchisee Network | ✅ Done | Hierarchy CRUD, approval workflow |
| 4. Procurement & GRN | ✅ Done + improved | Duplicate guard, expired-batch block, due_days/transporter |
| 5. B2B Ordering | ✅ Done | Cart → Order → Dispatch with commission engine |
| 6. Retail POS | 🔶 Core done, gaps remain | See Phase 6 detail |
| 7. Reports & GST | ✅ Done | GSTR-1/2, stock reports, BI |
| 8. Mobile API | ✅ Done | Sanctum auth, catalog, orders, operational |
| 9. Frontend hooks | ✅ Done | Vue forms wired to AJAX; new PI fields added |
| 10. Data Migration | ❌ Not started | Scripts to import from both legacy DBs |

---

## PHASE 6 — RETAIL POS (Detailed)

### 6.1 POS Controller & API — ✅ DONE (this session)

All AJAX endpoints built. Key improvements over legacy:

| Legacy gap | What we built instead |
|---|---|
| No server-side stock check before saving | `lockForUpdate()` inside DB transaction — race-condition safe |
| Expired batches sold silently | Hard abort with message if `expiry_date < today()` |
| Discount not validated server-side | Max discount checked against `products.max_discount` |
| GST hardcoded as 5% | Reads `sgst+cgst` or `igst` from product master per item |
| Bill number used simple auto-increment | `COUNT(today's bills) + 1` per franchisee per day (still needs DB lock — see gaps below) |
| Customer added only via separate screen | `storeCustomer()` / `firstOrCreate()` inline at POS |
| Doctor was a select dropdown with full list | Autocomplete AJAX search |

API endpoints (all under `POST /pos/*` or `GET /pos/*`):
- `searchProduct` — debounced live search (name/SKU/barcode)
- `getProductBatches` — FEFO-ordered batch list with stock levels
- `checkStock` — availability check for a specific batch
- `lookupCustomer` — by mobile number
- `searchCustomers` — autocomplete
- `storeCustomer` — inline quick-add
- `searchDoctors` — autocomplete
- `storeDoctor` — inline quick-add
- `nextBillNumber` — sequential per-franchisee per-day counter
- `customerCreditInfo` — pending credit + last 10 bills
- `checkout` — atomic sale with 3 pre-flight guards + ledger + stock deduction
- `processReturn` — partial/full return with stock restoration

### 6.2 POS Vue — ✅ DONE (this session)

Full rewrite of `POS/Index.vue`:
- Live AJAX product search (not a static prop list)
- Batch selection modal with FEFO order, expiry color-coding
- Customer mobile lookup + inline create
- Doctor autocomplete
- Cart uses actual GST% per item (not hardcoded 5%)
- Checkout uses `axios` POST → stays on POS page (no full-page reload)
- `lockForUpdate` prevents concurrent overbilling
- Keyboard shortcut: F2 = Checkout, ESC = close modal or clear search

### 6.3 Sales Invoice Print — ✅ EXISTS

`POS/Invoices/Show.vue` has `window.print()` with `@media print` CSS.

**Remaining gap:** No thermal printer (58mm/80mm) template. For proper thermal print:
- [ ] Add a `/pos/invoices/{id}/print` route that returns a minimal HTML view
- [ ] Or add a toggle in Show.vue for "Thermal (80mm) / A4" mode

### 6.4 Customer Credit Collection — ❌ NOT BUILT

Legacy `tbl_credit_payment` had a separate flow to collect outstanding dues.
The database side (`sale_payments.credit_amount` field) is ready but:
- [ ] `POST /pos/credit-collect` endpoint — takes customer_id + amount + payment_mode
- [ ] Deducts from outstanding credit balance, records in `financial_ledgers`
- [ ] UI: A "Collect Credit" button on the POS or Customer profile page

### 6.5 Free Schema Auto-Apply — ❌ NOT BUILT

`products.free_schema` column exists (e.g. `"10+1"` meaning buy 10 get 1 free).
Legacy applied this automatically on quantity entry.
- [ ] Parse `free_schema` in `addItemFromBatch()` in Vue: when qty ≥ threshold, show "1 unit free" and add a 0-rate bonus line
- [ ] Server-side: `checkout()` should accept a `free_qty` per line item

---

## PHASE 9 — FRONTEND GAPS (Vue Forms)

### 9.1 Purchase Invoice CreateEdit.vue — ✅ DONE

`due_days`, `transporter`, `lr_number` added to `useForm()` and rendered in the header grid.
- Credit-days field shows a computed due-date preview (e.g. "Due: 15 Feb 2026")
- `submit()` now correctly routes to PUT for edits vs POST for new
- Duplicate invoice error from controller shown inline under `supplier_invoice_no`

### 9.2 Product CreateEdit.vue — ✅ DONE

All 4 AJAX endpoints wired:
- On `hsn_id` change → `admin.products.hsnTax` → auto-fills SGST / CGST / IGST
- On `rack_section_id` change → `admin.products.rackAreas` → cascades Rack Area `<select>`
- On product name change (600 ms debounce) → `admin.products.checkName` → amber warning if duplicate
- Edit mode: rack areas for existing section pre-loaded on mount
- Rack section & area fields are now `<select>` dropdowns (not raw number inputs)

### 9.3 Rack Section & Area Management — ❌ NO CRUD

`rack_sections` and `rack_areas` tables exist. No controller, no Vue page, no routes.

- [ ] `GET /admin/rack-sections` — list with inline area management
- [ ] AJAX: `POST /admin/rack-sections` — create section
- [ ] AJAX: `POST /admin/rack-areas` — create area under section
- [ ] Simple modal-based management (no full CRUD page needed)

### 9.4 Supplier Payment Tracking — ❌ NOT BUILT

Purchase invoices have `due_days`. There's no way to record payment made to a supplier.

- [ ] `POST /admin/suppliers/{supplier}/payment` — records a `PAYMENT_MADE` entry in `financial_ledgers`
- [ ] Show outstanding payables per supplier on Supplier show page
- [ ] Age analysis: invoices overdue beyond `invoice_date + due_days`

---

## PHASE 10 — ARCHITECTURE: REMAINING HARDENING

### 10.1 Bill Number Race Condition — ✅ FIXED

`bill_counters` table created with `(franchisee_id, counter_date)` unique constraint.
`nextBillNumber()` uses `insertOrIgnore` + `lockForUpdate` + atomic increment.
No duplicate bill numbers possible under any concurrency.

### 10.2 LedgerService Running Balance — ✅ FIXED

`LedgerService::recordEntry()` now wraps the balance-read + write in `DB::transaction()` with `->lockForUpdate()` on the latest ledger row. Two concurrent POS transactions serialize correctly; no wrong running balances possible.

### 10.3 API Rate Limiting — 🔶 VERIFY

Check `bootstrap/app.php` or `RouteServiceProvider` for throttle middleware on API routes.
- [ ] Confirm `api` middleware group has `throttle:60,1` (or stricter for auth endpoints: `throttle:5,1`)
- [ ] Auth login endpoint should have `throttle:5,1` to prevent brute force

### 10.4 Inventory Ledger Index Performance — ⚠ FUTURE

As the ledger grows (millions of rows), `SUM(qty_in) - SUM(qty_out)` queries will slow down.

**Future fix (not urgent now):**
- Periodic snapshot table: `stock_snapshots` stores pre-computed stock as of date X
- Ledger query becomes: `snapshot_qty + SUM(ledger rows after snapshot_date)`
- Implement when `inventory_ledgers` exceeds ~500k rows

---

## PHASE 11 — DATA MIGRATION FROM LEGACY

Nothing is built here yet. This is the unglamorous but critical work before go-live.

### 11.1 Strategy

```
Legacy FMS (genericp_franchisee)  ──┐
                                     ├── ETL Scripts ──→ brainyug_erp
Legacy PharmaERP (pharmaer_pharmaerp) ┘
```

Legacy data problems to solve:
1. **Products exist in both DBs** — need deduplication by medicine name/SKU
2. **Stock is a counter** (`actual_stock`) — need to seed `inventory_ledgers` with OPENING_BALANCE entries
3. **Customers/Franchisees** — mobile numbers may have formatting differences
4. **Historical sales** — import as read-only reference records, don't run through full POS logic

Migration order (must respect foreign keys):
1. Company masters → `company_masters`
2. Categories → `item_categories`
3. Salts → `salt_masters`
4. HSN codes → `hsn_masters`
5. Products → `products`
6. Rack sections/areas → `rack_sections`, `rack_areas`
7. Franchisees → `franchisees` + `users`
8. Suppliers → `suppliers`
9. Customers → `customers`
10. Stock opening balances → `inventory_ledgers` (transaction_type = 'OPENING_BALANCE')
11. Outstanding ledger balances → `financial_ledgers`

- [ ] `database/seeders/LegacyMigrationSeeder.php` — reads from legacy DBs via second DB connection
- [ ] Dry-run mode: validate counts, check for duplicates, report by report
- [ ] Post-migration reconciliation report: old stock vs new stock per product

### 11.2 Config needed

```php
// config/database.php — add legacy connections
'legacy_fms' => [
    'driver' => 'mysql',
    'database' => 'genericp_franchisee',
    ...
],
'legacy_pharma' => [
    'driver' => 'mysql',
    'database' => 'pharmaer_pharmaerp',
    ...
],
```
- [ ] Add legacy DB connections to `config/database.php`
- [ ] Test read-only access to legacy DBs during migration

---

## PHASE 12 — MISSING REPORTS & EXPORTS

### 12.1 Missing Reports
- [ ] **GSTR-3B** — Monthly summary for tax filing (GSTR-1+GSTR-2 combined view)
- [ ] **Vendor payment outstanding** — per supplier, aged by `invoice_date + due_days`
- [ ] **Franchisee-wise sales** — Top/Bottom 10 franchisees by revenue
- [ ] **Growth reports** — Month-over-month, year-over-year by franchise/product
- [ ] **Commission statement** — Per franchise, per month, with TDS deduction detail
- [ ] **Near-expiry dispatch report** — HO can see franchise stock expiring within 60 days and push stock movement

### 12.2 Export Formats
- [ ] Excel export: Purchase register, Sales register, Commission statement
- [ ] PDF: Sales invoice, Purchase invoice, Dispatch note
- [ ] JSON: GSTR-1 in NIC e-invoice schema (for businesses above ₹5cr turnover)

---

## PHASE 13 — FUTURE-PROOF FEATURES (NOT IN LEGACY AT ALL)

These didn't exist in legacy. Building them makes this product genuinely better:

### 13.1 Expiry Auto-Alert System
- [ ] Scheduled command: `php artisan stock:check-expiry` — runs daily
- [ ] Sends alert (email/push) to franchisee for batches expiring within 30 days
- [ ] HO can see cross-franchisee expiry dashboard for recall planning

### 13.2 Reorder Auto-Trigger
- [ ] `products.reorder_quantity` is already stored
- [ ] When a POS sale causes stock to drop below `reorder_quantity`, auto-create a draft B2B cart entry
- [ ] Franchisee sees "Reorder Suggested" banner on POS dashboard

### 13.3 Scheme/Free Schema Engine
- [ ] `products.free_schema` field (e.g. `"10+1"`, `"5+1"`) is stored but never applied
- [ ] POS: when qty ≥ threshold, auto-add free qty at ₹0 as bonus line
- [ ] Purchase: manufacturer scheme — apply on GRN entry

### 13.4 Mobile Push Notifications
- [ ] FCM (Firebase Cloud Messaging) for: order dispatched, payment reminder, meeting invite
- [ ] Table: `push_tokens` (user_id, device_token, platform)
- [ ] Service: `NotificationService` wraps FCM HTTP v1 API

### 13.5 Notice Board
- [ ] `announcements` table: title, body, target_roles, valid_from, valid_to
- [ ] HO posts notices; franchisees see relevant ones on their dashboard
- [ ] API: `GET /api/notices` for mobile

### 13.6 Barcode/Label Printing
- [ ] Products have `barcode` field (EAN-13 or CODE-128)
- [ ] Admin can generate + print label sheets for warehouse racking
- [ ] Library: `picqer/php-barcode-generator` (composer)

---

## WHAT'S DONE (CONFIRMED, CODE EXISTS)

| Feature | File(s) |
|---|---|
| Product Master CRUD (50+ fields) | `Admin/ProductController.php`, `Master/Products/CreateEdit.vue` |
| Product AJAX: HSN tax, rack areas, name check, search | `Admin/ProductController.php` (hsnTax, rackAreas, checkProductName, search) |
| Purchase Invoice with GRN workflow | `Admin/PurchaseInvoiceController.php` |
| Purchase returns | `Admin/PurchaseReturnController.php` |
| Inventory ledger (event-sourced) | `Services/InventoryService.php` |
| Financial double-entry ledger | `Services/LedgerService.php` |
| Commission engine (recursive hierarchy) | `Services/CommissionService.php` |
| POS billing (11 AJAX endpoints) | `POSController.php` |
| POS Vue (live search, batch modal, real GST) | `Pages/POS/Index.vue` |
| POS checkout: race lock + expired batch guard + max discount | `POSController::checkout()` |
| Sales returns (standalone) | `SalesReturnController.php`, `Pages/POS/Returns/` |
| Franchisee network with approval flow | `Admin/FranchiseeController.php` |
| B2B ordering + dispatch | `B2b/CartController.php`, `Admin/DistOrderController.php` |
| Stock reports (current, expiry, non-moving, summary) | `ReportController.php`, `Reports/Stock/*.vue` |
| GST reports (GSTR-1, GSTR-2) | `ReportController.php`, `Reports/GST/*.vue` |
| BI reports (top products) | `ReportController.php`, `Reports/BI/TopProducts.vue` |
| Sales invoice print view | `Pages/POS/Invoices/Show.vue` (window.print) |
| Mobile API (auth, catalog, orders, POS, operational) | `Api/*.php` |
| Meetings, shop visits, support tickets | respective controllers + Vue pages |
| Expenses tracking | `ExpenseController.php` |
| Rack areas table + model | migration `2026_03_11_...`, `Models/RackArea.php` |
| Purchase invoice: due_days, transporter, lr_number | migration `2026_03_11_...` |
| Duplicate invoice guard (friendly error) | `PurchaseInvoiceController::store()` |
| Expired batch block on GRN approve | `PurchaseInvoiceController::approve()` |

---

## IMMEDIATE NEXT TASKS (PRIORITY ORDER)

1. **Fix bill number race condition** — create `bill_counters` table, lock on increment
2. **Fix LedgerService race condition** — add `lockForUpdate()` on balance read
3. **Update PurchaseInvoice Vue** — add `due_days`, `transporter`, `lr_number` inputs
4. **Wire Product form AJAX hooks** — HSN auto-fill, rack area cascade, name check
5. **Rack Section/Area CRUD** — simple admin page to manage warehouse layout
6. **Supplier payment recording** — POST endpoint + Supplier balance view
7. **Customer credit collection** — endpoint + POS UI button
8. **Free schema engine** — parse `free_schema`, auto-apply on POS qty entry
9. **Data migration scripts** — LegacyMigrationSeeder for both legacy DBs
10. **Thermal bill template** — 80mm print template for POS bills
11. **API rate limiting** — verify throttle on auth endpoint
12. **Commission statement report** — per franchisee per month with TDS detail
13. **GSTR-3B monthly summary** — combined GSTR-1+2 view for tax filing
14. **Reorder auto-trigger** — watch reorder_quantity on sale, create draft cart
15. **Expiry auto-alert** — scheduled command + notification


---

## 🏢 THE BUSINESS MODEL (What We Are Building)

### Who Are We?
**BrainYug / GenericPlus** = A **pharmaceutical manufacturer AND marketer** (combined entity). We manufacture drugs, procure from other suppliers, and distribute through a franchise network.

### The Two Sides of This ERP

| Side | Legacy System | Who Uses It | What They Do |
|------|--------------|-------------|-------------|
| **HO (Head Office)** | `pharmaer_pharmaerp` | Internal team (Admin, Warehouse, Procurement, Sales) | Manage products, purchase from suppliers, maintain HO warehouse stock, approve/dispatch orders to franchisees, manage commissions, GST filing |
| **FMS (Franchise Management)** | `genericp_franchisee` | Distributors/Franchisees + State/District/Sister Heads | Browse catalog, place purchase orders to HO, receive dispatched stock, sell to end customers via POS billing, manage their own local stock, track payments |

### User Type Hierarchy (from legacy `users.type` field)
```
Type 1 = Super Admin (HO — sees everything)
Type 2 = State Head (manages all districts in a state)
Type 3 = Master/Zone Head (manages districts in a zone)
Type 4 = District Head (manages franchisees in a district)
Type 5 = Franchisee (the medical shop owner — has POS, orders from HO)
Type 6 = Distributor (similar to franchisee but with different billing)
+ Sister Head (sub-district level, manages a cluster of franchisees)
+ Franchisee Users (staff hired by franchisees — cashiers, etc.)
```

### Stock Isolation (THE CRITICAL CONCEPT)
```
HO Warehouse Stock (pharmaerp.tbl_stock)
├── Batch A: Paracetamol 500mg — 5000 units
├── Batch B: Amoxicillin 250mg — 3000 units
│
├── DISPATCH to Franchisee #12 → -200 units from HO, +200 to Franchisee #12
│
Franchisee #12 Stock (franchisee.tbl_stock WHERE franch_id=12)
├── Batch A: Paracetamol 500mg — 200 units
│   └── SALE to customer → -5 units (POS billing)
```

**HO sees HO stock. Franchisee sees ONLY their stock. These are completely separate views.**

---

## 🛠 TECH STACK (Confirmed)
- **Backend:** Laravel 11 (PHP 8.2+)
- **Frontend:** Vue 3 + Inertia.js + Tailwind CSS
- **Database:** MySQL 8.0 (`brainyug_erp`)
- **Auth:** Laravel Sanctum (web + mobile API)
- **RBAC:** Spatie Laravel Permission
- **Audit:** Spatie Activity Log
- **PDF:** barryvdh/laravel-dompdf
- **Excel:** maatwebsite/laravel-excel
- **2026 GST:** 5% on most medicines, 6-digit HSN mandatory, e-invoicing for turnover > ₹5cr

---

## 📋 PHASED EXECUTION PLAN

### PHASE 1: Foundation & App Shell ✅ COMPLETE
- [x] Laravel + Vue + Inertia + Tailwind scaffolded
- [x] MySQL `brainyug_erp` created
- [x] Spatie Permissions + Activity Log installed
- [x] Roles seeded (Super Admin, State Head, Zone Head, District Head, Franchisee)
- [x] Premium responsive App Shell (sidebar, header)

---

### PHASE 2: Master Data Catalog ✅ COMPLETE
- [x] Geography: `states`, `districts`, `cities` migrations
- [x] Taxonomy: `item_categories`, `rack_sections` migrations
- [x] Product Masters: `company_masters`, `hsn_masters`, `salt_masters`, `box_sizes` migrations
- [x] Clinical fields added to `salt_masters` (Schedule H, narcotic flags)
- [x] Unified Products Engine: `products` table migration + Model + ProductController CRUD
- [x] Routes registered in `web.php` for Admin/HO
- [x] Vue: `Pages/Master/Products/Index.vue` & `CreateEdit.vue`

---

### PHASE 3: User Hierarchy & Franchise Network ✅ COMPLETE
- [x] Territory Hierarchy Engine: Proper pivot `territory_assignments` (replacing legacy CSV)
- [x] Franchisee Profile Engine: `franchisees` migration with KYC, DL, Bank fields
- [x] Franchisee Staffing: `franchisee_staff` migration
- [x] Controller: `FranchiseeController` with approve/reject/activate workflows
- [x] Role-Morphing Dashboard logic established

---

### PHASE 4: HO Procurement & Inventory ⏳ IN PROGRESS
- [x] Supplier Network: `suppliers` migration + CRUD
- [x] Purchase Invoices: `purchase_invoices` + `purchase_invoice_items` migrations
- [x] HO Receiving Workflow: `PurchaseInvoiceController` handles stock-in logic
- [x] **Event-Sourced Inventory Ledger**: `InventoryService` replaces direct stock manipulation
- [x] Purchase Returns: `purchase_returns` migration + approve/cancel workflow
- [ ] Vue: Procurement module pages (Invoices, Suppliers)

---

### PHASE 5: B2B Ordering & Dispatch ⏳ IN PROGRESS
- [x] Franchisee B2B Portal: `B2bCart` + `B2bCartItem` migrations
- [x] Order Management: `dist_orders` + `dist_order_items` (Normalized status flow 0-4)
- [x] **Recursive Commission Engine**: `CommissionService` calculates hierarchy payouts on dispatch
- [x] B2B Workflow: `CartController` (Franchisee) and `DistOrderController` (Admin) implemented
- [x] **Dual-Deduction Fix**: `InventoryService->recordDispatch()` handles HO-out and Franchisee-in in a single transaction
- [ ] B2B Invoicing: `barryvdh/laravel-dompdf` integration for GST invoices

---

## 🏗 ARCHITECTURAL CONTEXT (The "Source of Truth")

### 1. Inventory Ledger (Event-Sourcing)
Unlike legacy systems that directly update `tbl_stock.actual_stock`, BrainYug ERP v2.0 uses an immutable ledger (`inventory_ledgers`).
- **Current Stock** = `SUM(qty_in) - SUM(qty_out)` for a (product, batch, location).
- **Auditability**: Every single stock movement (Purchase, Sale, Dispatch, Return) has a permanent record and a reference ID to the source document.
- **Location Isolation**: Locations are identified by `location_type` (warehouse/franchisee) and `location_id`.

### 2. Recursive Commission Engine
The hierarchy is no longer a shallow 2-3 level parent chain.
- **Traversal**: Uses a recursive `parent` relationship on the `Franchisees` table.
- **Logic**: When an order is accepted/dispatched, the `CommissionService` crawls up the chain (Franchisee -> DH -> ZH -> SH), checking each parent's specific commission % and applying TDS.
- **Data Integrity**: Each commission transaction is linked to a `dist_order_id` for perfect reconciliation.

### 3. Service Layer Pattern
To keep Controllers lean (unlike the 4000+ line legacy ones), all business logic is encapsulated in `app/Services/`:
- `InventoryService`: All stock movements.
- `CommissionService`: Hierarchy payouts and TDS logic.
- `OrderService` (Planned): Complex B2B order status transitions.

---

### PHASE 6: Retail POS & Financial Accounting ❌

> **Legacy source:** `client_sale_info`, `sale_info`, `tbl_cash`, `tbl_bank`, `tbl_credit_payment`, `tbl_tax`, `tbl_return_sale`, `tbl_balance`

#### 6.1 POS Billing Panel (Franchisee Side)
- [ ] Migration `sales_invoices` (replaces `client_sale_info`):
  - bill_no, franchisee_id, user_id, customer_id, doctor_id, date_time
  - total_amount, total_discount_amount, discount_percent, other_charges
  - **Legacy insight:** Conversion factor on products (strips/tablets from box)
- [ ] Migration `sales_invoice_items` (replaces `sale_info`):
  - product_id, batch_no, exp_date, qty, price, discount
- [ ] Migration `sale_payments` (replaces scattered `tbl_cash`, `tbl_bank`, `tbl_credit_payment`):
  - **6 legacy payment modes unified into one table:**
  - payment_type: cash|bank|credit|cash_credit|bank_credit|cash_bank
  - cash_amount, bank_amount, credit_amount (for split payments)
  - transaction_no (for bank/UPI), wallet_type
- [x] Controller: `POSController` (Internal stock tracking & checkout)
- [x] Vue: `Pages/POS/Index.vue` — Full-screen, keyboard shortcut driven
- [x] On sale → inventory_ledger SALE entry (no direct stock manipulation)

#### 6.2 Sales Returns
- [x] Migration `sales_returns` (from `tbl_return_sale`)
- [x] Stock re-entry via inventory_ledger RETURN entry
- [x] Controller & UI for Sales Returns

#### 6.3 Customers & Doctors
- [x] Migration `customers` (Integrated into POS)
- [x] Migration `doctors` (Integrated into POS)

#### 6.4 Financial Ledger Hub
- [x] Migration `financial_ledgers`:
  - Links to: franchisee, supplier, order, invoice
  - Types: PAYMENT_RECEIVED (CR), PAYMENT_MADE (DR), CREDIT_NOTE, DEBIT_NOTE
  - Modes: CASH, BANK/NEFT, UPI, CHEQUE, CREDIT
  - Running balance tracking
- [x] `LedgerService` for immutable double-entry accounting
- [x] Integrated into B2B Dispatch, POS Sales, and Commissions

#### 6.5 Commission Engine
- [x] Migration `commissions`
- [x] `CommissionService` with recursive hierarchy traversal
- [x] Automated net-of-TDS calculation on HO dispatch

#### 6.6 Expense Tracking
- [x] Migration `expenses` & `expense_categories`
- [x] Controller & UI for logging store overheads

---

### PHASE 7: Reports, Analytics & GST Compliance ✅ COMPLETE

> **Legacy source:** `Gstr1.php`, `Gstr2.php`, `Report_management.php`, `Stock_Report_Management.php`, `GSTR_Report_Management.php`

#### 7.1 GST Reports
- [x] GSTR-1 export (outward supplies — our sales to franchisees)
- [x] GSTR-2 export (inward supplies — our purchases from suppliers)
- [x] HSN-wise summary
- [ ] E-invoice ready data format

#### 7.2 Stock Reports
- [x] Current stock by location (HO vs per-franchisee)
- [x] Expiry reports (30/60/90 day windows)
- [x] Batch-wise stock audit
- [x] Non-moving/slow-moving products

#### 7.3 Business Intelligence
- [x] Most/Least selling products (BI Dashboard built using streaming queries)
- [ ] Top/Bottom 10 franchisees by purchase volume
- [ ] Growth/Degrowth reports (month-over-month, YoY)
- [ ] Area-wise performance
- [ ] Commission reports with TDS

#### 7.4 Operational Reports
- [x] Purchase register
- [x] Sales register
- [x] Payment outstanding (who owes what)
- [x] Drug-wise sales analysis

---

### PHASE 8: Mobile API & Operational Tools ✅ COMPLETE

> **Legacy source:** `Webservices.php` (FMS — 1422 lines, 40+ endpoints)

#### 8.1 Sanctum Mobile API (Redis Cache Tuned)
- [x] `POST /api/auth/login` — Returns Bearer token
- [x] `GET /api/catalog/products` — Product catalog with caching for offline-sync
- [x] `GET /api/stock/current` — Franchisee's own stock (Cached with short TTL)
- [x] `POST /api/orders` — Place B2B order to HO
- [x] `GET /api/orders` — Order history
- [x] `POST /api/pos/sale` — POS sale from mobile (Invalidates cache on success)

#### 8.2 Operational Tools
- [x] Meeting management (schedule, attendance, invites)
- [x] Shop visit audits (inspection, photos, checklists)
- [x] Support ticket system (Franchisee -> HO)
- [x] Franchisee feedback system
- [ ] Notice Board / Announcements
- [ ] SMS/WhatsApp notifications

#### 8.3 Legacy Data Migration
- [ ] Migration scripts for `pharmaer_pharmaerp` → `brainyug_erp`
- [ ] Migration scripts for `genericp_franchisee` → `brainyug_erp`
- [ ] Data validation and reconciliation
- [ ] Parallel run verification

---

## VERIFICATION PLAN

### Per-Phase Testing
- Run `php artisan test` after each migration
- Verify foreign key constraints
- Test RBAC: each role sees only what they should
- Test stock isolation: Franchisee A cannot see Franchisee B's stock

### Integration Testing
- Full order lifecycle: Cart → Order → Approve → Dispatch → Receive → POS Sale
- Financial reconciliation: All ledger entries balance
- GST accuracy: Tax calculations match manual verification
- Mobile API: All endpoints return correct data per user role

### Browser Testing
- Desktop + Tablet + Mobile responsive
- Dark mode consistency
- Print invoice formatting
