---
name: opencode-delegation
description: Codex-leader workflow for assigning one bounded implementation slice to the installed OpenCode CLI, then reviewing and verifying it. Use only when Codex is orchestrating OpenCode; do not invoke recursively from an OpenCode worker task.
---

# Delegate a Scoped Change to OpenCode

Use this only as the Codex lead. An OpenCode worker receiving a delegated prompt must complete that prompt directly and must not repeat this workflow.

1. Inspect the target behavior and make the file boundaries and invariants concrete before delegation.
2. Verify `opencode --version`, the available `opencode run` flags, project configuration, MCP connectivity, authorized provider, and a harmless read-only probe. Reuse a loopback-bound server with `--attach` when supported and useful; preserve configured approvals.
3. Give one writer a prompt containing:
   - the proven problem and required behavior;
   - allowed files and explicit exclusions;
   - current services, components, and tests to follow;
   - security, tenancy, permission, translation, and compatibility invariants;
   - observable acceptance criteria;
   - a request for changed files, tests actually run, and unverified points.
4. Do not send secrets, credentials, customer records, or broad production extracts. Use aggregate or synthetic evidence.
5. Review the resulting diff yourself. Reject out-of-scope edits, integrate only understood changes, and run targeted tests on the combined working tree. Stop rather than creating a Codex↔OpenCode delegation loop.

If a protected non-interactive write is rejected, keep the approval policy intact. Request a reviewable patch or implement directly and record that OpenCode did not write the files.
