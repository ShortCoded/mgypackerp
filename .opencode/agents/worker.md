---
description: Scoped implementation worker for confirmed T1 changes.
mode: subagent
model: opencode/muse-spark-1.3-contributor-free#medium
steps: 14
permissions:
  - action: "*"
    resource: "*"
    effect: deny
  - action: read
    resource: "*"
    effect: allow
  - action: read
    resource: "*.env*"
    effect: deny
  - action: glob
    resource: "*"
    effect: allow
  - action: grep
    resource: "*"
    effect: allow
  - action: edit
    resource: "*"
    effect: allow
  - action: edit
    resource: "*.env*"
    effect: deny
  - action: skill
    resource: "*"
    effect: allow
  - action: laravel_boost_search_docs
    resource: "*"
    effect: allow
  - action: laravel_boost_application_info
    resource: "*"
    effect: allow
  - action: laravel_boost_database_schema
    resource: "*"
    effect: allow
  - action: laravel_boost_database_query
    resource: "*"
    effect: allow
  - action: laravel_boost_browser_logs
    resource: "*"
    effect: allow
  - action: laravel_boost_get_absolute_url
    resource: "*"
    effect: allow
  - action: shell
    resource: "git status *"
    effect: allow
  - action: shell
    resource: "git diff *"
    effect: allow
  - action: shell
    resource: "git log *"
    effect: allow
  - action: shell
    resource: "php artisan test *"
    effect: allow
  - action: shell
    resource: "php artisan route:list *"
    effect: allow
  - action: shell
    resource: "vendor/bin/pest *"
    effect: allow
  - action: shell
    resource: "vendor/bin/pint *"
    effect: allow
  - action: shell
    resource: "php -l *"
    effect: allow
  - action: shell
    resource: "composer test *"
    effect: allow
  - action: shell
    resource: "pnpm test *"
    effect: allow
  - action: shell
    resource: "pnpm run test *"
    effect: allow
  - action: shell
    resource: "pnpm run build *"
    effect: allow
  - action: shell
    resource: "pnpm run lint *"
    effect: allow
  - action: shell
    resource: "git push *"
    effect: deny
  - action: shell
    resource: "*deploy*"
    effect: deny
---

Operate only as the worker defined in docs/ai/AGENT_OPERATING_MODEL.md. Implement the supplied bounded change within the allowed files and acceptance criteria. Preserve every ERP invariant named in AGENTS.md and the handoff.

Do not expand scope, delegate, push, deploy, run destructive database commands, or expose secrets. Return changed files, exact behavior, tests actually run, failures, and unverified items. If the step budget is exhausted, stop with a concise status instead of continuing or requesting a respawn.
