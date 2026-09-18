# ERP Context Index

This is the starting point for ERP work. Read only the row relevant to the assigned domain, then inspect the live implementation and focused tests. Do not read historical context files end to end unless the task requires an architecture audit or context rewrite.

## Authority Order

1. Live code, database schema, routes, tests, and lockfiles in this checkout.
2. `AGENTS.md` and `docs/ai/` for mandatory workflow, routing, permissions, and budgets.
3. This index and the domain-specific files named below.
4. `CODEX_PROJECT_CONTEXT.md` for historical architecture and conventions only. Its recorded path, versions, route counts, dirty state, and inventory are explicitly historical.

Establish current facts with targeted commands:

```text
pwd -P
php --version
php artisan --version
php artisan route:list --path=<relevant-path> --except-vendor
composer show <relevant-package>
```

Observed during infrastructure validation on 2026-09-18: repository root `/mnt/Me/MB/Projects/ShortCoded/MgyPack/ERP`, PHP `8.5.10`, Laravel `12.61.0`, Node `22.23.1`, and pnpm `12.4.1`. These observations are not a substitute for rerunning the commands when versions matter.

## Minimum Context by Domain

| Domain | Start with | Then inspect only |
| --- | --- | --- |
| Authentication, users, roles, sessions | `modules/Auth`, relevant Auth section of `CODEX_PROJECT_CONTEXT.md` | Named route/controller/request/service/model/view/JS/lang and focused Auth tests |
| Core company, branch, period, shared UI | `modules/Core`, relevant Core section | Applicable scopes, middleware, shared components, menu/config, and focused tests |
| Accounting and balances | `modules/Accounting`, accounting rules in the historical context | Posting/reversal services, transactions, period/company/branch scopes, reconciliation tests; route as T3 |
| Finance foundations | `modules/Finance`, relevant Finance section | The exact controller/service/model/view and focused tests; escalate balance/posting effects to T3 |
| HR | `modules/HR`, relevant HR section | The exact workflow, permission boundaries, views/JS/lang, and focused tests |
| Inventory or warehouse | First prove a live module exists | Actual quantity/valuation/movement path and tests; route integrity work as T3 |
| Production or costing | First prove a live module exists | Actual issue/costing path and tests; route material/cost integrity work as T3 |
| Cross-module release/finalization | `docs/erp/FINALIZATION_PLAYBOOK.md` and `docs/erp/DEFINITION_OF_DONE.md` | Only changed modules, their integration boundaries, and release evidence |

## Handoff Contract

An explorer returns concise evidence: exact files and symbols, proven behavior, applicable invariants, allowed mutation boundary, focused tests, and unresolved questions. Worker, reviewer, and QA consume that handoff and must not repeat a full repository scan.
