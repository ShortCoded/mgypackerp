# Model Routing

Start at the lowest justified tier. Risk classification is based on behavior and data impact, not line or file count.

## Task Classes

### T0 Economy

- Read, search, grep, documentation lookup, and log inspection.
- Simple UI text or layout analysis, targeted discovery, and mechanical low-risk checks.

Default to Hermes cheap through the `explorer` role. Use `qa` only for bounded verification. Codex may work directly when delegation overhead would exceed the task.

### T1 Standard

- Localized implementation with a known root cause.
- Limited single-module changes and routine CRUD, validation, UI, or backend fixes.
- No material financial, inventory, authorization, isolation, or data-integrity risk.

Default to the health-gated OpenCode Personal route through the `worker` role, with one fallback to Hermes strong. Use `explorer` only when needed, then targeted `qa` where useful. Add a reviewer only when the discovered risk justifies it.

### T2 Deep

- Ambiguous root cause or multiple application layers.
- Meaningful integration, cross-module behavior, transactional workflow changes, or complex regression diagnosis.

Default to the health-gated OpenCode Personal route through the `deep_worker` role: targeted Hermes-cheap discovery when required, then `deep_worker`, `reviewer`, and `qa`. An unavailable or failed OpenCode invocation falls back once to Hermes strong. Codex controls scope and verifies evidence; it is not a blind dispatcher.

### T3 Critical

- Accounting postings or reversals, balances, inventory valuation or quantity integrity.
- Production material issue, production costing, period closing, or balance carry-forward.
- Migrations involving existing data, destructive operations, authorization or security boundaries.
- Concurrency, idempotency, tenant/company/branch isolation, or other material data-integrity risks.

Codex is the primary owner and makes the final architecture and GO / NO-GO decision. It may delegate bounded discovery to `explorer`, safe subparts to `deep_worker`, independent challenge to `critical_reviewer`, and verification to `qa`, followed by relevant regression or reconciliation checks.

Critical domain rules override apparent task size: a ten-line accounting change may be T3, while a twenty-file cosmetic UI change may remain T1.

## Local Runtime Map

These identifiers were validated locally on 2026-09-21. Refresh the tool inventory before changing them; never infer a model or reasoning variant from its name.

| Route | Model | Intended use | Status |
| --- | --- | --- | --- |
| Codex | Current session model | Orchestration, direct small work, integration, final review and verification | Active |
| Hermes cheap | `inclusionai/ling-3.0-flash-fin:free` via Nous | Search, discovery, summarization, repetitive low-risk analysis, QA | Active and smoke-tested |
| Hermes strong | `poolside/laguna-s-2.1:free` via Nous | Non-trivial implementation, deeper investigation, multi-file isolated work, independent review | Active and smoke-tested |
| OpenCode Personal | `opencode/ling-3.0-flash-fin-free`, `opencode/mimo-v2.5-free`, and `opencode/muse-spark-1.3-contributor-free` | Health probe plus T1/T2 implementation and bounded direct delegation | Active and completion-tested with the Personal account |
| KiosAPI | None | Not part of active routing | Disabled and non-blocking |

Review independence requires a fresh context and a review-only purpose. A different model family is preferred when its verified capability is appropriate, but independence must not be faked with an unverified model or variant.

Local Qwen 27B is optional/manual only. It is not an OpenCode role, automatic fallback, or Codex delegation target because current-hardware latency is impractical, and it must never auto-start.

## Automatic Delegation

Codex decides whether delegation is useful without waiting for the user to name a runtime. The normal interface is `scripts/ai/delegate-agent`, which returns one structured JSON object containing runtime, model, status, exit code, output, errors, and usage. Role routing is deliberately simple: `explorer` and `qa` use Hermes cheap; `worker`, `deep_worker`, `reviewer`, and `critical_reviewer` use Hermes strong. When OpenCode is enabled, automatic routing may try it for `worker` and `deep_worker`; any failed health check or invocation falls back once to Hermes strong.

```bash
scripts/ai/delegate-agent --role explorer --repo "$PWD" --title "Trace screen" --prompt-file /tmp/task.md --mode read-only
scripts/ai/delegate-agent --role deep_worker --repo /path/to/worktree --title "Scoped change" --prompt-file /tmp/task.md --mode edit
```

`scripts/ai/delegate-hermes` remains available for an explicit cheap/strong route. `scripts/ai/delegate-opencode --health` uses a real, cheap completion instead of `provider.list` because OpenCode 2.0.11 returns an empty provider inventory for a working Personal account. Successful probes are cached outside the repository for ten minutes; use `--refresh` to force a live check. Direct OpenCode sessions preserve raw JSONL and support `--session` resume. KiosAPI credentials and models are intentionally absent from active configuration.

One business-code writer owns each mutation boundary. Read-only discovery may use the main checkout. Edit work should use a linked Git worktree; a harmless temporary test target may use `--allow-shared-tree`. Codex and review roles remain read-only on an active writer's boundary until it returns.

## Fallbacks

- OpenCode disabled, unhealthy, or failed: fall back once to Hermes strong.
- Hermes cheap failed, timed out, or returned unusable evidence: retry the narrowed task once with Hermes strong.
- Hermes strong failed: Codex diagnoses directly and either continues in scope or reports the genuine blocker.
- Worker output conflicts with live code: live code wins; Codex verifies directly.
- Never cycle providers indefinitely or respawn the same role only to evade its budget.

## Escalation

- Start at the lowest justified tier; elapsed time alone is not a reason to escalate.
- Escalate delegated implementation to Codex only after the usable local route fails, a material reviewer NO-GO remains unresolved, the safe budget is exhausted, or critical-domain ambiguity requires Codex ownership.
- Do not escalate merely because a task is large. Do not silently downgrade T3 work to a cheaper runtime.
- XHigh or maximum reasoning is never the default. Use it only for a recorded, materially difficult review or synthesis.
- Ultra or heavy multi-agent execution is reserved for genuinely large work decomposable into independent tracks. Never use Ultra for a single localized bug.
- Reclassification never bypasses the budget policy. Narrow the scope or record the new evidence and escalation reason.

Routing metadata is concise, not chain-of-thought:

```text
risk_class:
selected_role:
selected_model_tier:
review_required:
reason_for_escalation: # only when applicable
```
