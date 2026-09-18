# ERP Definition of Done

An ERP change is done only when every applicable item below has evidence.

## Scope and Correctness

- The requested behavior and acceptance criteria are satisfied without unrelated changes.
- The implementation follows the existing route, controller/request, service/query, model, view/JS/lang, and test conventions.
- Company, branch, financial-period, permission, translation, formatting, transaction, audit, and soft-delete boundaries are preserved.

## Data Integrity and Safety

- T3 work has independent critical review.
- Financial, inventory, production, or migration work has reconciliation evidence and defined failure/rollback behavior.
- Concurrency, duplicate requests, authorization, and tenant isolation are tested where applicable.
- No destructive database action, push, deployment, or global promotion occurred without explicit authorization.

## Verification

- Focused automated tests pass; adjacent regressions are run in proportion to risk.
- PHP changes are formatted with the required Pint command and relevant frontend checks are complete.
- Actual commands, results, gaps, and unverified risks are recorded.
- The final diff contains only the approved files and preserves unrelated user changes.

## Context and Handoff

- Stable architectural changes update the appropriate current documentation.
- Routine task notes and duplicated historical context are not committed.
- Explorer evidence was handed forward so worker, reviewer, and QA did not repeat broad discovery.
