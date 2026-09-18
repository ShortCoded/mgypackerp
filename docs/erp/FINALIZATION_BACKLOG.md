# ERP Finalization Backlog

Authoritative Wave 0 audit backlog. Evidence was collected from the live checkout on 2026-09-19. This document records findings and verification work only; it does not authorize implementation, migration execution, deployment, or production-data changes.

## Baseline

- Repository: `main`, two commits ahead of `origin/main`; Wave 0.5/0.6 changes remain uncommitted for review.
- Runtime: PHP 8.5.10, Laravel 12.61.0, Composer 2.10.1, Node 22.23.1, pnpm 12.4.1.
- Environment: local; PostgreSQL; database-backed queue/cache/session; 1,988 non-vendor routes.
- Live modules: Accounting, Auth, Core, Finance, FixedAssets, HR, Inventory, Maintenance, Production, Purchases, Sales. Quality is implemented inside Production.
- Scheduler: minute jobs for presence cleanup, due-notification dispatch, and bounded database queue work.
- Tests: the authoritative Wave 0.6 `composer test` run passed 1,986 tests with 2 skipped and 37,120 assertions in 1,103.68 seconds (18:27.94 wall time). Test isolation remains SQLite `:memory:`.
- Build: the narrow project-local esbuild approval is active; `pnpm run build` passes with Vite 7.3.2.
- Schema: the valuable source `mgypack` still has five pending migrations and was not modified. All five were validated successfully on the isolated clone `mgypack_wave0_baseline`.

## Sources of Truth and Reusable Infrastructure

- Inventory quantity: posted `inventory_transactions` created through `InventoryMovementService` and `InventoryDocumentPostingService`.
- Inventory book cost: posted inventory document/transaction line `unit_cost` and `total_cost`. Receipt layers/allocations preserve FIFO lineage and availability; valuation simulations are comparison methods, not a replacement book ledger.
- Accounting: posted `journal_entries` and `journal_entry_lines` through `JournalEntryService`; ledgers must not read operational documents as a parallel GL.
- Sales pricing: `PriceListPricingService`, with resolved values snapshotted onto transactional document lines.
- Supplier bank payments: `ProcurementSettlementService` and `SupplierPaymentPostingService`.
- Select2 paging: `Core\Services\Select2ResponseService`; active financial accounts: `Accounting\Services\AccountSelect2Service`.
- Closing/reconciliation: `PeriodClosePreflightService`, `ReconciliationCenterService`, `InventoryGlReconciliationService`, and `PayrollReconciliationService`.

## Wave 1 — Sales

### SAL-001 — Price List clone is absent

- Domain/workflow/current state: Sales; Price List administration; **CONFIRMED MISSING FEATURE** (`K-01`).
- Evidence: Price List routes/controller/service expose CRUD only. Existing customer and quotation clone routes provide the closest reusable contract. `price_lists` and `price_list_lines` are a master/detail object.
- Risk/severity: T1 / medium. Dependencies: document numbering, audit metadata, company scope, permissions.
- Likely components: `modules/Sales/Routes/web.php`, `PriceListController`, `PriceListService`, Price List views/requests; `DocumentNumberService`.
- Existing tests: `tests/Feature/SalesPriceListTest.php` (included in the passing focused run). Missing validation: independent identity, complete line copy, tenant isolation, and audit-field behavior.
- Finalization: Wave 1; reuse the established clone pattern and never copy primary/document/audit identity blindly.

### SAL-002 — Transactional percentage adjustment is absent

- Domain/workflow/current state: Sales; Price List maintenance; **CONFIRMED MISSING FEATURE** (`K-02`).
- Evidence: no route/request/service/UI action exists. `price_list_lines.unit_price` and `allowed_discount_value` are decimal(20,4); `PriceListService` already uses transactions and `SalesAmountService` owns rounding.
- Risk/severity: T2 / high. Dependencies: rounding, currency, permissions, row locks, audit trail.
- Likely components: Price List routes/controller/request/service/views and pricing tests.
- Existing tests: Price List CRUD/acceptance tests. Missing validation: four-decimal rounding, invalid/edge percentages, injected-row-failure rollback, concurrent use, and historical snapshot invariance.
- Finalization: Wave 1 after SAL-001 boundaries are established.

### SAL-003 — Print-only Price Lists cannot be represented or excluded

