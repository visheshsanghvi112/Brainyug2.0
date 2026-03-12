# Legacy System Deep Knowledge Base
> Built from reading every sidebar menu (3817 lines), every key controller, model, and business flow.

---

## 1. COMPLETE USER & ROLE SYSTEM

### How Roles Work
- **Table:** `erp_rolemaster` — Stores role names (Admin, State Head, etc.) with `id`, `role`, `is_active`
- **Table:** `erp_modules` — All module definitions (`mod_id`, `mod_name`)
- **Table:** `access_rights` — Per-role permissions per module: `ac_user` (=role_id), `ac_module`, `ac_modulename`, `ac_view`, `ac_insert`, `ac_update`, `ac_delete`

### Role Creation Flow (`Rolemaster.php`)
```
Admin creates role → INSERT erp_rolemaster (name)
  → Auto-seed ALL modules into access_rights with 0/0/0/0 (view/insert/update/delete)
  → Admin then toggles each permission checkbox via AJAX
  → access_rights row is DELETE + re-INSERT per module change
```

### Permission Enforcement (TWO layers)
1. **Sidebar:** `get_rolewiseper($typ)` loads ALL modules where `ac_view=1` for that role → sidebar conditionally renders menu items
2. **Controller:** Every controller constructor calls `getrights($typ, $controller_name)` → checks `ac_view`, `ac_insert`, `ac_update`, `ac_delete` before allowing action

### SQL for Permission Loading
```sql
-- Sidebar (loads all visible modules for role)
SELECT am.mod_name, ar.ac_modulename, ar.ac_insert, ar.ac_view, ep.role
FROM access_rights ar
LEFT JOIN erp_modules am ON am.mod_id = ar.ac_module
INNER JOIN erp_rolemaster ep ON ep.id = ar.ac_user
WHERE ar.ac_user = '{role_id}' AND ar.ac_view = 1

-- Controller (checks specific module access)
SELECT * FROM access_rights WHERE ac_user = '{role_id}' AND ac_modulename = '{controller_name}'
```

### Routing / Auth Reality Discovered From Legacy Controllers
- Route files expose many direct business-action endpoints instead of stable module routes. Examples found in FMS routes: `getCustOfMobNo`, `submitDataAndGetReciept`, `submitCreditAmountInfo`, `generateStockReport`, `submitConfirmPaymentInfo`.
- Permission checks are usually tied to controller class names, not to specific actions. `getrights($typ, $this->router->fetch_class())` means one module permission often gates an entire controller surface.
- In FMS, several controller AJAX/action methods do not perform their own auth or permission checks. Example pattern in `Sales_Management.php`: the page-rendering methods check login, but business endpoints like `getCustOfMobNo`, `getMedicineInfoByID`, `submitCustInfo`, `checkQtyAvailbleOrNot` are callable methods without local guards.
- In PharmaERP, many controllers repeat the same pattern manually: check login, load `per_modal`, load `rights`, then decide whether to render the page or show the access view.
- Legacy auth is split into two systems:
   - Tank Auth for session login, logout, forgot/reset password, activation, captcha, remember-me.
   - Custom role/permission checks via `access_rights` and numeric `users.type` checks.
- PharmaERP login includes custom password migration behavior during login. It fetches the stored password, checks whether length is 60, and silently rewrites it if not. This proves login contained data-repair behavior, not just authentication.

### Navigation / UI Architecture Mistakes Learned From Legacy
- Sidebar visibility is treated as a security layer, but it is only a presentation layer. The same permission logic is duplicated again in controllers.
- Views execute live database queries. Example in FMS sidebar: franchise activation status is fetched directly inside the sidebar view before deciding what to render.
- Sidebar files are very large, repetitive, and brittle. The same `foreach ($per_modal ...)` pattern is repeated dozens of times for individual menu items.
- Multiple sidebar variants exist for the same product line (`sidebar_menu.php`, `sidebar_menu2.php`, `sidebar_menu2_TUSH.php`), which means navigation behavior drifted over time.
- Hardcoded numeric user-type exceptions are mixed with role permissions. Example patterns include `if ($type == 5)`, `if ($type == 10)`, and module checks in the same view.
- Business exceptions were hardcoded directly into the UI. Example in FMS sidebar: franchise menu gating was effectively overridden by setting `$franch_status_menu = 1` in the view.
- There are many dead or half-removed menu items and commented links, which indicates the navigation was maintained by patching markup rather than by maintaining a canonical module registry.
- Naming and labels drift badly: `frachise`, `Distributer`, `Permisssion`, mixed controller casing, mixed singular/plural labels.
- Some links open workflows in new tabs (`target="blank"`) without a clear product reason, which fragments the operating experience.
- Auth UI is visually upgraded in places, but still mixes inline CSS, inline JS, alert-box validation, external CDN assets, and direct asset links to non-product pages.

