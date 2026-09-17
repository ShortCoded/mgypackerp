# MgyPack integrated ERP audit — living evidence

Date: 2026-09-17  
Current checkpoint: the three requested UI/workflow corrections, canonical navigation reorganization, expanded accounting/finance/costing reports, normalized analytical geography, and audited cross-module period close/reopen.

This file is evidence of implemented and tested slices. It is **not** a claim that the complete ERP acceptance request or production-readiness gate has passed. The open items near the end remain binding.

## Source-of-truth decisions confirmed in code

- Accounting reports read approved posted, non-deleted `journal_entries` and `journal_entry_lines`; they use `entry_date`, not record timestamps.
- Base-currency reporting uses each journal header's historical `exchange_rate` and BCMath decimal arithmetic. It does not use today's rate.
- Company, branch, financial-period, cost-center, permission, translation, soft-delete, and `doc_num` boundaries remain intact.
- The current account tree and account-classification registry remain the classification source. No parallel chart or report-only account mapping table was introduced.
- Financial statements and trial balance include historically inactive or soft-deleted accounts when posted movement exists.
- Opening balances are derived once from posted history before the requested date. They are not added again from editable balance fields.
- Reports are read-only. Opening a report does not create an adjustment or change an account, document, or journal.

## Requested screen corrections

| Area | Implemented behavior | Evidence |
|---|---|---|
| Quotations | Line discount, tax, line total, document discount, totals, edit/view/print consistency use the current quotation calculation path. | `QuotationTest` and the sales browser scenario cover the corrected fields and reload/print path. |
| Sales requests | Quick actions are permission- and status-aware and are re-bound after DataTables redraw. | `SalesCycleIndexUxTest`, `sales-index.js`, and sales-cycle feature coverage. |
| Purchase invoices | Due dates and payment-source fields are bound to the correct schedule/payment records; historical soft-deleted item references remain readable and validation errors target their real fields. | `ProcurementUiNormalizationTest`, `ProcurementSubmissionTest`, and the procurement-cycle suite. |

These corrections were already present in the working baseline commit and were preserved while the later report and navigation work was applied.

## Analytical dimensions and data-readiness evidence

- Customer and supplier master-data reports now use the normalized country → governorate → city → area relationships, with dependent selectors that remain usable by report-only roles. They also expose explicit completeness buckets: complete, any issue, missing normalized location, missing address, missing contact, and legacy unlinked location.
- Customer-based sales analyses apply the same normalized geography filters to orders, invoices, quotations, receipts, returns, pricing gaps, and the customer ledger. Supplier-based procurement analyses and their print/export paths apply the same filters without bypassing price visibility rules.
- These filters constrain company-scoped queries and preserve the selected values in the relevant CSV/XLSX/PDF or print path. They do not manufacture missing geography or silently map legacy free text to a normalized record.
- The implemented readiness view currently covers customer and supplier identity/location/contact completeness. A broader read-only operational-data readiness matrix for opening balances, every posting-account mapping, cash/bank opening positions, and payroll setup remains open.

## Canonical navigation

One canonical tree now drives desktop/mobile menu rendering, navigation search, active state, and breadcrumbs.

- Sales, purchases, inventory, and HR expose their available working screens directly, in process order, followed by one report group. The former artificial data/workflow nesting is no longer part of the canonical tree.
- Production has exactly three management children: production management, quality, and maintenance. Each exposes its working screens directly and ends with its own reports.
- Finance is no longer a top-level root. It is nested under accounting and costing with its treasury/bank/cheque operations followed by finance reports. A finance-only role still sees and opens that permitted descendant without needing an accounting permission.
- Fixed assets remain nested under accounting and costing without moving their models, routes, or account relationships.
- General accounting contains the financial-period list and the dedicated period close/carry-forward workspace. Every operating domain ends with its report group; the general Reports area remains an index of the same routes, not a second implementation.
- Empty permission-pruned groups disappear without hiding permitted descendants.
- Existing route names and legacy links remain resolvable.

Navigation evidence at this checkpoint: the expanded PHP menu/navigation/permission suite passed 182 tests with 7,685 assertions, plus 12 navigation/PWA JavaScript tests passed.

## Accounting report matrix

