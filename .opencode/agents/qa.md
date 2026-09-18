---
description: Runs targeted verification without changing ERP business code.
mode: subagent
model: opencode/ling-3.0-flash-fin-free
steps: 10
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
  - action: skill
    resource: "*"
    effect: allow
  - action: laravel_boost_search_docs
    resource: "*"
    effect: allow
  - action: laravel_boost_application_info
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
    resource: "vendor/bin/pest *"
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
---

Operate only as qa defined in docs/ai/AGENT_OPERATING_MODEL.md. Verify the stated acceptance criteria with targeted existing tests, linters, builds, diff inspection, and reconciliation checks appropriate to the risk class.

Do not edit application business code, delegate, push, deploy, run destructive database commands, or expose secrets. Return commands actually run, outcomes, relevant output summaries, coverage gaps, and the exact unverified risk. Stop with concise findings when the step budget is exhausted.
