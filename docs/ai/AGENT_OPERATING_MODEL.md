# Agent Operating Model

This repository uses Codex Primary as the coordinator and applies a credit-first execution policy. Local deterministic tools are the first station, OpenCode is the preferred optional free executor for coherent mechanical work, and Hermes is selective rather than an automatic cascade. `AGENTS.md` remains the mandatory project instruction layer, and `docs/erp/INDEX.md` is the current ERP navigation index. `CODEX_PROJECT_CONTEXT.md` is historical architectural context, not a source for current path or runtime versions. Load only the sections and files relevant to the assigned task.

## Roles

| Role | Responsibility | Mutation authority |
| --- | --- | --- |
| `orchestrator` | Classify risk, map the bounded path once, choose whether delegation is economic, integrate, and own final verification. | Normal direct work plus coordination of optional free executors. |
| `explorer` | Trace the smallest relevant execution path and return evidence, likely risk, affected files, and focused verification targets. | Read-only. |
| `worker` | Implement a bounded T1 change with a known or confirmed cause. | Scoped project edits; no delegation. |
| `deep_worker` | Implement a bounded T2/T3 change that needs deeper reasoning across layers. | Scoped project edits; no delegation. |
| `reviewer` | Independently review a T2 or integration-sensitive change without repeating the full discovery audit. | Read-only. |
| `critical_reviewer` | Independently challenge T3 correctness, transactions, reconciliation, authorization, isolation, concurrency, and recovery behavior. | Read-only. |
| `qa` | Run targeted tests and inspections against stated acceptance criteria; report evidence and gaps. | No business-code edits by default. |

There is no model-router agent. Model selection is a deterministic orchestrator responsibility governed by `MODEL_ROUTING.md`.

## Operating Contract

1. Record `risk_class`, `execution_station`, `selected_provider`, and `review_required` before delegation. Record `delegation_savings_reason` and `reason_for_escalation` only when applicable.
2. Inspect existing infrastructure or behavior before changing it. For ERP work, use `docs/erp/INDEX.md` to select the minimum relevant context, then inspect the actual implementation, the closest working reference, and focused tests.
3. Prefer Codex Primary for tiny work and local tools for lookup/verification. Use one delegated writer for one coherent mechanical concern. At most two free writers may run concurrently, and only for independent tracks with non-overlapping mutation boundaries.
4. Pass a concise mapped handoff: exact files, exact modifications, exact reference, invariants, and one focused verification command. Never forward the full parent prompt or unrelated history.
5. Do not repeat discovery already supported by evidence. Reviewers inspect the diff and named risk areas; they do not redo the explorer's entire scan.
6. The orchestrator inspects the integrated diff and verification output. Agent completion is not proof of correctness.

## Codex Delegation Contract

Before implementation, Codex must classify the task, define the mutation boundary, and ask whether delegation will likely save more Codex work than its overhead. Local search and deterministic verification do not use a model. Tiny tasks stay with Codex Primary. Small/medium mechanical tasks may use one health-gated OpenCode Personal attempt. T3 remains Codex-owned. Codex platform subagents are policy-disabled by default because the repository cannot technically remove intrinsic platform capabilities; `.codex/config.toml` additionally caps exceptional Codex subagent concurrency at one.

An unavailable, stalled, or failed delegated route normally returns work to Codex Primary. A single explicit free fallback may be selected only from fresh evidence that it is likely to succeed and save meaningful work. There is no automatic OpenCode-to-Hermes or Hermes-Cheap-to-Strong escalation. One slice has at most two delegated attempts. The router records lightweight provider/model/category outcomes and opens a temporary circuit after repeated failures or timeouts.

Codex must inspect the worker process exit status, structured result, exact changed files, Git diff, targeted tests, syntax, and scope drift. Normal QA is performed directly with deterministic tools. Model QA/review requires a concrete complex semantic risk; it is not a default station. Stop after acceptance passes.

Local Qwen 27B is available only as an optional manual experiment. It is excluded from automatic routing and must not be auto-started because measured latency on this CPU is operationally impractical.

## Safety Boundaries

- T3 domain rules override apparent task size. Financial, inventory, production, migration, authorization, concurrency, idempotency, and tenant/company/branch isolation risks require T3 routing and Codex Primary ownership.
- Git status, diff, and log inspection are allowed. Git push and production deployment never run automatically.
- Destructive database operations and business migrations require explicit authorization and T3 routing.
- Do not broadly expose `.env`, secrets, credentials, customer data, or production extracts.
- Preserve company, branch, financial-period, permission, translation, audit, formatting, transaction, and soft-delete boundaries.
- Store stable policy here; do not create routine per-task plan files in the repository.
- Keep the project-local vLLM provider discovery disabled; it must not poll Laravel or RoadRunner on port 8000.
- Preserve Laravel Boost as the `php artisan boost:mcp` MCP server and use its version-specific documentation tools when framework behavior matters.

Step limits and the no-bypass rule are defined in `EXECUTION_BUDGETS.md`.

## Enforcement Model

- OpenCode V2 ordered permissions and step limits are runtime-enforced when OpenCode is enabled. The last matching permission rule wins.
- OpenCode implementation and QA roles default unknown shell commands to approval, allowlist single routine inspection and verification commands, and finish with explicit anywhere-match denies for shell composition, push, destructive Git/filesystem actions, deployment, migrations, production-environment commands, and direct database clients. These command patterns are enforcement controls, not a general-purpose process sandbox; Codex must still inspect tool calls, diffs, and database-sensitive scope.
- Hermes one-shot constraints are policy controls, not a filesystem sandbox. Read-only work may run in the main checkout; edit work should use a linked worktree. `delegate-hermes` rejects edit mode on a primary working tree unless Codex explicitly marks a harmless temporary target with `--allow-shared-tree`.
- Codex `sandbox_mode = "read-only"` is sandbox-enforced for explorer and reviewer profiles. `sandbox_workspace_write.network_access = false` is sandbox-enforced for workspace-write profiles.
- Codex role prompts that forbid delegation, business-code edits, push, deploy, or destructive commands are policy constraints unless the active Codex runtime exposes a corresponding hard permission control. Do not describe those prompt-only restrictions as technical impossibility.
- Codex QA uses `workspace-write` because the real Laravel/Pest/frontend verification workflow can create cache, build, coverage, and other runtime artifacts. It is instructed not to edit business logic, but Codex does not provide project-local path-level write denial for business-code directories in this profile.
