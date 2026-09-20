# ERP Finalization Status

Wave 0 status with the Wave 0.6 stabilization gate as of 2026-09-19. `VERIFIED` means the relevant execution path and effects were evidenced; the presence of routes, screens, services, or tests alone is not sufficient.

## Baseline Verification

| Area | Result |
| --- | --- |
| Repository | `main`, one commit ahead of `origin/main`; clean before audit |
| Runtime | PHP 8.5.10; Laravel 12.61.0; Composer 2.10.1; Node 22.23.1; pnpm 12.4.1 |
| Database/runtime drivers | PostgreSQL; database queue/cache/session; local debug enabled |
| Routes/modules | 1,988 non-vendor routes; 11 live top-level modules; Quality is inside Production |
| Migrations | VERIFIED IN SANDBOX: five remain pending on protected `mgypack`; all five applied successfully to isolated clone `mgypack_wave0_baseline` |
| Scheduling | Presence cleanup, due notifications, and bounded database queue worker scheduled each minute |
| Focused PHP tests | VERIFIED: 51 passed, 842 assertions, 61.52 seconds |
| Full backend tests | VERIFIED: 1,986 passed, 2 skipped, 37,120 assertions; Pest 1,103.68 seconds |
| Default backend test command | VERIFIED: exact `composer test` completed in 18:27.94 with test-only memory and script-local timeout configuration |
| Frontend/static tests | NOT APPLICABLE: no JS test, lint, or static-analysis script is configured |
| Production frontend build | VERIFIED: esbuild 0.27.7 narrowly approved project-locally; Vite 7.3.2 build passed |

Focused passing command:

```text
php artisan test --compact tests/Feature/SalesPriceListTest.php tests/Feature/Accounting/JournalEntriesAndLedgerTest.php tests/Feature/Inventory/OpeningStockPricingTest.php tests/Feature/HR/PayrollFinancialIntegrationTest.php tests/Feature/OperationalNotificationWorkflowTest.php
```

## System Status Matrix

| Domain | Workflow | Data integrity | Accounting | Inventory | Permissions | Reports / reconciliation | Tests | Release blockers |
| --- | --- | --- | --- | --- | --- | --- | --- | ---: |
| Sales | PARTIAL | PARTIAL | PARTIAL | PARTIAL | UNVERIFIED | PARTIAL | PARTIAL | 0 |
| Purchases | PARTIAL | CONDITIONALLY VERIFIED | VERIFIED | PARTIAL | PARTIAL | VERIFIED | VERIFIED | 0 |
| Inventory | PARTIAL | CONDITIONALLY VERIFIED | CONDITIONALLY VERIFIED | CONDITIONALLY VERIFIED | UNVERIFIED | VERIFIED | VERIFIED | 0 |
| Accounts & Costing | PARTIAL | CONDITIONALLY VERIFIED | CONDITIONALLY VERIFIED | PARTIAL | UNVERIFIED | PARTIAL | PARTIAL | 1 |
| Production | PARTIAL | CONDITIONALLY VERIFIED | UNVERIFIED | CONDITIONALLY VERIFIED | UNVERIFIED | PARTIAL | VERIFIED | 0 |
| Quality | PARTIAL | PARTIAL | NOT APPLICABLE | PARTIAL | UNVERIFIED | UNVERIFIED | PARTIAL | 0 |
| Maintenance | VERIFIED | CONDITIONALLY VERIFIED | CONDITIONALLY VERIFIED | CONDITIONALLY VERIFIED | VERIFIED | VERIFIED | VERIFIED | 0 |
| HR | PARTIAL | PARTIAL | PARTIAL | NOT APPLICABLE | PARTIAL | PARTIAL | PARTIAL | 0 |
| Fixed Assets | VERIFIED | CONDITIONALLY VERIFIED | VERIFIED | NOT APPLICABLE | UNVERIFIED | VERIFIED | VERIFIED | 0 |
| Cross-module integration | NEEDS FIX | NEEDS FIX | NEEDS FIX | PARTIAL | UNVERIFIED | NEEDS FIX | PARTIAL | 0 |
| Permissions & UI | PARTIAL | UNVERIFIED | NOT APPLICABLE | NOT APPLICABLE | UNVERIFIED | PARTIAL | PARTIAL | 0 |
| Notifications | PARTIAL | PARTIAL | NOT APPLICABLE | NOT APPLICABLE | UNVERIFIED | UNVERIFIED | PARTIAL | 0 |
| Reports / print / export | NEEDS FIX | UNVERIFIED | NEEDS FIX | NEEDS FIX | PARTIAL | NEEDS FIX | PARTIAL | 0 |
| Closing / carry-forward | PARTIAL | UNVERIFIED | UNVERIFIED | UNVERIFIED | PARTIAL | UNVERIFIED | PARTIAL | 0 |
| Production release readiness | BLOCKED | BLOCKED | BLOCKED | BLOCKED | UNVERIFIED | BLOCKED | PARTIAL | 1 |