| Report | Measurement and official source | Modes / columns | Formula and reconciliation | Filters, drill, export | Status |
|---|---|---|---|---|---|
| Account ledger | Posted journal lines for one account; opening is all eligible history before `from_date`; movement is inclusive range. | Opening D/C, every movement D/C, running D/C, period totals, ending D/C; stable order by accounting date, document number, line number, line id. | Running signed balance = prior signed balance + debit − credit. Ending matches the same account/date/dimensions in trial balance. | Company context; period or all periods; branch and cost center where meaningful; journal drill; PDF/CSV/Excel. | Implemented and covered. The current web result is not server-paginated and remains a large-data improvement item. |
| Customer statement | Same canonical ledger for the customer's linked receivable account; independent customer document relationship identifies the subledger subject. | Prior movements, current movements, collection employee/source where available, running and ending balance. | Ledger balance for the linked account. Separate aggregate customer-control reconciliation is still open. | Historical dates, PDF/CSV/Excel. | Implemented; aggregate reconciliation matrix remains open. |
| Supplier statement | Same canonical ledger for the supplier's linked payable account. | Prior/current movement and running/ending balance. | Ledger balance for linked account. Separate aggregate supplier-control reconciliation is still open. | Historical dates, PDF/CSV/Excel. | Implemented; aggregate reconciliation matrix remains open. |
| Trial balance | Posted journal lines only. Opening is history before range; period totals are movements in range; cumulative totals are all movement through the end date. | Independent value axis: totals / balances / combined. Independent display axis: selected-level aggregate / direct-account detail / expandable tree. Optional zero accounts. | `O = prior debit − prior credit`; `E = O + D − C`; split debit/credit with `max`. Group presentation retains gross child debit and credit separately plus explicit net. General totals sum direct accounts once. | Date range, level, branch, cost center; partial-scope warning; leaf drill to ledger; screen/PDF/CSV/Excel. | Implemented and covered, including historic accounts and a 100-debit/40-credit group example. Adjustment-stage snapshots are not offered because the data model has no approved stage marker. |
| Income statement | Posted revenue/expense lines in range; period-closing journals excluded from operating performance. Account classifications drive every category. | Summary/detailed and comparison dates. Revenue, returns/discounts, net revenue, cost of sales, gross profit, operating expense, other income, finance cost, tax, result. | Gross profit = net revenue − cost of sales. Period result is not pulled from prior periods. Unclassified accounts are shown and warned, never guessed. | Company, current range, comparison range, branch/cost center with partial-scope notice; account-ledger drill; PDF/CSV/Excel. | Implemented and covered before close, after close, after reopen, and after reclose. |
| Statement of financial position | Posted asset/liability/equity lines through `to_date`. | Summary/detailed, as-of comparison, ledger equity and unclosed period result shown separately. | Assets − (liabilities + equity) is exposed as a difference. Unclosed result enters equity once; after closing it remains only in ledger equity. No plug account is generated. | Same dimensions and exports; detailed account drill. | Implemented and covered by a balanced numeric example and close/reopen cycle. |
| Changes in equity | Posted equity movements; ordinary direct movements separated from closing transfers. | Opening, direct increases, direct decreases, period result, ending per component; comparison closing/variance. | Ending = opening + increases − decreases + classified closing/unclosed result. Internal closing transfer is not counted twice as new total equity. | Same dimensions, account drill, PDF/CSV/Excel. | Implemented and covered for the reference example. A business-specific contribution/distribution taxonomy beyond the existing classifications is still an explicit policy gap. |
| Cash flow — direct | Actual journal entries touching accounts classified `cash`, `bank`, or `cash_in_transit`; counterpart classification determines operating/investing/financing/exchange/unclassified. Internal transfers inside the cash-equivalent set do not create an external flow. | Operating receipts/payments, investing receipts/payments, financing receipts/payments, exchange effect, unclassified amount, opening/ending cash and a per-account cash-component bridge. | Ending cash = beginning + direct operating + investing + financing + exchange effect + unclassified. Equation difference is displayed. | Same dimensions, account drill from cash components, PDF/CSV/Excel. | Implemented and numerically covered. Ambiguous `finance_cost` movements stay unclassified and make the statement unreconciled until policy is approved. |
| Cash flow — indirect | Same range, cash population, and classification as direct; starts at period result, adds non-cash depreciation/disposal/FX adjustments and working-capital changes from classified balance-sheet accounts. | Period result, non-cash adjustments, working-capital change, operating result, investing, financing, exchange, cash bridge. | Indirect operating is compared explicitly with direct operating. Both the method difference and cash equation difference must be zero; unclassified movement also prevents a reconciled status. | Same dimensions and exports. | Implemented and numerically covered. Interest/dividend policy and any classifications not present in the registry are not silently invented. |
| Fixed-asset reports/reconciliation | Existing fixed-asset register and movements versus posted asset/accumulated-depreciation accounts. | Cost, movement, depreciation, net book value, reconciliation. | Independent subledger versus GL. | Historical date and asset filters; existing print/export. | Existing implementation has dedicated populated-database coverage; included in later integrated regression, not rewritten here. |
| Inventory/WIP/finished goods reconciliation | Existing inventory transaction/layer sources versus posted classified GL accounts. | WIP, finished goods, waste and related balances. | Independent inventory subledger versus GL. | Existing manufacturing reports/tests. | Existing implementation covered in the manufacturing cycle; consolidated accounting reconciliation UI remains open. |
| Finance operational reports | Approved cash vouchers, both approved transfer legs, bank/cash openings, cheque lifecycle/events, customer receipts, supplier payments, invoices and schedules. | Cashbox/bank balances and statements, cash vouchers, transfers, bank reconciliation, received/issued/returned/due/cancelled/guarantee cheques, advance allocation, unapproved documents, customer/supplier aging. | Drafts are excluded from financial balances; currencies remain separated; unsupported external-bank reconciliation states are disclosed rather than fabricated. | Holder, currency, status and date/as-of filters; document drill; CSV/XLSX/PDF. | Implemented and covered across the unified finance report and legacy report routes. |
| Cost-center/cost reports | Posted production inventory documents plus posted journals linked to existing cost centers and production orders/runs. | Product cost, work-order cost, estimated versus actual, variance, profitability, issued/returned/wasted/capitalized/recognized cost, WIP, and missing required cost-center dimensions. | Recognized finished-goods cost is separated from WIP; invoice revenue is net of credit notes; drafts are excluded and missing dimensions are flagged. | Product, work order, run status, cost center and date; CSV/XLSX/PDF and real data endpoints. | The five required costing report surfaces are implemented and covered. Allocation-basis/normal-capacity and under-absorption workflow evidence remains open. |