### Hidden Or Less-Obvious Legacy Surfaces Revealed By Routing
- FMS routing and controllers reveal additional operational areas beyond the obvious dashboard/report flows: support tickets, shop visits, meeting/activity tracking, feedback, courier master, audit stock report, printer settings, company software, invoice list, new-arrived-products cart, franchise users, and password update.
- PharmaERP routing and controllers reveal internal modules beyond purchasing and product masters: transport details, expense/TDS payment, GSTR1/GSTR2 exports, email/SMS, notes, cheque handling, transaction management, expiry, company discounts, and ledger/account structures.
- This means the legacy systems were not just order-entry apps. They were fragmented ERP suites with many small operational modules added over time.

---

## 2. USER CREATION & HIERARCHY

### How Users Are Created (`Register.php → registration()`)
```
Admin creates user → tank_auth->create_user(parent_id, type, username, email, mobile, fullname, state, district, password)
  → INSERT into `users` table with parent_id = creator's user_id
  → THEN based on who's creating:
     If Admin (type=1) creating State Head:
       → INSERT tbl_state_head (stateh_user_id, stateh_statecode)
     If State Head (type=2/8) creating Zone Head:
       → INSERT tbl_master_head (masterh_user_id, masterh_statehead_id, masterh_stateh_id, masterh_districtcode)
       → Districts stored as CSV string! e.g. "12,15,23"
     If Zone Head (type=3) creating District Head:
       → INSERT tbl_district_head (districth_user_id, districth_statecode, districth_districtcode, district_regionalh_id)
```

### The Parent Chain
```
Admin (id=1)
  └── State Head (id=5, parent_id=1, statecode=27)
       └── Zone Head (id=12, parent_id=5, districtcode="12,15,23")
            └── District Head (id=20, parent_id=12, districtcode="12")
                 └── Franchisee (id=35, parent_id=20, franch_id=7)
```

### Hierarchy Query Patterns (Home_model.php → total_order)
| User Type | How Data is Scoped |
|-----------|-------------------|
| Admin (1) | No filter — sees ALL orders |
| State Head (2) | `JOIN users u ON order_user_id = u.id` + `JOIN users s ON u.statecode = s.statecode WHERE s.id = {user_id}` |
| Sister Head (8) | `JOIN users t1 ON t1.id = u.parent_id` + `JOIN users t2 ON t2.id = t1.parent_id WHERE t2.parent_id = {user_id}` (3 levels deep!) |
| Zone Head (3) | `JOIN users t1 ON t1.id = u.parent_id WHERE t1.parent_id = {user_id}` (2 levels deep) |
| District Head (4) | `WHERE u.parent_id = {user_id}` (direct children) |
| Franchisee (5) | `WHERE order_user_id = {user_id}` (own orders only) |

### ⚠️ Legacy Flaw: CSV-stored district codes
Districts are stored as comma-separated strings (e.g., `"12,15,23"`) and queried with `FIND_IN_SET()`. This is fragile and prevents proper foreign key relationships.

**New system fix:** Use a proper pivot table `territory_assignments(user_id, district_id)` with proper FK constraints.

---

## 3. FRANCHISEE ONBOARDING

### Full Flow
```
1. PUBLIC REGISTRATION FORM (Franchaisee_res.php → index/insert)
   → Visitor fills 30+ field form (no login required)
   → Collects: owner name, partner name, shop name, type, address,
     state/district/city, pincode, email, mobile, WhatsApp,
     DOB, age, education, occupation, residence details,
     investment readiness, DL numbers (3 fields), bank details,
     UTR no, transaction date, address proof, ID proof (file upload)
   → INSERTS into tbl_franchisee (franch_status defaults to pending)

2. ENQUIRY FLOW (separate simpler form)
   → Less fields, sets franch_status = 3 (enquiry)

3. ADMIN REVIEWS (get_franchisee_res → Registered List)
   → Admin sees pending franchisees
   → Approve / Reject

4. SHOP ACTIVATION (Activate_shop.php)
   → Admin sets tbl_franchisee.franch_status_menu = 1
   → Records activated_date
   → THIS GATES THE SIDEBAR: If franch_status_menu ≠ 1, franchise users
     see NOTHING (no Add Product, no Purchase, no Sales, no Reports)
   → Can also DEACTIVATE → sets franch_status_menu = 0

5. USER ACCOUNT CREATION (Register.php)
   → After approval, Admin/DH creates a login account
   → Links user to franch_id
   → Franchisee can now login and see their scoped panel
```

