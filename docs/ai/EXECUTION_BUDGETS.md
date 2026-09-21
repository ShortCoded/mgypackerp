# Execution Budgets

OpenCode subagents have bounded model-step budgets. These are per-attempt ceilings, not invitations to invoke every role. Codex platform agents are disabled by default through policy, and Codex does not expose a repository-local technical switch for them.

| Role | OpenCode steps |
| --- | ---: |
| `orchestrator` | 12 |
| `explorer` | 8 |
| `worker` | 14 |
| `deep_worker` | 22 |
| `reviewer` | 8 |
| `critical_reviewer` | 12 |
| `qa` | 10 |

## No Budget Bypass

Re-spawning the same agent solely because it exhausted its step budget is forbidden. A new invocation requires at least one of:

- genuinely new evidence;
- narrowed scope;
- explicit escalation to another tier; or
- a distinct independent review purpose.

When a budget is exhausted, the agent stops and returns concise findings: completed work, evidence, files touched or inspected, verification run, unresolved risks, and the smallest useful next action.

## Loop Discipline

- Local tools perform ordinary discovery and deterministic QA without a model pass.
- One free writer owns a mutation boundary. At most two delegated attempts are allowed for that slice, and the second must be an explicitly justified free fallback.
- Failure normally returns the slice to Codex Primary. Do not automatically chain OpenCode, Hermes, Codex agents, QA, reviewer, and critical reviewer.
- Model review is scoped to a named complex semantic risk, normally T3. Deterministic QA is read directly by Codex Primary.
- Commands should be targeted and output-limited. Do not dump broad logs, schemas, or repository trees into agent context.
- Do not store routine task plans or loop state in repository files.