## Mandatory numeric accounting example

The automated example posts:

1. Before the period: debit cash 10,000 / credit capital 10,000.
2. Inside the period: debit cash 3,000 / credit revenue 3,000.
3. Inside the period: debit expense 2,000 / credit cash 2,000.

Proved results:

| Measurement | Debit | Credit |
|---|---:|---:|
| Period movement totals | 5,000 | 5,000 |
| Cumulative movement totals | 15,000 | 15,000 |
| Ending balance totals before close | 13,000 | 13,000 |

- Period result: profit 1,000.
- Financial position: cash 11,000 = capital 10,000 + unclosed result 1,000.
- Direct and indirect operating cash flow: 1,000.
- Opening cash 10,000; ending cash 11,000.
- Cash equation difference: zero.
- Direct/indirect operating-method difference: zero.
- Period close transfers the result once; income statement still explains the 1,000 performance after close; reopen reverses the closing entry; reclose creates one next auditable cycle without duplicating profit.

## Period close/reopen controls

- A dedicated, company-scoped close/carry-forward workspace is available under general accounting. It selects a period, shows period metadata and the next chronological period, and explains that the next period opening is derived from posted history rather than a duplicated opening entry.
- The workspace presents a read-only preflight before mutation: period-type policy, draft journals, unresolved financial documents, provisional/unpriced receipts, unvalued movements, negative stock, inventory-to-GL reconciliation, GRNI reconciliation, required cost-center allocations, posted base-currency trial balance, retained-earnings mapping, open operational orders, existing close journal, and next-period context. Failed checks show counts and remediation links when the user has permission.
- Open sales, purchase, and production orders are warnings: they may continue operationally after close, but the common posting-period guard prevents new financial effects from being posted back into the closed period. Unresolved financial effects are blockers.
- Preview and execution share the same closing-plan calculation. Execution locks the company period row, re-runs every blocker inside the closing transaction, and requires explicit confirmation from the dedicated workspace, so a stale preview cannot authorize a close. Financial and inventory posting services resolve and lock the same open period before posting.
- Ordinary financial-period CRUD cannot set or flip `is_closed`.
- Close locks the period, blocks draft journals, verifies the posted base-currency trial balance, creates one system posted closing journal, then closes the period in the same retryable transaction.
- Reopen records a posted reversal linked to the closing journal; it does not delete history.
- Repeated close/reopen calls are idempotent for their current state.
- The result view links to the generated closing or reversal journal when the user has journal-view permission, and close/reopen audit records retain the optional operator note.
- View-only users can inspect the workspace but cannot see mutation controls; close/reopen permissions are enforced independently. Foreign-company period identifiers return 404 rather than leaking scope.
- Legacy closed periods with live income balances and no auditable closing journal require review.
- The data model currently has no approved year-end/period-type field. The workspace therefore exposes that policy gap as a warning and requires confirmation; it does not invent a year-end classification.
- The cross-module checklist is implemented for the document and reconciliation sources listed above. Fixed-asset and payroll subledger reconciliation are still not part of the same preflight, and the year-end policy warning remains until the data model has an approved period-type decision.

## Automated evidence actually run in this checkpoint

