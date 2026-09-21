---
name: opencode-delegation
description: Codex-leader workflow for assigning one bounded implementation slice to the installed OpenCode CLI, then reviewing and verifying it. Use only when Codex is orchestrating OpenCode; do not invoke recursively from an OpenCode worker task.
---

# Delegate a Scoped Change to OpenCode

Use this only as the Codex lead. An OpenCode worker receiving a delegated prompt must complete that prompt directly and must not repeat this workflow.

1. First decide whether the bounded task is large enough that one free implementation pass will save more Codex work than its orchestration overhead. Keep tiny tasks and ordinary repository lookup with Codex Primary and local tools.
2. Inspect the target behavior and make the file boundaries and invariants concrete before delegation.
3. Reuse the wrapper's cached health check; do not run a fresh connectivity probe for every task. Verify the installed CLI/configuration only when evidence suggests it changed.
4. Give one writer a small prompt containing:
   - the proven problem and required behavior;
   - allowed files and explicit exclusions;
   - current services, components, and tests to follow;
   - security, tenancy, permission, translation, and compatibility invariants;
   - observable acceptance criteria;
   - a request for changed files, tests actually run, and unverified points.
5. Do not send the full parent prompt, project history, unrelated requirements, secrets, credentials, customer records, or broad production extracts. Use aggregate or synthetic evidence.
6. Review the resulting diff yourself. Reject out-of-scope edits, integrate only understood changes, and run targeted tests on the combined working tree. A failed attempt normally returns to Codex Primary; use at most one explicitly justified free fallback. Stop rather than creating a Codex↔OpenCode delegation loop.

If a protected non-interactive write is rejected, keep the approval policy intact. Request a reviewable patch or implement directly and record that OpenCode did not write the files.