### Key `tbl_franchisee` Fields (30+ columns)
```
franch_id, franch_owner_name, label, franch_partner_name, partner_label,
franch_type, franch_shop_name, franch_shopcode, franch_address,
franch_state_id, franch_district_id, franch_city_id, franch_other_city,
franch_pincode, franch_email, franch_mobile_no, franch_whatsno,
franch_res_address, franch_dob, franch_age, franch_education,
franch_ownoccupation, franch_residence_from, franch_distance,
ready_toinvest_status, franch_utr, franch_dlno_first, franch_dlno_second,
franch_dlno_third, bank_name, transaction_date, holder_name, amount,
address_proof, id_proof, franch_status, franch_status_menu,
franch_created_date, activated_date, deactivated_date
```

---

## 4. INVENTORY MANAGEMENT (THE CORE)

### How Stock is Tracked
- **Table:** `tbl_stock` — Key: (`franch_id`, `product_id`, `batch_no`)
- **Fields:** `exp_date`, `mfg_date`, `csr`, `mrp_rate`, `rack_section_id`, `rack_area_id`, `min_qty`, `max_qty`, `alert_stock`, `actual_stock`

### Purchase Challan Flow (Purchase_challan.php → insert_puchase_challan)
```
1. VENDOR HEADER → INSERT purchase_challan_vendor
   Fields: franch_id, vendor_id, pur_date, puchase_entry_no, party_no, bill_date, tax_type
   Returns: last_id (vendor_table_id)

   DUPLICATE CHECK: Before insert, checks if same (franch_id + vendor_id + entry_no)
   exists within the same financial year (April-March cycle)

2. FOR EACH LINE ITEM:
   a) Resolve product: get_productIdByName(name, franch_id) → pro_id
   b) Get product detail: hsn_sac, sgst, cgst, igst
   c) Calculate GST:
      tax_type=2 (LOCAL/INTRA-STATE) → SGST=product.sgst, CGST=product.cgst, IGST=0
      tax_type=3 (INTER-STATE) → SGST=0, CGST=0, IGST=product.igst
   d) INSERT purchase_challan_product
      Fields: vendor_table_id, pro_id, pack, batch, hsn_code, sgst, cgst, igst,
              expiry_date, mfg_company, mkt_company, mfg_date, mrp, csr,
              product_quantity, free, purchase_rate, discount, discountrs, amount, barcode
   e) INSERT tbl_tax entries (DR side)
      For LOCAL: 2 rows (SGST + CGST, each = gst_value/2)
      For INTER: 1 row (IGST = full gst_value)
   f) UPSERT tbl_stock:
      → Check if (franch_id, product_id) exists
        → If exists with empty batch and 0 stock: UPDATE with batch details
        → If exists with matching batch: ADD to actual_stock
        → If exists but different batch: INSERT new stock row
        → If no record: INSERT new stock row
      → actual_stock = product_quantity + free_qty
```

### Stock Update Management (Stock_Update_Management.php)
```
Franchisee can manually adjust:
  - rack_section_id, rack_area_id (warehouse location)
  - csr (customer selling rate)
  - min_qty, max_qty (reorder levels)
  - alert_stock (low stock threshold)
  - actual_stock (physical count — DANGEROUS: direct override!)
Via AJAX: getDrugStockInfoByDrugID → getStockBatchInfo → updateDrugStockInfoByDrugID
```

### ⚠️ Legacy Flaw: Direct stock manipulation
Old system allows direct `actual_stock` edits with NO audit trail. Stock discrepancies are impossible to trace.

**New system fix:** `inventory_ledgers` table — every stock change is an immutable ledger entry. Stock = SUM(qty_in) - SUM(qty_out). No direct edits.

---

## 5. COMPLETE SCREEN MAP BY USER ROLE

