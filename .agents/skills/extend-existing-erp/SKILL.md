---
name: extend-existing-erp
description: Modify an existing MgyPack ERP screen or workflow by locating its current implementation, shared services/components, and a working reference before editing. Use for feature fixes and extensions; not for greenfield applications.
---

# Extend Existing ERP Behavior

Start from the real request path: UI field or action, validation, controller, service/query, model relationships, response, and tests. Identify the shared component or service and the closest working screen before deciding where the change belongs.

Preserve the existing contracts that apply:

- company, branch, and financial-period scoping;
- permission and role checks, including the difference between visibility and current responsibility;
- translations, locale direction, date/number formatting, and the established UI framework;
- soft deletes, statuses, locks, audit trails, sessions, and notification semantics;
- route names, public document identifiers, pagination, and query efficiency.

Use Laravel Boost documentation, schema, log, and browser tools when relevant. Confirm suspected data shapes with safe aggregate/read-only queries. Reuse an existing destination screen or component; create a small missing entry point only when no truthful existing view can represent the data.

Add or update focused Pest coverage with isolated factories. Test the successful path, empty/error states, permission and scope boundaries, and the state transition that should change counts or lists. Review the final diff against unrelated work and run Pint on only the PHP files in scope when other uncommitted PHP changes belong to someone else.

For full-system audit starting points — pre-existing inventory documents, translation architecture, and report-coverage approach — see `references/audit-starting-points.md`.

## Full-System Audit and Repair Missions

When the task is a whole-system audit and repair of an existing ERP — not a single screen or workflow — follow this sequence before any modification:

### 1. Establish the baseline (read-only)

Run these before touching code:

- `git status --short` and `git log --oneline -20` — preserve unrelated uncommitted work; know the branch head.
- Read `composer.json` and `package.json` — record the actual framework and package versions in use.
- `php artisan route:list` (no `--compact`/`--format` flags exist) — save the full output path for later cross-referencing.
- Inventory translation dictionaries: `resources/lang/ar/*.php`, `resources/lang/en/*.php`, `resources/lang/ar.json`. Note any pre-existing audit files such as `LOCALIZATION_AUDIT.md` or `FINALIZATION_STATUS.md` — they are prior art, not noise.
- Run a focused passing test subset (e.g. `php artisan test --compact <files>`) and `pnpm run build` — record existing failures separately from anything introduced later.

### 2. Launch parallel read-only audit workers (max 3)

Cover three independent angles so they don't step on each other:

- **Worker A — Routes / Runtime / Navigation**: routes, controllers, middleware, route model binding, views, permissions, 404/403/500 behavior, broken links.
- **Worker B — Localization / UI / Structural Integrity**: translation keys vs dictionaries, hard-coded strings, RTL/LTR, shared components, missing files.
- **Worker C — ERP Workflows / Reports / Data Integrity**: module completeness, report coverage matrix, report reconciliation, soft deletes, orphan relations, cross-scope leakage.

Workers are read-only until the consolidation step. Collect findings first.

### 3. WAIT for all audit workers to complete before modifying anything

Do not start the modification phase while any audit worker is still running. The user may steer you to pause here — honor it. Read the live transcripts (`delegate_task` returns them) if you need progress visibility, but do not begin code changes until every read-only worker has returned its findings.

### 4. Consolidate into one remediation plan

Merge the three workers' findings into a single plan. Classify each issue by severity and root cause. Distinguish:

- definitely required repairs;
- strongly justified missing reports (only when the existing data model and workflow clearly require them);
- optional / business-decision items (list separately, do not implement).

### 5. Modify with one writer at a time

After consolidation, only one writer modifies the working tree at a time. Separate genuinely independent changes only. After each repair batch: run focused tests, inspect affected routes, inspect logs, verify the associated UI/workflow.

### Key commands (this ERP)

| Task | Command |
| --- | --- |
| Full route inventory | `php artisan route:list` (output is large; save to file) |
| Focused tests | `php artisan test --compact tests/Feature/<File>.php ...` |
| Frontend build | `pnpm run build` |
| Framework versions | `php artisan --version`; read `composer.json` |
| Translation dict diff | compare `resources/lang/ar/*.php` with `resources/lang/en/*.php` key-by-key |

### Pitfalls

- **Do not start modifying while audit workers are running.** A read-only audit phase and a modification phase are different phases; mixing them loses the audit findings and risks conflicting writes. If the user tells you to wait, wait.
- Do not treat an existing audit document (e.g. `LOCALIZATION_AUDIT.md`) as the current truth — verify its claims against the live code since the document may predate recent changes.
- Do not assume a route, menu item, or report exists in working form just because it is defined. A route can point to a missing controller method; a menu item can point to a route that 404s; a report can render numbers from the wrong query.
- When a shared component or service is the root cause of multiple symptoms, fix it centrally rather than patching each consumer independently.
- A green test suite is not sufficient if browser routes still throw JavaScript or runtime errors — verify the rendered application too.
