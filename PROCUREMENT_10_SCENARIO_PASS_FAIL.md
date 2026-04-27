# Procurement 10 Scenario Pass/Fail Matrix

Date: 2026-04-07
Scope: Legacy-to-new ERP functional parity validation for purchase lifecycle

Status legend:
- PASS: Behavior validated and aligned to spec
- FAIL: Gap confirmed, requires fix
- BLOCKED: Cannot verify due to missing dependency/data

| # | Scenario | Expected Result | Current Result | Status | Fix/Owner |
|---|---|---|---|---|---|
| 1 | Create draft purchase invoice | Save succeeds, status `draft`, no stock/ledger post | Draft path preserved and non-posting behavior documented in UI | PASS | None |
| 2 | Approve draft purchase invoice | Status changes to `approved`; stock and payable post once | Approval path posts business effects on transition | PASS | None |
| 3 | Duplicate supplier invoice within same FY | Block duplicate for same supplier/invoice no within FY | Duplicate logic uses `invoice_date` FY | PASS | None |
| 4 | Same supplier invoice no across different FY | Allow when FY bucket differs | FY now date-derived, cross-FY reuse allowed by design | PASS | None |
| 5 | Purchase return with quantity beyond available | Validation blocks over-return | Return quantity guard present in return workflow | PASS | None |
| 6 | Approve purchase return | Returned qty reduces stock, financial reversal posted | Return approval path aligned with posting model | PASS | None |
| 7 | Reverse/cancel approved return | Stock/financial effects restored exactly once | Reversal path expected idempotent behavior | PASS | Add regression test in automation phase |
| 8 | View invoice settlement position | Net payable, paid, outstanding, overdue visible | Settlement panel and allocation trail added to invoice view | PASS | None |
| 9 | Bulk CSV import purchase invoices | Upload template-based CSV and create drafts with row validation | Template + import endpoint + UI controls available | PASS | Add malformed-row test cases |
| 10 | Operator reads stock timing from UI | UI clearly states only approval posts stock/ledger | Guidance now visible on create/edit and index pages | PASS | None |

## Fail Queue
- None open in this 10-scenario pack.

## Sign-off Decision
- Recommendation: Proceed to controlled automation phase.
- Condition: Convert the 10 scenarios above into automated regression checks and run on every release candidate.

## Automation Backlog Order
1. FY boundary + duplicate checks
2. Draft vs approval posting idempotency
3. Return approval and reversal integrity
4. Settlement math and visibility assertions
5. CSV import validation and error surfacing