- Domain/workflow/current state: Sales pricing resolution; **CONFIRMED MISSING FEATURE** (`K-03`).
- Evidence: `PriceList` has no print-only property; `PriceListPricingService::latestLine()` selects by company/customer/currency/effectivity without such an exclusion and feeds quotation, request, order, invoice, and suggestion paths.
- Risk/severity: T3 / high. Dependencies: existing-data migration, centralized pricing fallback, reporting, historical price snapshots.
- Likely components: Price List schema/model/request/form, `PriceListPricingService`, pricing-gap queries, all price-resolution tests.
- Existing tests: `SalesPriceListTest`, `SalesBusinessAcceptanceTest`. Missing validation: default/backfill, customer/general fallback, every resolution path, concurrent update/resolution, and no repricing of stored documents.
- Finalization: Wave 1 with independent critical review; backend resolver exclusion is mandatory, not only UI filtering.

### SAL-004 — Price List print and exports are absent

- Domain/workflow/current state: Sales Price List output; **CONFIRMED MISSING FEATURE** (`K-04`).
- Evidence: no Price List print/export routes or actions. Reusable infrastructure exists in `SalesCycleReportController`, `ReportPdfService`, export classes, and report toolbar components.
- Risk/severity: T1 / medium. Dependencies: SAL-001/SAL-003 visibility rules and permission design.
- Likely components: Price List controller/routes/views/export class.
- Existing tests: report-export shape and Sales report tests. Missing validation: selected persisted header/details match HTML, PDF, XLSX, and CSV.
- Finalization: Wave 1; outputs must not re-resolve operational prices.

### SAL-005 — Sales report summaries cover only selected perspectives

- Domain/workflow/current state: Sales reporting; **PARTIALLY IMPLEMENTED** (`K-13`).
- Evidence: `SalesCycleReportController` supplies summaries for financial/operational perspectives, while invoice, product, period, customer, receivable, collection, and return tables remain row-oriented; exports mirror that limitation.
- Risk/severity: T2 / medium. Dependencies: filter parity, currency boundaries, REP-001, export templates.
- Likely components: Sales cycle report controller/read service/views/exports.
- Existing tests: `SalesDocumentSummaryTest`, Sales report/export tests. Missing validation: mathematically valid totals per perspective and exact screen/PDF/XLSX/CSV/filter equivalence without cross-currency summation.
- Finalization: Wave 1 for Sales-specific totals; Wave 11 for cross-report reconciliation.

## Wave 2 — Purchases

### PUR-001 — Supplier bank payment path exists but end-to-end statement reconciliation is unproven

- Domain/workflow/current state: Purchases to Bank/AP/GL; **PARTIALLY IMPLEMENTED** (`K-08`).
- Evidence: `ProcurementSettlementService` calls `SupplierPaymentPostingService`, which posts one supplier-payment journal and supports idempotent approval and reversal. `FinanceReportService` includes approved non-cheque supplier bank contexts. `ProcurementCycleTest` contains journal/cancellation assertions, but no executed test proved one-and-only-one appearance across bank statement, supplier statement, AP, and GL.
- Risk/severity: T3 / high. Dependencies: Accounting reports, bank/currency/date filters, Wave 9 reconciliation.
- Likely components: the named settlement/posting/report services and `tests/Feature/ProcurementCycleTest.php`.
- Missing validation: targeted runtime path for Bank method, exact statement/ledger equality, cancellation, branch/company/period isolation, and duplicate request.
- Finalization: Wave 2 verification; Wave 4 accounting review; Wave 9/11 reconciliation. Do not introduce a second posting source.

## Wave 3 — Inventory

### INV-001 — Aggregate inventory valuation capability is incomplete

- Domain/workflow/current state: Inventory valuation; **PARTIALLY IMPLEMENTED** (`K-10`).
- Evidence: `InventoryReportService` can aggregate as-of quantity/value across operational dimensions, but operational stock reports hide financial values. The `/admin/inventory/reports/valuation` screen compares methods for one product/store rather than providing the required aggregate valuation. Missing cost is currently coalesced to zero.
- Risk/severity: T3 / high. Dependencies: inventory ledger, book-cost policy, unpriced receipts, branch/store/hall/location filters, GL reconciliation.
- Likely components: `InventoryReportService`, `InventoryValuationService`, `InventoryGlReconciliationService`, report controller/views/exports.
- Existing tests: `InventoryValuationComparisonTest`, `OpeningStockPricingTest` (passed in focused run), `ReportExportShapeTest`. Missing validation: aggregate/as-of reconciliation, unvalued and negative stock, cross-period behavior, unit conversion, transfers/returns/production, isolation, and output parity.
- Finalization: Wave 3 with critical review; label book valuation versus comparison methods explicitly.