### ALL USERS (permission-gated via `per_modal`)
| Section | Screen | Controller | What It Does |
|---------|--------|-----------|-------------|
| Dashboard | Home | `Home.php` | Role-morphing: Type 5→`home_franchise`, Type 6→`home_distributor`, else→`home_admin` |
| Masters → Permission | Role Master | `Rolemaster.php` | Admin-only. CRUD module-level permissions per role |
| Masters → User Mgmt | Register | `Register.php` | Create/manage users with role + hierarchy |
| Masters → District | Add District | `District.php` | CRUD districts linked to states |
| Masters → City | Add City | `Add_city.php` | CRUD cities linked to districts |
| Masters → Bank Master | Bank Master | `Bank_master.php` | Bank accounts for transactions |
| Masters → Rack Section | Rack Section | `Rack_section.php` | Warehouse rack locations |
| Masters → Rack Area | Rack Section Area | `Rack_section.php` | Sub-areas within racks |
| Masters → Company | Company Master | `Company_master.php` | Drug manufacturer companies |
| Masters → Salt | Salt Master | `Salt_master.php` | Drug compositions/ingredients |
| Masters → HSN | HSN Master | `Hsn_master.php` | GST HSN codes with tax rates |
| Masters → TDS | TDS Master | `Tds.php` | Admin-only. TDS deduction rules |
| Masters → Transaction | Transaction Master | `Transaction_Master.php` | Admin-only. Transaction type config |
| Masters → Settings | Franchisee Settings | `Printer_Setting_Management.php` | Per-franchise config |
| Add Product | Add/List Products | `Add_new_product.php` | Product CRUD with pricing, HSN, salt, company |
| Add Vendor | New Ledger | `New_ledger.php` | Supplier/vendor CRUD |
| Purchase Challan | Purchase Challan | `Purchase_challan.php` | Buy from suppliers with batch/expiry/GST |
| Sales | Sale V2 | `Sales_Management.php` | POS billing (franchisee retail) |
| Sales | Update/Manage Stock | `Stock_Update_Management.php` | Manual stock corrections |
| Orders | Place/My/Return/Pending | Multiple | Full B2B order lifecycle |
| Meeting | Add/List | `Meeting_management.php` | Schedule + view meetings |
| Accounts | Cash/Deposit/Withdrawal/Expense | `Transaction.php`, `Expence.php` | Financial transactions |
| Activity | Add/List | `Activity_management.php` | Field activity logging |
| Tickets | Generate/Support | Multiple | Support ticket flow |
| Shop Visit | Pre/Post/Reports | `Shop_visit.php` | Field inspection workflow |

### FRANCHISEE-ONLY (type == 5)
Franchisee Users, Search Products, Bank Info, Feedback, **11 Reports** (Drug, Purchase, Sales, Stock, Expiry, Customer, GSTR Sales/Purchase, Quotation, Credit, Audit), Expenses

### DISTRIBUTOR-ONLY (type == 6)
Welcome Stock, Courier Master, Commission/Stock/Product Reports, Accept Payment, Invoice List, Update Password, Return Report, Credit Note Report

---

## 6. PHARMAERP HO-ONLY SCREENS (Manufacturer Side)
Masters (Balancing Method, Category/SubCategory, Ledger Type, Account Group, Tax Type), Product Master (Item Type, Color), Account (Bank Deposit, Credit/Debit Note, Expenses), Reports (GST R1/R2/R3, TDS, E-waybill, Export, Stock, Discontinued/Coming/Product/Vendor Reports), Purchase Bill, Multiple Purchase Return, Purchase Order, Excise Details, Expiry, Notes, Transport Details, Company Discount, Commission, Email Templates, Expense Management, Settings, Bio Data, Merging Form

---

## 7. KEY USER TYPES

| Type | Role | Dashboard | Hierarchy Scope |
|------|------|-----------|----------------|
| 1 | Super Admin | `home_admin` — All KPIs | Everything — no scope filter |
| 2 | State Head | `home_admin` — State-scoped | JOIN on `statecode` |
| 3 | Zone Head | `home_admin` — Zone-scoped | 2-level parent_id chain |
| 4 | District Head | `home_admin` — District-scoped | Direct `parent_id` match |
| 5 | Franchisee | `home_franchise` — Local ops | Own data only via `user_id` / `franch_id` |
| 6 | Distributor | `home_distributor` — Supply side | Own data + accept payments |
| 8 | Sister Head | `home_admin` — Sub-zone | 3-level parent_id chain |
| 10 | Sales Staff | Sales link only | Sales screen in new tab |

