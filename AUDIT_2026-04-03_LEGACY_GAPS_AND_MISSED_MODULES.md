# Audit Note - Legacy Gap Check
Date: 2026-04-03
Scope: Legacy parity check focused on multi purchase return and other major potentially missed modules.

## 1) Snapshot From Current Rebuild State (for quick reference)
- Purchase return module exists in rebuild with routes, controller, UI, export, approval, reverse flow.
- Reports stack exists with stock, GST (GSTR1/2/3B), BI, vendor outstanding, commissions.
- Known open blocker from previous audit still applies: `stockNonMoving()` uses collection pagination incorrectly.

## 2) Multi Purchase Return - Legacy vs Rebuild

### Legacy evidence (pharmaerp)
- DB has dedicated multi-return header/detail tables:
  - `multi_return_vendor`
  - `multi_return_purchase_product`
  - Evidence in SQL dump around table definitions:
    - `pharmaer_pharmaerp.sql` lines ~10496 onward
- Legacy controller has explicit multi-return workflow:
  - `Purchase_challan::multi_purchase_return()` renders the screen
  - `Purchase_challan::return_multi_purchase_challan()` accepts array payloads and inserts header + multiple item rows
  - It also decrements stock directly per item/batch
  - Evidence: `html_backuppharmaerp/application/controllers/Purchase_challan.php` around lines 627-770
- Legacy menu exposes separate navigation entry:
  - `Multiple Purchase Return`
  - Evidence: `html_backuppharmaerp/application/views/header/sidebar_menu2.php` around lines 831-834

### Rebuild status
- Rebuild has a solid purchase return implementation, but model is single-return document with optional single linked invoice:
  - `purchase_returns.purchase_invoice_id` (single FK)
  - Evidence: `database/migrations/2026_03_07_140400_create_purchase_returns_table.php`
- Controller validates linked return items against one invoice context:
  - `validateInvoiceLinkedReturnItems($linkedInvoice, $validated['items'])`
  - Evidence: `app/Http/Controllers/Admin/PurchaseReturnController.php` around lines 164-178

### Gap conclusion
- Not fully missing, but parity gap is real:
  - Rebuild supports purchase returns well.
  - Legacy had a distinct "multiple purchase return" style workflow with header + multiple return lines that could be sourced from broader contexts.
  - Rebuild currently enforces one return document context with optional single invoice linkage, not an explicit multi-source debit-note workflow.

## 3) Other Major Legacy Items Potentially Missed

### A) Purchase Order module
- Legacy menu has direct `purchase_order` entry.
- Rebuild route/controller list currently has purchase invoices and purchase returns, but no dedicated purchase order route/controller/UI found in `routes/web.php` and current controller inventory.
- Risk: procurement planning and pre-invoice controls may be missing.

### B) E-waybill and TDS report surfaces
- Legacy docs explicitly list GST R1/R2/R3 + TDS + E-waybill reports.
- Rebuild `reports` routes currently expose stock/GST/BI/vendor outstanding/commissions but no explicit TDS or E-waybill endpoints.
- Risk: compliance/reporting scope mismatch versus legacy ops expectation.

### C) Email Templates (operational template management)
- Legacy menu has dedicated `Email Templates` module.
- Rebuild has announcements/tickets but no explicit email template management module found during route/controller scan.
- Risk: communication automation parity gap.

### D) Expense Management depth mismatch
- Legacy menu shows category + expense list + TDS payment shape.
- Rebuild currently exposes `expenses` resource with limited actions (`index`, `create`, `store`) and may not cover full legacy depth.
- Risk: finance workflow gaps if teams relied on category/TDS-specific flows.

### E) Excise Details / Notes / Settings-era utility modules
- Legacy contains explicit entries for excise details, notes, and broader settings utilities.
- Rebuild coverage for these specific modules is not visible as named modules/routes.
- Risk: edge operational tasks still handled outside rebuild.

## 4) Priority Recommendation
1. Close P0 report runtime blocker first (`stockNonMoving` pagination misuse).
2. Decide target behavior for purchase returns:
   - Keep current single-invoice-linked approach only, or
   - Add explicit multi-source/multi-invoice debit-note workflow.
3. Confirm compliance scope with business owner:
   - If TDS and E-waybill are mandatory, add routes/controllers/exports next.
4. Run one more legacy-to-rebuild parity pass focused on:
   - Purchase Order,
   - Email Templates,
   - Expense/TDS flows,
   - Excise/utility modules.

## 5) Practical note
This file is intentionally date-stamped so future audits can be added as separate files and compared quickly without overwriting historical findings.