### COST-001 — Delivery COGS posts, but traceable sales-cost reporting is incomplete

- Domain/workflow/current state: Inventory/Sales/Accounts costing; **PARTIALLY IMPLEMENTED** (`K-11`).
- Evidence: `SalesAccountingService::postDeliveryCost()` and saleable-return reversal use posted inventory cost; Reconciliation Center compares COGS; financial statements classify cost of sales. Existing costing reports are production-oriented, not delivery/return movement reports.
- Risk/severity: T3 / high. Dependencies: INV-001, Sales delivery/return/cancellation, production finished-goods cost, GL.
- Likely components: `SalesAccountingService`, `ReconciliationCenterService`, inventory/sales read services, costing reports.
- Existing tests: `SalesCycleTest` contains delivery COGS assertions. Missing validation: item/document traceability, partial delivery, returns/reversal, cancellation/repost, dates, isolation, and report-to-inventory-to-GL equality.
- Finalization: Waves 3 and 4; mandatory Waves 9/11 reconciliation.

## Wave 4 — Accounts & Costing

### ACC-001 — Approved generic cash vouchers form a treasury truth outside the GL

- Domain/workflow/current state: Receipt/payment vouchers, cashbox statement, ledger and closing; **CONFIRMED DEFECT** (`K-09` root; `K-06` dependent symptom).
- Evidence: `CashVoucherService::approve()` journals linked supplier/payment/payroll contexts only; generic vouchers are merely approved. Generic cancellation has no journal reversal. `FinanceReportService::cashboxMovements()` reads every approved voucher, while `LedgerQueryService` reads posted journals. `PeriodClosePreflightService` explicitly blocks approved vouchers without a linked posting source; `JournalEntriesAndLedgerTest` covers that blocker. Approval's early return means already-approved records need explicit reconciliation handling.
- Risk/severity: T3 / **blocker**. Dependencies: cash/bank account mapping, counter-account lines, currency, branch/company/period, historical data, closing.
- Likely components: `CashVoucherService`, `CashVoucher`, voucher schema, `FinanceReportService`, `JournalEntryService`, `LedgerQueryService`, preflight and finance/accounting tests.
- Existing tests: `FinanceReportTest` currently codifies document-derived cashbox totals; the focused Wave 0 ledger test passed. Missing validation: atomic posting/status, source-key idempotency, cancellation/reversal, already-approved reconciliation, cashbox opening/running/closing balance, and exact GL equality.
- Finalization: Wave 4 with critical review. Wave 9/11 must prove K-06 statement reconciliation. Use the existing journal service; do not add parallel accounting logic.

### UI-001 — Deleted-account exclusion is present but picker coverage is incomplete

- Domain/workflow/current state: Financial account selection; **PARTIALLY IMPLEMENTED** (`K-07`).
- Evidence: `AccountSelect2Service` defaults to active, non-trashed accounts; authorized historical report contexts explicitly use `withTrashed()`. Finance transaction selectors require active/postable accounts and ledger lookup preserves old relationships.
- Risk/severity: T2 / medium. Dependencies: shared Select2 clients and historical report rendering.
- Likely components: Accounting/Finance Select2 services, financial screens, route-level selector tests.
- Existing tests: Auth Select2 and account CRUD coverage. Missing validation: every affected picker distinguishes active/inactive/soft-deleted while historical rows remain readable.
- Finalization: Wave 4 verification; shared UI corrections, if reproduced, belong in Wave 10.

## Wave 5 — Production

### PROD-001 — Production integrity controls exist but recovery/idempotency release evidence is incomplete

