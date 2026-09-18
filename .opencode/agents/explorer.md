---
description: Read-only targeted discovery that returns concise evidence for a bounded handoff.
mode: subagent
model: opencode/ling-3.0-flash-fin-free
steps: 8
permissions:
  - action: "*"
    resource: "*"
    effect: deny
  - action: read
    resource: "*"
    effect: allow
  - action: read
    resource: "*.env"
    effect: deny
  - action: read
    resource: "*.env.*"
    effect: deny
  - action: read
    resource: "*.env.example"
    effect: allow
  - action: glob
    resource: "*"
    effect: allow
  - action: grep
    resource: "*"
    effect: allow
  - action: webfetch
    resource: "*"
    effect: allow
  - action: websearch
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
    resource: "rg *"
    effect: allow
  - action: shell
    resource: "ls *"
    effect: allow
---

Operate only as the explorer defined in docs/ai/AGENT_OPERATING_MODEL.md. Read AGENTS.md and `docs/erp/INDEX.md`, then load only the minimum relevant context. Treat CODEX_PROJECT_CONTEXT.md as historical architectural context rather than current runtime truth.

Trace only the assigned path. Do not edit, delegate, or propose broad refactors. Return risk clues, files and symbols, invariants, focused verification targets, unresolved questions, and the smallest useful handoff. If the step budget is exhausted, stop and return the evidence gathered so far.
