# Procurement Parity Spec Sheet

Date: 2026-04-07
Scope: Legacy purchase lifecycle parity for enterprise rollout

## Stage 1: Entry (Draft Capture)
- Legacy contract: Draft save records supplier invoice metadata and line items, but no stock or supplier ledger movement.
- New ERP contract: Purchase Invoice save creates or updates draft only.
- Acceptance checks:
  - Draft status remains `draft`.
  - Inventory batch quantity is unchanged after draft save.
  - Supplier account statement has no payable posting for draft.
- Current status: PASS

## Stage 2: Approval (Maker-Checker)
- Legacy contract: Approval is explicit and is the business event that posts inventory and payable.
- New ERP contract: Approve action transitions `draft -> approved` and performs posting atomically.
- Acceptance checks:
  - Status transition succeeds once.
  - Stock batch rows are posted once.
  - Ledger entries are posted once and tied to source reference.
  - Re-approval is blocked/idempotent.
- Current status: PASS

## Stage 3: Financial Year and Numbering Integrity
- Legacy contract: Fiscal-year scope is based on document date, not system date.
- New ERP contract: Invoice FY and duplicate checks derive from `invoice_date`; return FY derives from `return_date`.
- Acceptance checks:
  - FY for 31-Mar and 01-Apr documents resolves correctly.
  - Duplicate supplier invoice checks are applied inside the correct FY bucket.
  - Number sequencing follows date-derived FY.
- Current status: PASS

## Stage 4: Payment Settlement Visibility
- Legacy contract: Users can audit invoice liability, paid amount, and pending amount from invoice context.
- New ERP contract: Invoice show page exposes net payable, paid allocations, outstanding, overdue, and allocation trail.
- Acceptance checks:
  - Net payable is visible.
  - Total allocated amount is visible.
  - Outstanding and overdue status are visible.
  - Allocation trail references payment records.
- Current status: PASS

## Stage 5: Purchase Return Lifecycle
- Legacy contract: Returns are created against approved procurements and reduce stock/payable only on return approval.
- New ERP contract: Return creation validates available quantity; approval posts reversal movement and financial effects.
- Acceptance checks:
  - Return quantity cannot exceed available quantity.
  - Return approval reduces stock for returned batches.
  - Return postings are reference-linked to source invoice/return.
- Current status: PASS

## Stage 6: Return Reversal and Idempotency
- Legacy contract: Reversing return effects restores balances exactly once.
- New ERP contract: Return reverse/cancel path restores prior effects without double posting.
- Acceptance checks:
  - Reversal restores stock to pre-return level.
  - Financial reversal neutralizes prior return posting.
  - Duplicate reversal attempts are blocked.
- Current status: PASS

## Stage 7: Import and Bulk Operations
- Legacy contract: Operational teams use bulk entry for high-volume invoice intake.
- New ERP contract: CSV template + CSV import endpoint for purchase invoices.
- Acceptance checks:
  - Template download is available.
  - CSV upload validates required fields and row-level format.
  - Import errors are surfaced in UI.
- Current status: PASS

## Stage 8: Operator Clarity and Safety Messaging
- Legacy contract: Users understand draft is non-posting and approval is posting event.
- New ERP contract: Explicit guidance appears on purchase invoice create and list screens.
- Acceptance checks:
  - Create/edit page states draft does not post stock/ledger.
  - List page states only approved invoices impact stock/ledger.
- Current status: PASS

## Ordered Fix Strategy (Already Applied)
1. Financial correctness (FY derivation, duplicate scope)
2. Posting visibility (settlement and allocation trail)
3. Throughput enablement (CSV template/import)
4. Operator safety (stock posting guidance in UI)
5. Verification artifacts (this spec + scenario matrix)

## Automation Gate (Post Sign-off)
- Do not automate beyond pilot until all scenario checks in the scenario matrix are PASS.
- Convert each scenario to regression tests in this order:
  1. FY/duplicate checks
  2. Draft vs approved posting behavior
  3. Return and reversal idempotency
  4. Settlement rendering and reconciliation
  5. CSV import validation and error handling