- Domain/workflow/current state: BOM requirement through material issue, output, QC, receipt, costing, closure; **UNVERIFIED**.
- Evidence: `ProductionCycleService` uses immutable BOM snapshots, locks, remaining-requirement/output guards, material reconciliation, QC gates, inventory documents, and WIP costing. The audit did not execute duplicate/resume or injected partial-failure paths.
- Risk/severity: T3 / high. Dependencies: Inventory, Quality, Sales demand, costing, period locks.
- Likely components: `ProductionCycleService`, `ProductionCostService`, inventory posting services, `ManufacturingInventoryCycleTest`.
- Existing tests: substantial manufacturing cycle and browser coverage. Missing validation: retry/idempotency of each action, rollback across stock/journal/reservation counters, reversal/cancellation, concurrent runs, and full order closure reconciliation.
- Finalization: Wave 5 focused regression and reconciliation; fix only proven failures.

## Wave 6 — Quality & Maintenance

### MAINT-001 — Maintenance materials post to adjustment gain/loss rather than authoritative consumption cost

- Domain/workflow/current state: maintenance material issue, consumption, return, costing; **CONFIRMED DEFECT**.
- Evidence: `MaintenanceMaterialRequestService::issue()` posts `inventory_adjustment_out`; unused return posts `inventory_adjustment_in`; `InventoryAccountingPostingService` maps these to adjustment loss/gain. `recordConsumption()` only changes quantities. Return cost may be recalculated at the later moving average because original issue-cost lineage is absent.
- Risk/severity: T3 / **blocker**. Dependencies: Inventory, Accounts & Costing, asset/cost-center policy, Maintenance closure.
- Likely components: maintenance material service, inventory document/accounting posting services, manufacturing cycle tests.
- Existing tests: quantity conservation is covered, but accounting classification/cost is not. Missing validation: issue-cost preservation, partial consumption/return, expense-or-asset policy, cost center, rollback/reversal/idempotency, and inventory-to-GL reconciliation.
- Finalization: Wave 6 with Wave 4 accounting ownership and Wave 9/11 reconciliation.

## Wave 7 — HR

No standalone HR defect was confirmed in Wave 0. Payroll lifecycle and `PayrollReconciliationService` exist, and the focused payroll integration test passed. Release remains blocked by REL-001 until the pending payroll financial-chain migration is reviewed and schema parity is proved. Do not expand HR scope beyond actual employee, attendance, permissions, biometric/device, and implemented payroll/accounting flows.

## Wave 8 — Fixed Assets

### FA-001 — Purchase-to-asset capitalization needs final duplicate-recognition reconciliation

- Domain/workflow/current state: purchase allocation, capitalization, depreciation, disposal and reversal; **UNVERIFIED**.
- Evidence: Fixed Asset services use transactions and canonical journal sources; purchase allocation guards line limits and lifecycle services prevent duplicate source recognition. Strong lifecycle tests exist, but the audit did not execute purchase invoice journal versus capitalization reconciliation.
- Risk/severity: T3 / high. Dependencies: Purchases, Accounts, account tree, branch/hall/cost center.
- Likely components: `FixedAssetPurchaseIntegrationService`, `FixedAssetLifecycleService`, `FixedAssetAccountingSyncService`, fixed-asset integration/lifecycle tests.
- Missing validation: exact purchase-line allocation, no duplicate inventory/asset recognition, reversal, period lock, and GL/book-value equality.
- Finalization: Wave 8 verification; preserve existing account-tree foreign keys.

## Wave 9 — Cross-module Integration

Wave 9 owns reconciliation acceptance for ACC-001, PUR-001, INV-001, COST-001, PROD-001, MAINT-001, and FA-001. It must use the named canonical ledgers/services, inject rollback and duplicate-request cases, and prove source-to-subledger-to-GL/report equality. This is a verification wave, not permission to create new truth sources.

## Wave 10 — Permissions & UI Consistency

### UI-002 — Statement selector initial-load behavior is inconsistent

- Domain/workflow/current state: customer/supplier statements; **PARTIALLY IMPLEMENTED** (`K-12`).
- Evidence: the shared paginated endpoint supports an empty query and active company-scoped results. Customer Statement uses minimum input length 0; Supplier Statement uses 1, producing the reported misleading empty initial state.
- Risk/severity: T1 / medium. Dependencies: shared Select2 paging and statement permissions.
- Likely components: `resources/views/modules/accounting/reports/ledger.blade.php`, Sales/Purchases Select2 services, Select2 browser tests.
- Existing tests: shared Select2 service/lookup coverage. Missing validation: browser open-without-search for both populations, page navigation, large datasets, permission-only users, active/deleted scope.
- Finalization: supplier correction in Wave 2 or shared Wave 10; retain server-side pagination.

