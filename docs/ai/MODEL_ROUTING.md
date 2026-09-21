# Model Routing

Choose the plan with the lowest total paid-model usage that preserves correctness. Risk classification is based on behavior and data impact, not line or file count. Before any model call, use deterministic local tools when they can answer the question.

## Task Classes

### T0 Economy

- Read, search, grep, documentation lookup, and log inspection.
- Simple UI text or layout analysis, targeted discovery, and mechanical low-risk checks.

Use Codex Primary directly for tiny edits and local tools for lookup, logs, routes, diffs, and deterministic verification. Do not use Hermes or a Codex explorer for ordinary repository discovery.

### T1 Standard

- Localized implementation with a known root cause.
- Limited single-module changes and routine CRUD, validation, UI, or backend fixes.
- No material financial, inventory, authorization, isolation, or data-integrity risk.

Tiny or one/two-file corrections stay with Codex Primary. A coherent mechanical concern spanning roughly two to six closely related files may use one health-gated OpenCode Personal implementation attempt. Codex Primary reviews the diff and deterministic test output.

### T2 Deep

- Ambiguous root cause or multiple application layers.
- Meaningful integration, cross-module behavior, transactional workflow changes, or complex regression diagnosis.

Codex Primary maps the path and owns integration. Delegate only a well-understood mechanical slice whose implementation work is likely to exceed orchestration overhead. Use one OpenCode attempt by default. Do not automatically add explorer, reviewer, or QA agents.

### T3 Critical

- Accounting postings or reversals, balances, inventory valuation or quantity integrity.
- Production material issue, production costing, period closing, or balance carry-forward.
- Migrations involving existing data, destructive operations, authorization or security boundaries.
- Concurrency, idempotency, tenant/company/branch isolation, or other material data-integrity risks.

Codex Primary owns architecture, sensitive implementation/review, and the final GO / NO-GO decision. Deterministic regression and reconciliation evidence is mandatory. A model reviewer is optional only when a concrete residual semantic risk justifies additional usage; it is never automatic.

Critical domain rules override apparent task size: a ten-line accounting change may be T3, while a twenty-file cosmetic UI change may remain T1.

## Local Runtime Map

These identifiers were validated locally on 2026-09-21. Refresh the tool inventory before changing them; never infer a model or reasoning variant from its name.

| Route | Model | Intended use | Status |
| --- | --- | --- | --- |
| Codex | Current session model | Orchestration, direct small work, integration, final review and verification | Active |
| Hermes cheap | `inclusionai/ling-3.0-flash-fin:free` via Nous | Explicit bounded semantic research where local search is insufficient | Active and smoke-tested |
| Hermes strong | `poolside/laguna-s-2.1:free` via Nous | Selective explicit fallback, deeper implementation, or justified semantic review | Active and smoke-tested |
| OpenCode Personal | `opencode/ling-3.0-flash-fin-free`, `opencode/mimo-v2.5-free`, and `opencode/muse-spark-1.3-contributor-free` | Health probe plus T1/T2 implementation and bounded direct delegation | Active and completion-tested with the Personal account |
| KiosAPI | None | Not part of active routing | Disabled and non-blocking |

When independent semantic review is justified, it requires a fresh context and a review-only purpose. A different model family is preferred when its verified capability is appropriate, but independence must not be faked with an unverified model or variant.

Local Qwen 27B is optional/manual only. It is not an OpenCode role, automatic fallback, or Codex delegation target because current-hardware latency is impractical, and it must never auto-start.

## Credit-First Routing Matrix

| Task class | Default station | Fallback |
| --- | --- | --- |
| Tiny edit, including a one-file Blade/UI change | Codex Primary | None |
| Simple lookup or path tracing | Local tools (`rg`, Git, routes, logs) | Codex Primary reasoning |
| Small/medium mechanical concern, about 2-6 related files | One OpenCode free implementation attempt | Codex Primary; one explicit free fallback only with evidence |
| Larger independent mechanical work | At most two concurrent OpenCode tasks with disjoint files | Codex Primary |
| T3 accounting, inventory, migration, authorization, isolation, concurrency, or destructive behavior | Codex Primary | Optional risk-justified model review |
| Normal QA | Tests, browser/runtime checks, syntax, Pint, diff checks, safe DB assertions | Codex Primary diagnoses |

The normal interface is `scripts/ai/delegate-agent`. Its `--task-class ... --dry-run` mode reports routing without calling a provider. Actual automatic delegation is limited to one OpenCode attempt for a mapped mechanical slice. `--allow-fallback` explicitly permits one free Hermes fallback; it is not a default.

```bash
scripts/ai/delegate-agent --task-class lookup --dry-run
scripts/ai/delegate-agent --task-class mechanical --role worker --repo /path/to/worktree --title "Scoped change" --prompt-file /tmp/task.md --mode edit
```

`scripts/ai/delegate-hermes` remains available for an explicit cheap/strong route when semantic reasoning is useful. `scripts/ai/delegate-opencode --health` uses a cheap completion instead of `provider.list` because OpenCode 2.0.11 returns an empty provider inventory for a working Personal account. Successful probes are cached outside the repository for ten minutes; task and health runs are time-bounded. Direct OpenCode sessions preserve raw JSONL and support `--session` resume. KiosAPI credentials and models are intentionally absent from active configuration.

Delegate prompts are size-limited and must contain only exact paths, modifications, reference, invariants, and focused verification. The router records provider, model, task category, success/failure/timeout, and duration in a small runtime-local TSV. After repeated failures/timeouts for the same provider/model/category within the configured window, its circuit opens temporarily.

One business-code writer owns each mutation boundary. Read-only discovery may use the main checkout. Edit work should use a linked Git worktree; a harmless temporary test target may use `--allow-shared-tree`. Codex and review roles remain read-only on an active writer's boundary until it returns.

## Failure and Fallback Budget

- OpenCode disabled, unhealthy, stalled, circuit-open, or failed: Codex Primary normally continues directly.
- A second free-provider attempt requires explicit `--allow-fallback` and current evidence that it is likely to save meaningful Codex work.
- Hermes Cheap does not automatically escalate to Hermes Strong.
- Maximum delegated model attempts for one implementation slice: two.
- Worker output conflicts with live code: live code wins; Codex verifies directly.
- Never cycle providers, retry an open circuit, or respawn a role only to evade its budget.

## Escalation

- Start at the lowest justified tier; elapsed time alone is not a reason to escalate.
- Return delegated implementation to Codex Primary after the normal single attempt fails, unless one evidence-backed free fallback was explicitly selected.
- Do not escalate merely because a task is large. Do not silently downgrade T3 work to a cheaper runtime.
- XHigh or maximum reasoning is never the default. Use it only for a recorded, materially difficult review or synthesis.
- Ultra or heavy multi-agent execution is reserved for genuinely large work decomposable into independent tracks. Never use Ultra for a single localized bug.
- Reclassification never bypasses the budget policy. Narrow the scope or record the new evidence and escalation reason.

Routing metadata is concise, not chain-of-thought:

```text
risk_class:
execution_station:
selected_provider:
review_required:
delegation_savings_reason: # only when delegating
reason_for_escalation: # only when applicable
```
