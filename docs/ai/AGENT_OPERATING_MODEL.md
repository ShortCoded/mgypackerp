# Agent Operating Model

This repository uses Codex as the master orchestrator and OpenCode V2 as the default execution runtime for eligible T0, T1, and T2 work. `AGENTS.md` remains the mandatory project instruction layer, and `docs/erp/INDEX.md` is the current ERP navigation index. `CODEX_PROJECT_CONTEXT.md` is historical architectural context, not a source for current path or runtime versions. Agents load only the sections and files relevant to the assigned task.

## Roles

| Role | Responsibility | Mutation authority |
| --- | --- | --- |
| `orchestrator` | Classify risk, choose roles and model tier, define boundaries, coordinate handoffs, and own final verification. It should not normally implement non-trivial features. | Coordination; direct edits only for genuinely trivial work or agent infrastructure. |
| `explorer` | Trace the smallest relevant execution path and return evidence, likely risk, affected files, and focused verification targets. | Read-only. |
| `worker` | Implement a bounded T1 change with a known or confirmed cause. | Scoped project edits; no delegation. |
| `deep_worker` | Implement a bounded T2/T3 change that needs deeper reasoning across layers. | Scoped project edits; no delegation. |
| `reviewer` | Independently review a T2 or integration-sensitive change without repeating the full discovery audit. | Read-only. |
| `critical_reviewer` | Independently challenge T3 correctness, transactions, reconciliation, authorization, isolation, concurrency, and recovery behavior. | Read-only. |
| `qa` | Run targeted tests and inspections against stated acceptance criteria; report evidence and gaps. | No business-code edits by default. |

There is no model-router agent. Model selection is a deterministic orchestrator responsibility governed by `MODEL_ROUTING.md`.

## Operating Contract

1. Record `risk_class`, `selected_role`, `selected_model_tier`, and `review_required` before delegation. Record `reason_for_escalation` only when escalation occurs.
2. Inspect existing infrastructure or behavior before changing it. For ERP work, use `docs/erp/INDEX.md` to select the minimum relevant context, then inspect the actual implementation, the closest working reference, and focused tests.
3. Use one writer. Parallel work is allowed only for independent tracks with non-overlapping mutation boundaries. Normal tasks should not consume all available concurrency.
4. Pass a concise handoff contract: objective, proven evidence with file or symbol references, allowed files or areas, invariants, acceptance criteria, tests to run, and known unknowns.
5. Do not repeat discovery already supported by evidence. Reviewers inspect the diff and named risk areas; they do not redo the explorer's entire scan.
6. The orchestrator inspects the integrated diff and verification output. Agent completion is not proof of correctness.

## Codex Self-Delegation Contract

Before substantial implementation, Codex must classify the task, define the mutation boundary, and decide whether OpenCode can own it. T0, T1, and T2 work is delegated by default using `scripts/ai/delegate-opencode`; T3 remains Codex-owned, though bounded discovery, safe implementation subparts, independent review, and tests may still be delegated.

When review finds defects, resume the same OpenCode writer session with `--session` whenever possible. Escalate implementation to Codex only after repeated provider or runtime failure, unresolved material reviewer NO-GO, a safe budget exhaustion, or discovery of accounting, inventory, migration, concurrency, security, closing, reconciliation, or release-gate risk.

Codex must inspect the OpenCode process exit status, raw JSON, session/model metadata, exact changed files, Git diff, targeted tests, syntax, and scope drift. It stays read-only on business files assigned to an active writer. OpenCode completion prose alone is never acceptance evidence.

Local Qwen 27B is available only as an optional manual experiment. It is excluded from automatic routing and must not be auto-started because measured latency on this CPU is operationally impractical.

## Safety Boundaries

- T3 domain rules override apparent task size. Financial, inventory, production, migration, authorization, concurrency, idempotency, and tenant/company/branch isolation risks require critical routing.
- Git status, diff, and log inspection are allowed. Git push and production deployment never run automatically.
- Destructive database operations and business migrations require explicit authorization and T3 routing.
- Do not broadly expose `.env`, secrets, credentials, customer data, or production extracts.
- Preserve company, branch, financial-period, permission, translation, audit, formatting, transaction, and soft-delete boundaries.
- Store stable policy here; do not create routine per-task plan files in the repository.
- Keep the project-local vLLM provider discovery disabled; it must not poll Laravel or RoadRunner on port 8000.
- Preserve Laravel Boost as the `php artisan boost:mcp` MCP server and use its version-specific documentation tools when framework behavior matters.

Step limits and the no-bypass rule are defined in `EXECUTION_BUDGETS.md`.

## Enforcement Model

- OpenCode V2 ordered permissions and step limits are runtime-enforced. The last matching permission rule wins.
- OpenCode implementation and QA roles default unknown shell commands to approval, allowlist single routine inspection and verification commands, and finish with explicit anywhere-match denies for shell composition, push, destructive Git/filesystem actions, deployment, migrations, production-environment commands, and direct database clients. These command patterns are enforcement controls, not a general-purpose process sandbox; Codex must still inspect tool calls, diffs, and database-sensitive scope.
- Codex `sandbox_mode = "read-only"` is sandbox-enforced for explorer and reviewer profiles. `sandbox_workspace_write.network_access = false` is sandbox-enforced for workspace-write profiles.
- Codex role prompts that forbid delegation, business-code edits, push, deploy, or destructive commands are policy constraints unless the active Codex runtime exposes a corresponding hard permission control. Do not describe those prompt-only restrictions as technical impossibility.
- Codex QA uses `workspace-write` because the real Laravel/Pest/frontend verification workflow can create cache, build, coverage, and other runtime artifacts. It is instructed not to edit business logic, but Codex does not provide project-local path-level write denial for business-code directories in this profile.
