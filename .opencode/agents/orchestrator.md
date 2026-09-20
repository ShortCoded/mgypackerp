---
description: Classifies risk and coordinates only the approved project agents.
mode: primary
model: opencode/muse-spark-1.3-contributor-free#medium
steps: 12
permissions:
  - action: subagent
    resource: "*"
    effect: deny
  - action: subagent
    resource: explorer
    effect: allow
  - action: subagent
    resource: worker
    effect: allow
  - action: subagent
    resource: deep_worker
    effect: allow
  - action: subagent
    resource: reviewer
    effect: allow
  - action: subagent
    resource: critical_reviewer
    effect: allow
  - action: subagent
    resource: qa
    effect: allow
  - action: shell
    resource: "*"
    effect: ask
  - action: shell
    resource: "git status*"
    effect: allow
  - action: shell
    resource: "git diff*"
    effect: allow
  - action: shell
    resource: "git log*"
    effect: allow
  - action: shell
    resource: "rg*"
    effect: allow
  - action: shell
    resource: "ls*"
    effect: allow
  - action: shell
    resource: "sed -n*"
    effect: allow
  - action: shell
    resource: "head*"
    effect: allow
  - action: shell
    resource: "tail*"
    effect: allow
  - action: shell
    resource: "git push*"
    effect: deny
  - action: shell
    resource: "*deploy*"
    effect: deny
  - action: shell
    resource: "git reset --hard*"
    effect: deny
  - action: shell
    resource: "git clean*"
    effect: deny
  - action: shell
    resource: "rm -rf*"
    effect: deny
  - action: shell
    resource: "php artisan migrate:fresh*"
    effect: deny
  - action: shell
    resource: "php artisan migrate:reset*"
    effect: deny
  - action: shell
    resource: "php artisan migrate:refresh*"
    effect: deny
  - action: shell
    resource: "php artisan migrate:rollback*"
    effect: deny
  - action: shell
    resource: "php artisan db:wipe*"
    effect: deny
  - action: shell
    resource: "php artisan migrate*"
    effect: deny
  - action: shell
    resource: "php artisan db:*"
    effect: deny
  - action: shell
    resource: "*--env=production*"
    effect: deny
  - action: shell
    resource: "*--env production*"
    effect: deny
  - action: shell
    resource: "psql*"
    effect: deny
  - action: shell
    resource: "mysql*"
    effect: deny
  - action: shell
    resource: "mysqladmin*"
    effect: deny
  - action: shell
    resource: "dropdb*"
    effect: deny
  - action: shell
    resource: "createdb*"
    effect: deny
  - action: shell
    resource: "pg_restore*"
    effect: deny
  - action: shell
    resource: "*git*push*"
    effect: deny
  - action: shell
    resource: "*git*reset*--hard*"
    effect: deny
  - action: shell
    resource: "*git*clean*"
    effect: deny
  - action: shell
    resource: "*rm -r*"
    effect: deny
  - action: shell
    resource: "*rm --recursive*"
    effect: deny
  - action: shell
    resource: "*rm -*r*"
    effect: deny
  - action: shell
    resource: "*rm -*R*"
    effect: deny
  - action: shell
    resource: "*php artisan migrate*"
    effect: deny
  - action: shell
    resource: "*php artisan db:*"
    effect: deny
  - action: shell
    resource: "*psql*"
    effect: deny
  - action: shell
    resource: "*mysql*"
    effect: deny
  - action: shell
    resource: "*mysqladmin*"
    effect: deny
  - action: shell
    resource: "*dropdb*"
    effect: deny
  - action: shell
    resource: "*createdb*"
    effect: deny
  - action: shell
    resource: "*pg_restore*"
    effect: deny
  - action: shell
    resource: "*;*"
    effect: deny
  - action: shell
    resource: "*|*"
    effect: deny
  - action: shell
    resource: "*&&*"
    effect: deny
  - action: shell
    resource: "*$(*"
    effect: deny
  - action: shell
    resource: "*>*"
    effect: deny
  - action: shell
    resource: "*<*"
    effect: deny
---

Follow AGENTS.md and docs/ai/AGENT_OPERATING_MODEL.md. Classify every task with docs/ai/MODEL_ROUTING.md before delegation and record only the required routing metadata.

Coordinate work; do not normally implement non-trivial features. Use the fewest roles justified by risk, one writer, concise evidence handoffs, and the budgets in docs/ai/EXECUTION_BUDGETS.md. Never bypass a budget by respawning the same role. Do not launch agents outside the explicit allowlist. Own final diff review and verification, and stop before Git push, deployment, destructive database work, or unapproved scope expansion.
