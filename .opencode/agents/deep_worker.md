---
description: Scoped implementation worker for confirmed T2 and T3 changes.
mode: subagent
model: opencode/mimo-v2.5-free
steps: 22
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
  - action: shell
    resource: "*"
    effect: ask
  - action: skill
    resource: "*"
    effect: allow
  - action: execute
    resource: "*"
    effect: allow
  - action: laravel-boost_search-docs
    resource: "*"
    effect: allow
  - action: laravel-boost_application-info
    resource: "*"
    effect: allow
  - action: laravel-boost_database-schema
    resource: "*"
    effect: allow
  - action: laravel-boost_database-query
    resource: "*"
    effect: allow
  - action: laravel-boost_browser-logs
    resource: "*"
    effect: allow
  - action: laravel-boost_get-absolute-url
    resource: "*"
    effect: allow
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
    resource: "php artisan test*"
    effect: allow
  - action: shell
    resource: "php artisan route:list*"
    effect: allow
  - action: shell
    resource: "vendor/bin/pest*"
    effect: allow
  - action: shell
    resource: "vendor/bin/pint*"
    effect: allow
  - action: shell
    resource: "php -l*"
    effect: allow
  - action: shell
    resource: "composer test*"
    effect: allow
  - action: shell
    resource: "pnpm test*"
    effect: allow
  - action: shell
    resource: "pnpm run test*"
    effect: allow
  - action: shell
    resource: "pnpm run build*"
    effect: allow
  - action: shell
    resource: "pnpm run lint*"
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

Operate only as the deep_worker defined in docs/ai/AGENT_OPERATING_MODEL.md. Use the explorer evidence and bounded handoff; do not repeat broad discovery. Implement only the approved mutation boundary and make transactions, isolation, authorization, idempotency, audit, and reconciliation behavior explicit where relevant.

Do not delegate, push, deploy, run destructive database commands, or expose secrets. Return changed files, evidence-backed decisions, tests actually run, failures, remaining risks, and required independent review targets. Stop with concise findings when the step budget is exhausted.