The Production release readiness blocker count is now **1** (valuable/production-target schema parity). Total release blockers: **2**.

## Wave 0.6 Readiness Gate

**READY FOR WAVE 1**

- The frontend blocker is resolved by the package-specific `allowBuilds.esbuild: true` setting. `pnpm rebuild esbuild` and `pnpm run build` passed without dependency or lockfile changes.
- Test isolation is verified: `APP_ENV=testing`, SQLite `:memory:`, empty `DB_URL`, array cache/session, sync queue, isolated `/tmp` caches, and a hard `RefreshDatabase` guard against persistent databases.
- Exact `composer test` passed 1,986 tests with 2 skipped and 37,120 assertions. PHPUnit supplies the spawned test process 512 MiB; Composer's timeout is disabled only for this script.
- The six Wave 0.5 failures were repaired without changing T3 business logic: four form/view/navigation roots, one stale constructor test, and two brittle dashboard markup assertions whose visibility/count semantics already passed.
- The protected `mgypack` database was read-only throughout and still has all five migrations pending. The isolated clone `mgypack_wave0_baseline` applied all five successfully with row/schema reconciliation.
- **WAVE 1 DOES NOT DEPEND ON PENDING MIGRATIONS**: none changes Price List/Sales schema, and source product indexes already match the target definitions.

Migration sandbox matrix:

| Migration | Effect | Data mutation / rollback | Risk | Wave 1 dependency |
| --- | --- | --- | --- | --- |
| Auth users active uniqueness | Replaces five partial active unique indexes | No DML; empty `down()` | T3 | No |
| Core product active uniqueness | Replaces six indexes with three company-scoped partial indexes | No DML; empty `down()` | T3 | No direct dependency; target definitions already on source |
| Cost overhead allocation | Creates four accounting/production costing tables | Additive; `down()` drops later data | T3 | No |
| HR payroll financial chain | Alters seven tables and creates two financial tables | No explicit backfill; structural rollback loses new data/columns | T3 | No |
| Finance cashbox counts | Creates one treasury table | Additive; `down()` drops later data | T3 | No |

## Evidence-Based Domain Notes