| Scope | Result |
|---|---|
| User-supplied historical checkpoint | 76 tests, 1,379 assertions. This is recorded as earlier evidence only; it is not added to or substituted for the runs below. |
| Focused accounting, finance, costing, partner-data, sales, procurement, and UI-shell regression after the final changes | 91 passed, 3,146 assertions in 97.73 seconds. |
| Screenshot regressions: quotation totals, sales-request DataTable actions, and purchase-invoice schedule/item validation | 56 passed, 883 assertions in 79.39 seconds. The quotation fixture proves 7,625 subtotal + 381.25 tax = 8,006.25 across persistence, show, edit, and print. |
| Period-close stale-preview regression | Preview passed, a draft opening-balance document was created afterward, execution rechecked and blocked, the period remained open, and no closing journal was created. Included in the focused and full runs. |
| Numeric financial-statement example and screen/export contract | 1 passed, 51 assertions, including screen plus CSV, Excel, and PDF paths. |
| Canonical navigation, role-permission hierarchy, search, breadcrumbs, HR, purchases, finance, and fixed-asset menu semantics | 182 passed, 7,685 assertions. |
| Navigation search, unsaved-change guard, and PWA navigation JavaScript | 12 passed. |
| `vendor/bin/pint --dirty --format agent` | Passed. |
| `git diff --check` | Passed. |
| Translation/catalog consistency after normalized geography filters | 18 passed, 3,880 assertions. |
| Final full PHP regression suite | 1,935 passed, 2 skipped, 36,191 assertions in 1,052.44 seconds; zero failures. The direct Pest process used a 512 MB test-only memory limit because the single-process run exhausted 128 MB after hundreds of tests while ZipStream assembled an XLSX. Individual export coverage passed at the normal limit; no production memory setting was changed. |

The complete automated PHP regression gate is green. That proves code-level integration on the isolated SQLite test environment; it does **not** by itself prove client-data quality, browser/device acceptance, production-scale performance, or deployment/restore readiness.

The local application login page was reachable at the checkpoint, but the isolated in-app browser had no authenticated test session. No recovery user, broad seed, or real-document mutation was introduced merely to bypass authentication. Authenticated visual acceptance of the three screenshot flows therefore remains part of the browser QA item below.

## Open acceptance items — do not claim production readiness

1. Prove the remaining allocation-engine policies: approved-hours/material-value fallback, partial/zero bases, normal capacity, under-absorption, rerun idempotency, and source-to-order drill. The five requested costing reports are no longer placeholders.
2. Complete a consolidated reconciliation center for customers, suppliers, cashboxes, banks/external bank statements, fixed assets, payroll/accrual/payment, cost allocation, and cross-statement linkage. The close preflight now covers inventory/GRNI/cost-center blockers but is not a replacement for that full matrix.
3. Run and preserve the supplied 10,000-unit factory reference scenario and its stated reference totals.
4. Run moving-average, periodic weighted-average, and FIFO as controlled independent inventory-method scenarios.
5. Prove WIP/finished-goods/COGS reconciliation through close and late cost after sale.
6. Prove payroll-to-ledger and payroll-to-cash reconciliation from attendance through approved payroll and settlement.
7. Extend the read-only data-readiness matrix beyond customer/supplier completeness to opening balances, every posting-account mapping, cash/bank positions, payroll setup, and client-data reconciliation.
8. Define the requested “unmanufactured products” indicator from an approved business decision; do not infer it from zero stock or absent BOM.
9. Confirm the accounting framework/effective policies for OCI/disclosures and interest/dividend cash-flow classification. The current implementation exposes ambiguity instead of guessing.
10. Large-data query-count, peak-memory, export consistency/concurrency, period-close/posting lock-contention, and retry evidence. The 128 MB accumulated-suite XLSX failure is a test-process memory observation that still requires production-volume profiling.
11. Browser/device visual QA for the reorganized deep menus and all new accounting, finance, costing, and geography-filter report modes/exports in Arabic and English.
12. A safe non-production deployment rehearsal with migration, permission sync, cache rebuild, backup, and restore evidence.

## Deployment and rollback notes

- No dependency was added. Two idempotent reconciliation migrations were added for active-record unique indexes on users and products; they must be exercised in the non-production upgrade rehearsal before deployment.
- Synchronize permissions idempotently so `reports.trial_balance.*` and `reports.financial_statements.*` reach intended roles; do not grant broad roles automatically.
- Clear route/config/view/menu/permission caches after deployment.
- Verify in a non-production company: trial-balance mode matrix and all exports; five financial statement modes and all exports; cash component bridge; close with a draft journal (must block); close, reopen, and reclose.
- Before rolling code back, reopen any period closed by the new workflow through the application so the posted reversal is retained. Never delete closing journals.
- No production data was changed by this work; mutations were limited to isolated tests.
