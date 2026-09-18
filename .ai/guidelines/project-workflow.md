# Extend the Existing ERP

- Treat MgyPack as an existing system. Before changing behavior, inspect the current implementation, its shared component or service, the closest working reference screen, and the relevant tests.
- Load the relevant project skill from `**/skills/**` whenever its description matches the work; mandatory project rules remain in these generated guidelines rather than only in optional skills.
- Preserve the established company, branch, financial-period, permission, translation, formatting, and soft-delete boundaries. Do not remove a scope or invent parallel domain behavior to make an isolated fix pass.
- Review Git status before editing and preserve unrelated uncommitted work. Keep changes focused, use the existing UI and architectural conventions, and verify every change with targeted tests.
- Use Laravel Boost documentation and inspection tools when they cover the question. Prefer database aggregates, scoped queries, and paginated lists over loading records into memory.

# Codex-led OpenCode Delegation

- Codex owns diagnosis, task boundaries, review, integration, and final verification. OpenCode is a single scoped implementation worker, not a second lead, and must not delegate the assigned work again.
- Before relying on OpenCode, Codex must verify the installed CLI version, configuration, authorized provider, permissions, and a small safe invocation. A local attached server must remain bound to loopback; do not disable approvals or change billing/provider configuration.
- Every implementation delegation must state the concrete problem, expected behavior, allowed files or areas, existing patterns and invariants, acceptance criteria, and requested report of edits, tests actually run, and unverified items. Never include secrets or customer data.
- Start with one writer. Do not overlap writers on the same files. After each delegation, Codex must inspect the diff for scope drift and run the relevant tests on the integrated working tree. Tool completion or an agent claim is not proof of correctness.
- If delegation is unavailable or fails, report the exact limitation and continue safe in-scope work directly; do not claim OpenCode wrote changes it did not produce.

# Local Agent Infrastructure v1

- Before delegating, the orchestrator must classify the task and follow `docs/ai/AGENT_OPERATING_MODEL.md`, `docs/ai/MODEL_ROUTING.md`, and `docs/ai/EXECUTION_BUDGETS.md`. Model routing is the orchestrator's deterministic responsibility; do not create a model-router agent.
- Use only the approved explorer, worker, deep_worker, reviewer, critical_reviewer, and qa roles. Keep one writer unless independent file boundaries are proven, and never let a subagent delegate again.
- Read the minimum relevant context. Start with `docs/erp/INDEX.md`, use `CODEX_PROJECT_CONTEXT.md` only for the historical sections it identifies, then pass concise evidence and file references forward so downstream agents do not repeat discovery.
- Critical financial, inventory, migration, security, concurrency, idempotency, and tenant-scope work is T3 regardless of apparent size and requires an independent critical review plus targeted regression or reconciliation evidence.
- Do not bypass an exhausted step budget by respawning the same role. A new invocation needs new evidence, narrower scope, an explicit tier escalation, or a distinct independent review purpose.
- Git pushes, production deployments, destructive database operations, and broad secret or environment-file access must never happen automatically.
