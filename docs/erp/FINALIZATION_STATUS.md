# ERP Finalization Status

Wave 0 status as of 2026-09-19. `VERIFIED` means the relevant execution path and effects were evidenced; the presence of routes, screens, services, or tests alone is not sufficient.

## Baseline Verification

| Area | Result |
| --- | --- |
| Repository | `main`, one commit ahead of `origin/main`; clean before audit |
| Runtime | PHP 8.5.10; Laravel 12.61.0; Composer 2.10.1; Node 22.23.1; pnpm 12.4.1 |
| Database/runtime drivers | PostgreSQL; database queue/cache/session; local debug enabled |
| Routes/modules | 1,988 non-vendor routes; 11 live top-level modules; Quality is inside Production |
| Migrations | BLOCKED: five migrations pending in the inspected database; production state not inferred |
| Scheduling | Presence cleanup, due notifications, and bounded database queue worker scheduled each minute |
| Focused PHP tests | VERIFIED: 51 passed, 842 assertions, 61.52 seconds |
| Full PHP/frontend/browser tests | UNVERIFIED |
| Production frontend build | BLOCKED: pnpm ignored unapproved esbuild build scripts |

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
| Production release readiness | BLOCKED | BLOCKED | BLOCKED | BLOCKED | UNVERIFIED | BLOCKED | BLOCKED | 3 |

Total release blockers: **5**.

## Evidence-Based Domain Notes

- **Sales:** pricing snapshots and centralized resolution exist. Clone, percentage adjustment, print-only semantics, and Price List outputs are absent. Sales report totals and document signatures are incomplete.
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

- Full Pest, frontend unit, browser, and end-to-end suites.
- Production frontend bundle in the current pnpm approval environment.
- Production database migration status; only the inspected local target is known.
- Live reconciliation datasets for supplier bank payment, inventory valuation, COGS, fixed assets, payroll, and closing.
- Exhaustive sensitive-route authorization and audit-actor coverage.
- Every queued side effect created inside a database transaction.
- Runtime browser proof for customer/supplier statement initial Select2 choices and every financial account picker.

Wave 0 changed only `docs/erp/FINALIZATION_BACKLOG.md` and `docs/erp/FINALIZATION_STATUS.md`. No ERP business code, tests, migrations, configuration, dependencies, data, or agent infrastructure were changed.