- **Sales:** pricing snapshots and centralized resolution exist. Wave 1A added verified Price List clone and transactional percentage adjustment. Wave 1B verified the additive print-only property, shared operational eligibility, transactionally locked persisted-document resolution, administrative visibility, report coverage semantics, and snapshot invariance: the critical reviewer returned GO with no findings, and QA passed `SalesPriceListTest` (34/288/48.02s), `SalesBusinessAcceptanceTest` (3/67/41.03s), and `QuotationTest` (22/356/46.68s). The additive migration and deterministic lock-order/revalidation contracts passed in isolated SQLite `:memory:` coverage; live cross-connection PostgreSQL contention was not executed. REP-001/K-05 is verified: all nine Sales document kinds render real persisted lifecycle actors, retain soft-deleted historical users, keep unsupported roles blank, and never substitute the editor or printer; focused English/Arabic HTML/PDF coverage passed 177 assertions. SAL-004/K-04 is verified: Price Lists now provide permission-aware HTML print, PDF, XLSX, and CSV outputs from one canonical stored-data mapper, including historical soft-deleted relations and English/Arabic rendering, without operational price re-resolution; independent review returned GO and QA passed `SalesPriceListTest` (41/365). SAL-005/K-13 is verified: every existing Sales report perspective now uses exact, fully filtered pre-pagination totals shared by screen, PDF, XLSX, and CSV, with company/branch/period/currency/date/filter isolation and existing return-status semantics preserved. Independent review returned GO; targeted QA passed `SalesReportTotalsTest` (9/186), existing Sales report regressions (2/30), and localized workbook metadata coverage (1/7). COST-001/K-11 is verified: one canonical read-only report traces delivery and saleable-return inventory cost to exact order/invoice/source-line and posted COGS journal identity, reconciles filtered output against full source documents, and shares rows across screen/PDF/XLSX/CSV. Critical review returned GO and QA passed the new report suite (10/91), totals regression (9/186), focused COGS (2/24), and return (3/108) coverage.
- **Purchases:** the Bank-method supplier-payment path is verified through canonical posting and linked reversal, with exact supplier/AP and bank/GL/report reconciliation, sequential idempotency, historical soft-delete visibility, scoped filters, and focused supplier selector permission/pagination coverage. Simultaneous duplicate requests, injected rollback, and cross-period reversal remain Wave 9 hardening targets.
- **Inventory:** INV-001 is verified implemented. Posted inventory transactions remain the quantity ledger and complete posted `unit_cost`/`total_cost` remain book cost. The aggregate as-of valuation provides scoped book quantity/value, explicit zero-versus-unvalued and negative-stock states, prior-period carry-in, base-currency totals, historical dimensions, and screen/PDF/XLSX/CSV parity. Maintenance returns now split by original issue allocation and restore the exact physical dimensions, so transaction availability/book value and receipt layers agree per position while document/GL reversal retains the original issue moving-average cost. Independent critical review returned GO; QA passed Maintenance integrity (14/233), Inventory Book valuation (11/109), and six focused manufacturing/inventory regressions (6/170). Authorized populated-data reconciliation and real PostgreSQL contention remain Wave 9/11 evidence.
- **Accounts & Costing:** ACC-001/K-06/K-09 is conditionally verified for generic Cash Receipt and Cash Payment vouchers. New approvals create one canonical journal through `JournalEntryService`; retries, atomic rollback, canonical reversal history, exact receipt/payment lines, cashbox/currency/branch isolation, statement opening/movement/closing, and statement-to-GL equality passed focused T3 review and QA. Generic statement rows derive only from journal lines and cannot double count the operational voucher. One approved legacy generic voucher without a canonical journal was found by read-only aggregate and remains an explicit period-close blocker pending controlled reconciliation; no silent backfill or data mutation was performed.
- **Production:** PROD-001 now has replay protection for repeatable create/lifecycle actions, production-linked reversal rejection, and injected material-issue/finished-goods rollback evidence across inventory, accounting, reservation, costing-position, and production counters. Canonical multi-run/QC/receipt/closure reconciliation remains passing. Status is conditional because the isolated SQLite test harness cannot execute the required simultaneous PostgreSQL contention cases.
- **Quality:** production quality lifecycle and QC-hold inventory movements exist with reversal guards. Notification delivery and complete operational regression were not verified.
- **Maintenance:** MAINT-001 is conditionally verified and its former INV-001 position blocker is repaired. Approved material requests create maintenance-specific canonical inventory issue/return documents, retain posted transaction and original receipt-layer lineage, post inventory against the configured `factory_maintenance_expense` account without adjustment gain/loss, and attribute the issue to the production-run cost center before the optional asset cost center. Returns reuse the issue's recorded unit cost, journal accounts, dimensions, and cost center even if mappings later change; returned quantities are split across original allocations so exact locations and layer costs are restored without diverging from the transaction ledger. Direct generic reversal and unvalued posting fail closed. Exact per-material HTML/XLSX/PDF traces, split-return aggregation, and financial redaction are verified. Independent critical review returned GO and the final INV gate passed 31 focused tests / 512 assertions. Conditional items are authorized populated-company maintenance-account classification preflight/remediation and real two-connection PostgreSQL contention evidence.
- **HR:** payroll reconciliation exists and its focused test passed. Target schema parity is blocked by a pending payroll financial-chain migration on the inspected database.
- **Fixed Assets:** FA-001 is verified in focused tests from Purchase Invoice line allocation through one canonical purchase journal, capitalization, book value/GL, and cancellation/reversal. A proven persistence-time allocation race and both direct and linked-account restore bypasses were fixed with invoice → line → allocation locking and transactional revalidation. Exact decimals, retries, source/status/period rejection, rollback, account-tree preservation, and lifecycle reconciliation passed 82 tests / 1,178 assertions. Status remains conditional only for simultaneous two-connection PostgreSQL contention, deferred to Wave 9/11.
- **Permissions:** centralized permission registration and operating-scope tests exist; exhaustive backend authorization for every sensitive mutation remains unverified.
- **Notifications:** operational notification focused tests passed. The global queue timing setting requires an inventory of transactional queued dispatches; no current defect was proven because inspected queued flows opt into after-commit.
- **Closing:** close/preflight/reconciliation services exist and targeted ledger tests passed. Full source coverage, carry-forward equality, failure recovery and locked-period regression were not executed.

