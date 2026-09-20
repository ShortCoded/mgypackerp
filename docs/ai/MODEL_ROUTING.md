# Model Routing

Start at the lowest justified tier. Risk classification is based on behavior and data impact, not line or file count.

## Task Classes

### T0 Economy

- Read, search, grep, documentation lookup, and log inspection.
- Simple UI text or layout analysis, targeted discovery, and mechanical low-risk checks.

Default to the OpenCode `explorer` (Ling). Use `qa` only for a bounded verification task. Codex should not spend substantial reasoning budget on T0.

### T1 Standard

- Localized implementation with a known root cause.
- Limited single-module changes and routine CRUD, validation, UI, or backend fixes.
- No material financial, inventory, authorization, isolation, or data-integrity risk.

Default to the OpenCode `worker` (MiMo). Use `explorer` only when needed, then targeted `qa` where useful. Add a reviewer only when the discovered risk justifies it.

### T2 Deep

- Ambiguous root cause or multiple application layers.
- Meaningful integration, cross-module behavior, transactional workflow changes, or complex regression diagnosis.

Default to the OpenCode `deep_worker` (MiMo): targeted `explorer` when required, then `deep_worker`, `reviewer`, and `qa`. Codex controls scope and verifies evidence; it is not the default T2 writer.

### T3 Critical

- Accounting postings or reversals, balances, inventory valuation or quantity integrity.
- Production material issue, production costing, period closing, or balance carry-forward.
- Migrations involving existing data, destructive operations, authorization or security boundaries.
- Concurrency, idempotency, tenant/company/branch isolation, or other material data-integrity risks.

Codex is the primary owner and makes the final architecture and GO / NO-GO decision. It may delegate bounded discovery to `explorer`, safe subparts to `deep_worker`, independent challenge to `critical_reviewer`, and verification to `qa`, followed by relevant regression or reconciliation checks.

Critical domain rules override apparent task size: a ten-line accounting change may be T3, while a twenty-file cosmetic UI change may remain T1.

## Local Model Map

These identifiers were validated in the local pilot. Refresh the tool inventory before changing them; never infer a model or reasoning variant from its name.

| Role | Codex | OpenCode | Tier |
| --- | --- | --- | --- |
| `orchestrator` | `gpt-5.6-sol`, medium | `opencode/muse-spark-1.3-contributor-free#medium` | Standard strong |
| `explorer` | `gpt-5.6-terra`, medium | `opencode/ling-3.0-flash-fin-free` (no variants exposed) | Efficient |
| `worker` | `gpt-5.6-sol`, medium | `opencode/mimo-v2.5-free` (no variants exposed) | Reliable coding |
| `deep_worker` | `gpt-5.6-sol`, high | `opencode/mimo-v2.5-free` (no variants exposed) | Deep |
| `reviewer` | `gpt-5.6-terra`, high | `opencode/mimo-v2.5-free` (no variants exposed) | Strong independent review |
| `critical_reviewer` | `gpt-5.6-sol`, high | `opencode/mimo-v2.5-free` (no variants exposed) | Strong independent review |
| `qa` | `gpt-5.6-terra`, medium | `opencode/ling-3.0-flash-fin-free` (no variants exposed) | Efficient verification |

Review independence requires a fresh context and a review-only purpose. A different model family is preferred when its verified capability is appropriate, but independence must not be faked with an unverified model or variant.

Local Qwen 27B is optional/manual only. It is not an OpenCode role, automatic fallback, or Codex delegation target because current-hardware latency is impractical, and it must never auto-start.

## Default Delegation and Resume

Codex invokes OpenCode non-interactively through `scripts/ai/delegate-opencode`. New work uses `--agent`, `--title`, and `--prompt-file`; the helper also pins the role's configured model because OpenCode 2.0.8 can otherwise retain the default primary model while applying a subagent's prompt and permissions. Reviewer corrections resume the same writer through `--session` and a correction prompt file. The helper preserves raw JSON and exit status and never uses OpenCode's `--auto` flag.

```bash
scripts/ai/delegate-opencode --agent deep_worker --title "Task title" --prompt-file /tmp/task.md
scripts/ai/delegate-opencode --session <session-id> --prompt-file /tmp/correction.md
```

One business-code writer owns each mutation boundary. Codex and review roles remain read-only on that boundary until the writer returns. Parallel writers require isolated boundaries or worktrees.

## Escalation

- Start at the lowest justified tier; elapsed time alone is not a reason to escalate.
- Escalate OpenCode implementation to Codex only for repeated provider/runtime failure, a material reviewer NO-GO that the resumed writer cannot resolve, exhausted safe budget, shared accounting or inventory architecture ambiguity, migration/schema risk, PostgreSQL concurrency, security privilege boundaries, closing, reconciliation, or release gates.
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