---

## 8. POS BILLING FLOW (Sales_Management.php — 1224 lines)

### How a Retail Sale Works
```
1. Cashier selects customer (by mobile) → getCustOfMobNo (AJAX)
2. Searches medicines → getMedicineInfoByID (returns from franchisee's tbl_stock)
3. Selects batch → getMedicineBatchInfo (returns exp, mrp, qty available)
4. Checks stock → checkQtyAvailbleOrNot
5. Submits sale → submitDataAndGetReciept:

   a) INSERT client_sale_info (bill header):
      cust_bill_no, franch_id, user_id, cust_id, dr_id, dateTime,
      total_amount, total_dis_amount, discount, other_charges
      → Returns custId (sale ID)

   b) FOR EACH LINE ITEM → INSERT sale_info:
      drug_id, batch_no, exp_date, drug_qty, price, discount

   c) STOCK DEDUCTION (per item):
      actual_stock = old_stock - (qty / conversion_factor)
      → Direct UPDATE tbl_stock SET actual_stock = new_value

   d) PAYMENT RECORDING (6 modes!):
      cash → INSERT tbl_cash
      bank → INSERT tbl_bank (with transaction_no, type)
      credit → INSERT tbl_credit_payment (paid=0, credit=full)
      cashCredit → INSERT tbl_cash + tbl_credit_payment (split)
      bankCredit → INSERT tbl_bank + tbl_credit_payment (split)
      cashBank → INSERT tbl_cash + tbl_bank (split)

   e) TAX ENTRIES (always intra-state retail):
      → 2 rows in tbl_tax: SGST (CR) + CGST (CR), each = taxAmount/2
```

### Key Fields in sale_info
`cust_bill_no, franch_id, user_id, cust_sale_id, drug_id, batch_no, exp_date, drug_qty, price, discount`

### Conversion Factor
Some products sell in sub-units (e.g., strips from a box of 10). The `conversion` field divides the entered qty to get actual stock units deducted.

---

## 9. B2B ORDER LIFECYCLE (Dist_order.php — 4236 lines, 53 methods)

### Order Status Codes
| Status | Meaning |
|--------|--------|
| 0 | Pending (just placed by franchisee) |
| 1 | Accepted (HO approved, stock deducted) |
| 2 | Rejected (item-level, not visible in lists) |
| 3 | Dispatched (shipped to franchisee) |

### Order Acceptance Flow (ordereraccept_order)
```
HO Admin clicks "Accept" on an order:
  1. Updates tbl_order_group: status=1, sets total_amount, discount_percent,
     total_discountedamt_withgst
  2. FOR EACH ORDER ITEM (where status != 2/rejected):
     a) Get FMS product ID + batch
     b) DEDUCT FROM FMS STOCK: get_stock(pro_id, batch) → subtract (qty + free)
        → update_fms_stock
     c) Get pharma_pro_id (cross-reference to PharmaERP product)
     d) DEDUCT FROM PHARMA STOCK: get_pharmastock(fms_id, batch, pharma_id)
        → subtract from PharmaERP tbl_stock too!
     e) INSERT tbl_balance: debit note entry for audit trail
```

