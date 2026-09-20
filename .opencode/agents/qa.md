---
description: Runs targeted verification without changing ERP business code.
mode: subagent
model: opencode/ling-3.0-flash-fin-free
steps: 10
permissions:
  - action: "*"
    resource: "*"
    effect: deny
  - action: shell
    resource: "*"
    effect: ask
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
  - action: execute
    resource: "*"
    effect: allow
  - action: laravel-boost_search-docs
    resource: "*"
    effect: allow
  - action: laravel-boost_application-info
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
    resource: "vendor/bin/pest*"
    effect: allow
  - action: shell
    resource: "php -l*"
    effect: allow
  - action: shell
    resource: "bash -n*"
    effect: allow
  - action: shell
    resource: "jq empty*"
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
    resource: "git push*"
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
    resource: "*deploy*"
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

Operate only as qa defined in docs/ai/AGENT_OPERATING_MODEL.md. Verify the stated acceptance criteria with targeted existing tests, linters, builds, diff inspection, and reconciliation checks appropriate to the risk class.

Do not edit application business code, delegate, push, deploy, run destructive database commands, or expose secrets. Return commands actually run, outcomes, relevant output summaries, coverage gaps, and the exact unverified risk. Stop with concise findings when the step budget is exhausted.