## Highest-Risk Cross-Module Paths

1. `CashVoucherService` approval/cancellation -> cashbox report -> journal/ledger -> period close: canonical posting/reversal and statement-to-GL equality are verified for the repaired generic path; one legacy approved voucher requires controlled reconciliation before unconditional closure (`ACC-001`).
2. Maintenance issue/consumption/return -> inventory cost/layers -> maintenance expense and cost center: repaired with maintenance-specific source semantics, original issue accounting/dimension reversal, exact reports, and critical-review/QA approval; populated-company account-classification preflight and PostgreSQL contention evidence remain (`MAINT-001`).
3. Price List print-only flag -> centralized resolver -> quotations/orders/invoices: verified implemented in Wave 1B with critical-review GO and QA PASS; live cross-connection PostgreSQL contention was not executed (`SAL-003`).
4. Inventory valuation/COGS -> deliveries/returns/production -> GL and reconciliation center: exact maintenance-return position restoration, aggregate book valuation, and traceable delivery/return COGS reporting are verified implemented with independent critical-review GO and focused QA. Authorized populated-data reconciliation, full Production Run lifecycle evidence, and real PostgreSQL contention remain Wave 9/11 release evidence (`INV-001`, `COST-001`, `MAINT-001`).
5. Production issue/output/QC/receipt/closure: retry, duplicate-request, rollback, reversal-blocking, QC, costing-position, and closure reconciliation are release-tested; simultaneous PostgreSQL issue/receipt/closure contention remains blocked by the isolated SQLite harness (`PROD-001`).

## Unverified or Environment-Blocked

- Frontend unit/lint/static suites are not configured; browser/end-to-end execution was not part of the configured package scripts.
- Production database migration status. Sandbox success does not replace populated-production preflight, backup/restore rehearsal, or migration authorization.
- Live reconciliation datasets for supplier bank payment, inventory valuation, COGS, fixed assets, payroll, and closing.
- Exhaustive sensitive-route authorization and audit-actor coverage.
- Every queued side effect created inside a database transaction.
- Runtime browser proof for customer/supplier statement initial Select2 choices and every financial account picker.
- Authorized disposable-PostgreSQL contention proof for simultaneous production material issue, finished-goods receipt, and close-versus-transition attempts. SQLite `:memory:` remains the mandatory default test database and cannot supply row-lock evidence.
- Authorized disposable-PostgreSQL contention proof for simultaneous Fixed Asset allocation create/update/restore versus Purchase Invoice approval/cancellation. The focused SQLite suite proves persistence-time revalidation and rollback, not cross-connection lock behavior.
- Authorized disposable-PostgreSQL contention proof for simultaneous Maintenance material issue/return requests, plus controlled per-company `factory_maintenance_expense` classification preflight/remediation and `PostingAccountConfigurationAudit` before enabling the repaired posting path on populated installations.
- Authorized populated-data COST-001 reconciliation across delivery, saleable return, cancellation/repost, full Production Run lifecycle, inventory subledger, and COGS GL; focused SQLite fixtures prove the report contract but do not replace the Wave 9/11 release dataset.

Wave 0.6 changed only development/test runner configuration, the six bounded baseline UI/test surfaces, `pnpm-workspace.yaml`, and these finalization documents. No migration file, dependency version, lockfile, T3 business logic, Price List Wave 1 feature, production configuration, or source `mgypack` data/schema was changed. The five migrations were applied only to `mgypack_wave0_baseline`.
