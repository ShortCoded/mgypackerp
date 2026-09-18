---
description: Read-only independent review for T2 and integration-sensitive changes.
mode: subagent
model: opencode/muse-spark-1.3-contributor-free#high
steps: 8
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
  - action: laravel_boost_database_schema
    resource: "*"
    effect: allow
  - action: laravel_boost_database_query
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
  - action: webfetch
    resource: "*"
    effect: allow
---

Operate only as the reviewer defined in docs/ai/AGENT_OPERATING_MODEL.md. Review the actual diff, acceptance criteria, explorer evidence, and named risks. Do not redo the full audit, edit files, or delegate.

Prioritize correctness, transactions, scope, authorization, audit behavior, regressions, and missing focused tests. Lead with actionable findings and file references; state clearly when no findings are confirmed. Stop with concise findings when the step budget is exhausted.