### ⚠️ CRITICAL: Dual Stock Deduction on Accept
On order acceptance, legacy code performed stock deduction from TWO separate databases:
- `genericp_franchisee.tbl_stock` (FMS — the "warehouse" stock)
- `pharmaer_pharmaerp.tbl_stock` (PharmaERP — the manufacturer's stock)
This cross-database operation was not wrapped in a single global transaction, leading to frequent "ghost stock" where one DB was updated and the other failed.

**New system fix:** The `InventoryService` uses a unified `inventory_ledgers` table. A single `DB::transaction` in `recordDispatch()` records both the "Warehouse OUT" and "Franchisee IN" entries. No cross-database synchronization is required, ensuring atomic consistency.

---

### ⚠️ Legacy Flaw: Fragmented Hierarchy Logic
Commissions were calculated using hardcoded checks for `user_type` (1, 2, 3, 4, 8). If a new hierarchy level was added, hundreds of lines of code in `Dist_order.php` had to be modified.

**New system fix:** The `CommissionService` uses a **Recursive Hierarchy Traversal**. It simply follows the `parent_id` chain until it hits the Super Admin. This allows for infinitely nested management levels (Regional Heads, Zone Heads, etc.) without changing a single line of business logic.

### Bill Edit Locking
- `start_editing($order_id)` → SETs a lock flag in `bill_edit_locks`
- `check_lock($order_id)` → Returns if another user is currently editing
- Prevents concurrent edits but is NOT optimistic locking — no version numbers

### Order View Scoping (same hierarchy pattern as everywhere)
- Type 6 (Distributor): Sees all orders (status 0 or 1), limit 1500, uses `tbl_order_group`
- Type 4 (DH): Scoped by `districtcode` match (one level)
- Type 3 (ZH): Scoped by `FIND_IN_SET(districtcode)` (CSV districts)
- Type 2 (SH): Scoped by `statecode` match
- Type 1 (Admin): Sees everything, joins product + company tables

### Invoice PDF Generation
- `generate_fullinvoice_pdf()` (358 lines!) builds complete GST-compliant invoice
- Includes: amount-in-words (`getIndianCurrency`), HSN summary, tax breakdowns
- `generateCSV()` for Excel export

---

## 10. LEGACY FLAWS TO FIX IN NEW ERP

| Flaw | Where | Fix |
|------|-------|-----|
| Districts stored as CSV strings | `users.districtcode = "12,15,23"` | Pivot table `territory_assignments` with proper FK |
| Direct stock manipulation | `tbl_stock.actual_stock` editable | Event-sourced `inventory_ledgers` — immutable entries |
| No audit trail on stock | Updates silent | `spatie/activitylog` on all models |
| `FIND_IN_SET` for district matching | `Home_model.new_registration()` | Proper JOIN on pivot table |
| Hardcoded user types | `if ($type == 5)` everywhere | Spatie roles/permissions — `$user->hasRole('franchisee')` |
| Mixed concerns in Home_model | 3770 lines, 121 methods | Service classes per domain (OrderService, StockService, etc.) |
| No optimistic locking | Orders can be edited by multiple users | Version field on orders |
| Financial year calc in controller | Business logic in controller | Service class `FinancialYearService` |
| GST split hardcoded | `if tax_type==2` then split | Config-driven GST engine respecting 2026 rates |
| No soft deletes | `banned=TRUE` for users | Laravel SoftDeletes trait everywhere |

---

## 11. FMS VS PHARMAERP: THE REAL SYSTEM BOUNDARY

### The Correct Mental Model
- **`genericp_franchisee` (FMS)** was the **full operating system** for the network side.
- **`pharmaer_pharmaerp`** was the **internal HO/backoffice/manufacturer-side system**.
- The two DBs were cross-wired in code, but they did **not** carry the same meaning.

### What FMS Actually Covered
FMS was not just a franchise ordering portal. It acted as a **full franchise-side ERP** with:
- Dashboard
- Franchise users / sub-users
- Masters
- Product browsing
- Add vendor
- Purchase challan
- Sales / POS billing
- Orders
- Meeting / activity / tickets / feedback
- Reports / GST / expenses / bank info

### Why This Changes Migration Thinking
- The business-critical continuity is **franchise identity + user identity + role/hierarchy + GPM code continuity**.
- Old transactional history is already incomplete in practice because many real orders shifted to phone/manual flows.
- Therefore, **identity continuity matters more than full historical transaction parity**.

---

## 12. USER TYPE COLLISIONS ACROSS DATABASES

### CRITICAL WARNING
Legacy `users.type` values are **database-specific**. The same number means different things in FMS vs PharmaERP.

### FMS (`genericp_franchisee`) Role Map
| Type | Meaning |
|------|---------|
| 1 | Admin |
| 2 | State Head |
| 3 | Regional Head |
| 4 | District Head |
| 5 | Franchisee |
| 6 | Distributer |
| 7 | MR |
| 8 | Zonal Head |
| 9 | Account |
| 10 | Franchisee User |

### PharmaERP (`pharmaer_pharmaerp`) Role Map
| Type | Meaning |
|------|---------|
| 1 | Admin |
| 2 | State Head |
| 3 | Regional Head |
| 4 | District Head |
| 5 | Franchisee |
| 6 | Distributer |
| 7 | MR |
| 8 | Accountant |
| 9 | Order |
| 10 | Warehouse |
| 11 | Inward |
| 12 | Outward |
| 13 | Orderstaff |

### ⚠️ Migration Rule
Any migration keyed only on `users.type` without also checking the **source database** will be wrong.

**New system fix:** Make all user migration logic **source-aware** (`fms` vs `pharmaerp`) and map into explicit modern roles instead of preserving numeric type codes.

---

## 13. THE LEGACY `DISTRIBUTER` USER WAS AN INTERNAL OPERATIONS ROLE

### What The SQL Confirms
Both FMS and PharmaERP contain a dedicated user row:
```sql
(5, '6', 1, 0, 'distributer', '...', 'distributer@gmail.com', ..., 'abhi distributer', 27, ...)
```

### Business Meaning
- The name `Distributer` is misleading.
- This user was part of the **internal team**, not a supplier master record.
- In practice this role handled the **incoming franchise order desk**:
   - pending orders
   - accept / reject flow
   - dispatch-related visibility

### Migration Implication
- Do **not** treat this as an external distributor/vendor identity.
- Do **not** connect it to `suppliers` semantics.
- In the new ERP this is better modeled as **Sales Operations / Order Desk / HO Operations**.

**New system fix:** Keep the seeded role temporarily if needed for compatibility, but conceptually map old `Distributer` to an internal **order-operations role**, not to supplier management.

---

## 14. GPM CODE GENERATION WAS HARDCODED AND MAHARASHTRA-BIASED

### How Legacy Generated Shop Codes
In franchise approval:
```php
$franch_id = last_franchisee_shopcode_for_state_27;
$shopcode = substr($franch_id, -4);
$newshopcode = "GPMH" . ((int)$shopcode + 1);
```

### Problems
- `GPMH` was hardcoded in controller logic.
- The lookup for "last code" was hardcoded to **`franch_state_id = 27`**.
- `tbl_state` stored only `statecode` + `statename`; there was **no abbreviation field**.
- So state prefixes like `MH`, `KA`, `KL`, `GA`, `TS` were **not derived from schema**.

### Real-World Result
- Maharashtra dominated the codebase logic.
- Non-MH prefixes existed in data (`GPKA`, `GPKL`, `GPGA`, `GPTS`) but the generation logic itself was not properly generalized.

**New system fix:** Store state abbreviations explicitly and generate shop codes as:
`GP` + `{state_abbreviation}` + `{4-digit sequence}`
while preserving all existing legacy GPM codes exactly.

---

## 15. `create_new_ledger` WAS A MIXED ENTITY TABLE, NOT A SUPPLIER TABLE

### What Was In It
`create_new_ledger` mixed multiple business entities into one ID sequence:
- Franchisees / retailers
- Suppliers / creditors
- Field staff
- Expense vendors
- Misc other parties

### Key Pattern
- `account_group = 3` → franchise/retailer-side entities (many with embedded GPM codes in `ledger_name`)
- `account_group in (4,5,6,7)` → supplier/creditor/staff-side entities

### Example Consequence
If this table is imported blindly into `suppliers`, franchise GPM entities get inserted as vendors.
That is exactly what happened during early migration attempts.

### Legacy Data Quirk
The GPM code often lived only as part of `ledger_name`, e.g.:
```text
AADITYA S.A.S GPMH0136 NASHIK
```
There was no dedicated `gpm_code` column in `create_new_ledger`.

**New system fix:** Never use `create_new_ledger` as a direct 1:1 source for any single modern table. Treat it as a **staging source** and split records by business meaning before loading them.

---

## 16. FRANCHISEES WERE USING A FULL ERP, NOT A THIN PORTAL

### Why This Matters
The old franchise side was running real day-to-day operations:
- retail sales billing
- purchase entries
- stock handling
- vendor management
- GST reports
- franchise sub-users
- expenses and bank info

### Migration Implication
When replacing legacy, the goal is **not** just "preserve ordering".
The real continuity requirement is:
- preserve franchise identity
- preserve GPM code continuity
- preserve active users and staff
- preserve enough role-based ERP capability that franchises can actually operate

### Practical Launch Conclusion
- **Preserve:** franchise master, user identities, GPM codes, product masters, role hierarchy
- **Rebuild fresh:** transactional history, pending queues, old carts, broken stock states, old billing history unless explicitly needed
- **Archive only:** old system data for lookup/reference

**New system fix:** Think in 3 separate continuity layers:
1. **Identity continuity** — users, franchisees, GPM codes, hierarchy
2. **Operational continuity** — dashboards, order flow, billing/POS, permissions
3. **Historical continuity** — archive/reference only unless a hard business reason exists
