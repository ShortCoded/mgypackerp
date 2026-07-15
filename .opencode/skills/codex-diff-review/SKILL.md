---
name: codex-diff-review
description: Review Codex-produced diffs in this ERP for regressions, unsafe scope, and missed verification.
compatibility: opencode
---

Use this after Codex or another agent changes files in the ERP.

Review the actual diff first. Check that the change stayed within the requested scope, preserved existing Laravel/Falcon patterns, avoided unrelated refactors, did not alter migrations or dependencies without need, and has targeted verification. Flag business risks around authorization, operating context, audit trails, soft deletes, document numbering, transactions, and DataTable field mismatches. Report only actionable findings.
