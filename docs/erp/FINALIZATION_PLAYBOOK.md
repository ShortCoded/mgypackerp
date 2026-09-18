# ERP Finalization Playbook

Use this playbook to finish an already-approved ERP change. It does not authorize a new implementation wave, deployment, migration execution, or global configuration promotion.

## 1. Confirm Scope and Risk

- Name the changed module, user-visible behavior, mutation boundary, and acceptance criteria.
- Classify the work with `docs/ai/MODEL_ROUTING.md` before delegation.
- Treat accounting, balances, inventory, production, existing-data migrations, authorization, isolation, concurrency, and idempotency as T3.

## 2. Reuse Evidence

- Start from `docs/erp/INDEX.md` and the explorer handoff.
- Inspect only the changed execution path, closest working reference, relevant invariants, and focused tests.
- Do not repeat a whole-repository or whole-history scan without new evidence that requires it.

## 3. Review the Integrated Change

- Confirm the diff stays inside the approved boundary and preserves company, branch, financial-period, permission, translation, formatting, transaction, audit, and soft-delete behavior.
- Require independent review for T2 integration risk and critical review for T3.
- Confirm migrations and data transformations are reversible or have an explicit recovery and reconciliation plan.

## 4. Verify Proportionally

- Run focused tests first, then only the adjacent regression suite justified by risk.
- For T3, include transaction, rollback, duplicate-request/idempotency, isolation, and reconciliation evidence as applicable.
- Record commands actually run, outcomes, skipped checks, and remaining risk.

## 5. Release Gate

- Apply `docs/erp/DEFINITION_OF_DONE.md`.
- Inspect final git status and diff for unrelated or generated changes.
- Stop before push, deployment, destructive database work, or global promotion unless separately authorized.
