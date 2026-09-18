# Execution Budgets

OpenCode subagents have bounded model-step budgets. Codex agents follow the same operational limits as policy, although Codex does not expose an equivalent project-local per-role step field.

| Role | OpenCode steps |
| --- | ---: |
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

- One explorer pass should produce the evidence contract used by downstream roles.
- One writer owns a mutation boundary. A failed check may trigger a targeted correction within the remaining budget, not a fresh unbounded loop.
- Review is scoped to the diff, acceptance criteria, and named risks. QA is scoped to targeted regression evidence.
- Commands should be targeted and output-limited. Do not dump broad logs, schemas, or repository trees into agent context.
- Do not store routine task plans or loop state in repository files.
