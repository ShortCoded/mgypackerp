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
| Sales | PARTIAL | PARTIAL | PARTIAL | PARTIAL | UNVERIFIED | NEEDS FIX | PARTIAL | 0 |
| Purchases | PARTIAL | UNVERIFIED | PARTIAL | PARTIAL | UNVERIFIED | UNVERIFIED | PARTIAL | 0 |
| Inventory | PARTIAL | PARTIAL | PARTIAL | PARTIAL | UNVERIFIED | NEEDS FIX | PARTIAL | 0 |
| Accounts & Costing | NEEDS FIX | NEEDS FIX | NEEDS FIX | PARTIAL | UNVERIFIED | NEEDS FIX | PARTIAL | 1 |
| Production | PARTIAL | UNVERIFIED | UNVERIFIED | PARTIAL | UNVERIFIED | PARTIAL | PARTIAL | 0 |
| Quality | PARTIAL | PARTIAL | NOT APPLICABLE | PARTIAL | UNVERIFIED | UNVERIFIED | PARTIAL | 0 |
| Maintenance | NEEDS FIX | NEEDS FIX | NEEDS FIX | PARTIAL | UNVERIFIED | NEEDS FIX | PARTIAL | 1 |
| HR | PARTIAL | PARTIAL | PARTIAL | NOT APPLICABLE | PARTIAL | PARTIAL | PARTIAL | 0 |
| Fixed Assets | PARTIAL | PARTIAL | PARTIAL | NOT APPLICABLE | UNVERIFIED | PARTIAL | PARTIAL | 0 |
| Cross-module integration | NEEDS FIX | NEEDS FIX | NEEDS FIX | PARTIAL | UNVERIFIED | NEEDS FIX | PARTIAL | 0 |
| Permissions & UI | PARTIAL | UNVERIFIED | NOT APPLICABLE | NOT APPLICABLE | UNVERIFIED | PARTIAL | PARTIAL | 0 |
| Notifications | PARTIAL | PARTIAL | NOT APPLICABLE | NOT APPLICABLE | UNVERIFIED | UNVERIFIED | PARTIAL | 0 |
| Reports / print / export | NEEDS FIX | UNVERIFIED | NEEDS FIX | NEEDS FIX | PARTIAL | NEEDS FIX | PARTIAL | 0 |
| Closing / carry-forward | PARTIAL | UNVERIFIED | UNVERIFIED | UNVERIFIED | PARTIAL | UNVERIFIED | PARTIAL | 0 |
| Production release readiness | BLOCKED | BLOCKED | BLOCKED | BLOCKED | UNVERIFIED | BLOCKED | PARTIAL | 1 |

The Production release readiness blocker count is now **1** (valuable/production-target schema parity). Total release blockers: **3**.

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

- **Sales:** pricing snapshots and centralized resolution exist. Wave 1A added verified Price List clone and transactional percentage adjustment with scoped authorization, locking, audit, rollback, decimal rounding, and snapshot-invariance coverage. Print-only semantics and Price List outputs remain absent; Sales report totals and document signatures remain incomplete.
- **Purchases:** bank supplier-payment posting/reversal exists through the canonical journal path. Exact supplier statement, bank statement, AP and GL equality remains unverified.
- **Inventory:** posted inventory transactions are the quantity ledger and posted line cost is book cost. The aggregate monetary valuation/reporting requirement remains incomplete; do not substitute selling price or method simulations.
- **Accounts & Costing:** generic approved cash vouchers are visible in cashbox reporting without a canonical journal, producing a confirmed dual truth and a period-close blocker.
- **Production:** strong locks, snapshots, quantity guards, QC gates and WIP services exist. Duplicate/resume and injected rollback/recovery evidence remains incomplete.
- **Quality:** production quality lifecycle and QC-hold inventory movements exist with reversal guards. Notification delivery and complete operational regression were not verified.
- **Maintenance:** quantity issue/return is implemented, but accounting uses generic adjustment gain/loss and consumption has no authoritative cost movement.
- **HR:** payroll reconciliation exists and its focused test passed. Target schema parity is blocked by a pending payroll financial-chain migration on the inspected database.
- **Fixed Assets:** account-tree relationships, lifecycle journals and reversal controls exist. Purchase-to-capitalization duplicate-recognition reconciliation was not executed.
- **Permissions:** centralized permission registration and operating-scope tests exist; exhaustive backend authorization for every sensitive mutation remains unverified.
- **Notifications:** operational notification focused tests passed. The global queue timing setting requires an inventory of transactional queued dispatches; no current defect was proven because inspected queued flows opt into after-commit.
- **Closing:** close/preflight/reconciliation services exist and targeted ledger tests passed. Full source coverage, carry-forward equality, failure recovery and locked-period regression were not executed.

## Highest-Risk Cross-Module Paths

1. `CashVoucherService` approval/cancellation -> cashbox report -> journal/ledger -> period close: confirmed operational-versus-GL divergence (`ACC-001`).
2. Maintenance issue/consumption/return -> inventory cost -> adjustment accounts -> maintenance cost center: confirmed classification and cost-lineage defect (`MAINT-001`).
3. Price List print-only flag -> centralized resolver -> quotations/orders/invoices: missing existing-data property and backend exclusion (`SAL-003`).
4. Inventory valuation/COGS -> deliveries/returns/production -> GL and reconciliation center: core postings exist, aggregate traceable reports and full reconciliation do not (`INV-001`, `COST-001`).
5. Production issue/output/QC/receipt/closure: controls exist, but retry and rollback integrity is not release-proven (`PROD-001`).

## Unverified or Environment-Blocked

- Frontend unit/lint/static suites are not configured; browser/end-to-end execution was not part of the configured package scripts.
- Production database migration status. Sandbox success does not replace populated-production preflight, backup/restore rehearsal, or migration authorization.
- Live reconciliation datasets for supplier bank payment, inventory valuation, COGS, fixed assets, payroll, and closing.
- Exhaustive sensitive-route authorization and audit-actor coverage.
- Every queued side effect created inside a database transaction.
- Runtime browser proof for customer/supplier statement initial Select2 choices and every financial account picker.

Wave 0.6 changed only development/test runner configuration, the six bounded baseline UI/test surfaces, `pnpm-workspace.yaml`, and these finalization documents. No migration file, dependency version, lockfile, T3 business logic, Price List Wave 1 feature, production configuration, or source `mgypack` data/schema was changed. The five migrations were applied only to `mgypack_wave0_baseline`.