### REP-001 — Sales document signature identities are explicitly blank

- Domain/workflow/current state: Prepared/Reviewed/Approved report identity; **CONFIRMED DEFECT** (`K-05`).
- Evidence: `resources/views/reports/sales/document.blade.php` passes all three identities as null to `reports.partials.document-signatures`. The shared partial supports audit relations; `CompanyPrintIdentityService` owns company branding, not personnel identity.
- Risk/severity: T1 / medium. Dependencies: real document audit/approval data and employee/user conventions.
- Likely components: Sales print view, shared signature partial, document audit relations and print tests.
- Existing tests: report/export shape coverage. Missing validation: every affected document with/without actual relations and absence of fabricated reviewers/approvers.
- Finalization: Wave 10/11 after the real identity source per document is confirmed.

### SEC-001 — Complete backend authorization coverage for sensitive mutations is unverified

- Domain/workflow/current state: cross-module permissions/auditability; **UNVERIFIED**.
- Evidence: permission registration is centralized in `PermissionRegistryService`, many routes use `can:*`, and operating company/branch/period rejection has substantive tests. Wave 0 did not prove all 1,988 routes, especially approve/post/reverse/restore actions, are protected beyond UI visibility.
- Risk/severity: T3 / high because authorization and tenant boundaries are security-critical. Dependencies: every transactional wave.
- Likely components: route middleware, form requests/policies, audit columns/events, permission sync tests.
- Existing tests: permission seeding/sync, operating scope, screen visibility. Missing validation: generated route-to-permission inventory and negative tests for every sensitive mutation, actor audit, restore and status transitions.
- Finalization: Wave 10; record only concrete failures as implementation defects.

## Wave 11 — Regression, Reconciliation & Closing

### CLOSE-001 — Full close/carry-forward evidence is incomplete

- Domain/workflow/current state: period preflight, close, opening/carry-forward and reconciliation; **UNVERIFIED**.
- Evidence: `FinancialPeriodClosingService`, `PeriodClosePreflightService`, `ReconciliationCenterService`, and opening-balance services exist; the focused ledger test passed. End-to-end closure with every operational source, reversal lock, and next-period balance was not executed.
- Risk/severity: T3 / high. Dependencies: all T3 transactional findings and REL-001 schema parity.
- Likely components: Accounting closing/reconciliation services and their feature tests.
- Missing validation: all preflight blockers, atomic close, retry/idempotency, locked-period mutation rejection, currency/branch/company isolation, carry-forward equality, and rollback/recovery.
- Finalization: Wave 11 after Waves 1–10 reconciliation findings are resolved.

### TEST-001 — Complete regression baseline stabilized

- Domain/workflow/current state: test/release evidence; **VERIFIED RESOLVED** in Wave 0.6.
- Evidence: `phpunit.xml` sets test-only memory to 512 MiB and Composer disables its process timeout only for the `test` script. Exact unmodified `composer test` completed: 1,986 passed, 2 skipped, 37,120 assertions; Pest 1,103.68 seconds, wall 18:27.94.
- Risk/severity: T0/T1 baseline infrastructure and bounded UI regression repair / resolved.
- Repairs: shared hidden form-control markup; stale eight-dependency dashboard test construction; semantic dashboard metric assertions preserving scope/count invariants; missing pending-sourcing report menu entry and translations; stale Sales-versus-Accounting report-group expectation.
- Existing validation: all affected files passed targeted suites before the complete suite. The two skipped tests remain explicitly reported by Pest, not hidden.
- Finalization: retain as baseline evidence for Wave 11; no open blocker remains under this finding.

## Wave 12 — Production Release Readiness

### REL-001 — Inspected database is behind the repository schema

