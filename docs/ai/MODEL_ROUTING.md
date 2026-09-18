# Model Routing

Start at the lowest justified tier. Risk classification is based on behavior and data impact, not line or file count.

## Task Classes

### T0 Economy

- Read, search, grep, documentation lookup, and log inspection.
- Simple UI text or layout analysis, targeted discovery, and mechanical low-risk checks.

Use `explorer` or `qa` only.

### T1 Standard

- Localized implementation with a known root cause.
- Limited single-module changes and routine CRUD, validation, UI, or backend fixes.
- No material financial, inventory, authorization, isolation, or data-integrity risk.

Use `explorer` only when needed, then `worker`, then targeted `qa`.

### T2 Deep

- Ambiguous root cause or multiple application layers.
- Meaningful integration, cross-module behavior, transactional workflow changes, or complex regression diagnosis.

Use `explorer`, then `worker` or `deep_worker` according to confirmed complexity, then `reviewer` when transactional or integration risk exists, then `qa`.

### T3 Critical

- Accounting postings or reversals, balances, inventory valuation or quantity integrity.
- Production material issue, production costing, period closing, or balance carry-forward.
- Migrations involving existing data, destructive operations, authorization or security boundaries.
- Concurrency, idempotency, tenant/company/branch isolation, or other material data-integrity risks.

Use `explorer`, then `deep_worker`, then an independent `critical_reviewer`, then `qa` and relevant regression or reconciliation checks.

Critical domain rules override apparent task size: a ten-line accounting change may be T3, while a twenty-file cosmetic UI change may remain T1.

## Local Model Map

These identifiers were validated in the local pilot. Refresh the tool inventory before changing them; never infer a model or reasoning variant from its name.

| Role | Codex | OpenCode | Tier |
| --- | --- | --- | --- |
| `orchestrator` | `gpt-5.6-sol`, medium | `opencode/muse-spark-1.3-contributor-free#medium` | Standard strong |
| `explorer` | `gpt-5.6-terra`, medium | `opencode/ling-3.0-flash-fin-free` (no variants exposed) | Efficient |
| `worker` | `gpt-5.6-sol`, medium | `opencode/muse-spark-1.3-contributor-free#medium` | Reliable coding |
| `deep_worker` | `gpt-5.6-sol`, high | `opencode/muse-spark-1.3-contributor-free#high` | Deep |
| `reviewer` | `gpt-5.6-terra`, high | `opencode/muse-spark-1.3-contributor-free#high` | Strong independent review |
| `critical_reviewer` | `gpt-5.6-sol`, high | `opencode/muse-spark-1.3-contributor-free#high` | Strong independent review |
| `qa` | `gpt-5.6-terra`, medium | `opencode/ling-3.0-flash-fin-free` (no variants exposed) | Efficient verification |

Review independence requires a fresh context and a review-only purpose. A different model family is preferred when its verified capability is appropriate, but independence must not be faked with an unverified model or variant.

## Escalation

- Start at the lowest justified tier; elapsed time alone is not a reason to escalate.
- Escalate for discovered risk, ambiguity, multi-layer complexity, failed verification, or evidence that the current tier cannot safely resolve the task.
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
