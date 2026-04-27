# Legacy Missing Priority Register (2026-04-07)

## Objective
Identify major legacy-critical surfaces still missing in the rebuild and start implementation from highest business risk items.

## Current Gap Register

| Priority | Legacy Surface | Why It Is Critical | Status in Rebuild | Action Taken Today |
|---|---|---|---|---|
| P0 | TDS Compliance Report | Tax deduction visibility, filing support, payout reconciliation | Missing as dedicated report | Implemented backend + UI + export + navigation |
| P0 | E-waybill Dispatch Report | Dispatch compliance tracking and missing document detection | Missing as dedicated report | Implemented backend + UI + export + navigation |
| P0 | Non-moving stock runtime reliability | Existing report had pagination bug risking runtime error | Bug present | Fixed query pagination flow |
| P1 | Purchase Order (HO procurement planning) | Legacy had separate PO layer before purchase invoice | Not implemented | Pending |
| P1 | Email Templates module | Legacy had configurable operational communication templates | Not implemented | Pending |
| P1 | Multi-source purchase return workflow | Legacy had multiple purchase return flow (header + multi-lines) | Partial parity only | Pending design decision |
| P2 | Expense/TDS operational depth | Legacy had deeper expense and TDS payment surfaces | Partial | Pending parity pass |
| P2 | Excise and utility modules | Legacy contained compliance and utility forms | Not implemented | Pending scope confirmation |

## Implemented in This Session
- Added reports.compliance.tds with filters, summary cards, pagination, CSV/Excel/PDF export.
- Added reports.compliance.ewaybill with compliance filters, missing e-waybill visibility, pagination, CSV/Excel/PDF export.
- Added report entries to sidebar for both compliance reports.
- Added route access mapping to module policy system for compliance report routes.
- Fixed stock non-moving report pagination path to avoid collection pagination misuse.

## Recommended Next Build Order
1. Purchase Order module (logic + UI)
2. Email Templates module (logic + UI)
3. Multi-source purchase return workflow
4. Expense/TDS workflow depth alignment