- Domain/workflow/current state: deployment/schema parity; **BLOCKED**.
- Evidence: five pending migrations remain on the valuable source `mgypack`: users active unique indexes, products active unique indexes, overhead allocation tables, HR payroll financial chain, and cashbox counts. Wave 0.6 cloned the source read-only into `mgypack_wave0_baseline` and applied all five successfully as batch 32. Across 322 common tables, source and sandbox retained the same 69,010-row aggregate and identical per-table count hash. The source remained at 256 migration rows with all five pending; the sandbox has 261.
- Risk/severity: T3 operational work / **blocker**. This is an environment gate, not evidence that production is behind or that migration code is defective.
- Dependencies: owner confirmation that the database is disposable/non-production, writer-free window, verified snapshot/restore, duplicate/data preflight, staging rehearsal, Waves 4/7/11.
- Likely components: the five `2026_09_17_*` migrations and related reconciliation tests.
- Missing validation: production-target status and populated-payroll behavior. Two index reconciliations have empty `down()` methods; snapshot restore, not blanket rollback, is the recovery plan. The sandbox source had no payroll rows, so a populated target requires fresh duplicate/backfill preflight and restore rehearsal.
- Wave 1 dependency: **WAVE 1 DOES NOT DEPEND ON PENDING MIGRATIONS**. None changes Price List/Sales schema; the product target indexes already existed identically on the source. SAL-003 will require its own future migration.
- Finalization: Wave 12 only under separately authorized release/migration procedures.

### REL-002 — Production frontend build-script approval resolved

- Domain/workflow/current state: release artifact build; **VERIFIED RESOLVED** in Wave 0.5.
- Evidence: `pnpm-workspace.yaml` now explicitly allows only `esbuild`; `pnpm rebuild esbuild` completed its 0.27.7 postinstall and `pnpm run build` completed with Vite 7.3.2, 54 transformed modules, and generated manifest/CSS/JS assets.
- Risk/severity: T0 / low. No dependency version or lockfile change occurred.
- Remaining validation: repeat the same build in CI/release infrastructure. No JS test, lint, or static-analysis script is configured in `package.json`.
- Finalization: no business implementation required; retain the narrow project-local allowlist.

Queue note: the global database queue has `after_commit=false`, but current inspected queued flows opt into after-commit individually. This is a governance hazard, not a confirmed defect or blocker. Wave 12 must inventory every `ShouldQueue` dispatch created inside transactions and prove rollback creates no external side effect before considering any global timing change.

## User-Confirmed Mandatory Findings

| K item | Wave 0 status | Root finding(s) |
| --- | --- | --- |
| K-01 Price List Clone | CONFIRMED MISSING FEATURE | SAL-001 |
| K-02 Increase Price List by Percentage | CONFIRMED MISSING FEATURE | SAL-002 |
| K-03 Print-Only Price Lists | CONFIRMED MISSING FEATURE | SAL-003 |
| K-04 Price List Print and Export | CONFIRMED MISSING FEATURE | SAL-004 |
| K-05 Prepared/Reviewed/Approved identity | CONFIRMED DEFECT | REP-001 |
| K-06 Treasury/Cash Account Statement | ALREADY IMPLEMENTED BUT BROKEN | ACC-001 dependent reconciliation symptom |
| K-07 Deleted accounts in pickers | PARTIALLY IMPLEMENTED | UI-001; central exclusion exists, complete runtime coverage is missing |
| K-08 Supplier bank payment reconciliation | PARTIALLY IMPLEMENTED | PUR-001; posting/reversal exists, statement-to-GL proof is missing |
| K-09 Receipt/payment voucher accounting | CONFIRMED DEFECT | ACC-001 root cause |
| K-10 Inventory Valuation Report | PARTIALLY IMPLEMENTED | INV-001 |
| K-11 Cost of Sales reporting | PARTIALLY IMPLEMENTED | COST-001 |
| K-12 Statement selector initial choices | ALREADY IMPLEMENTED BUT BROKEN | UI-002; supplier client configuration differs from working customer path |
| K-13 Sales/invoice report totals | PARTIALLY IMPLEMENTED | SAL-005 |

No K-item is classified `VERIFIED ALREADY CORRECT`; runtime/code/test evidence was insufficient for that designation.

## Release Gate Summary

- Material findings: 20.
- Severity blockers: 3 (`ACC-001`, `MAINT-001`, `REL-001`). `REL-001` remains a production-release schema gate but is not a Wave 1 dependency.
- T3 findings: 11 (`SAL-003`, `PUR-001`, `INV-001`, `COST-001`, `ACC-001`, `PROD-001`, `MAINT-001`, `FA-001`, `SEC-001`, `CLOSE-001`, `REL-001`).
- Wave 0.6 establishes a reliable baseline for Wave 1. Each implementation wave must apply `docs/erp/DEFINITION_OF_DONE.md`, preserve the named sources of truth, and stop before deployment or destructive data work unless separately authorized.
