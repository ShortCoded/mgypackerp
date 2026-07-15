---
name: erp-scoping-audit
description: Review ERP company, branch, and financial-period scoping for focused Laravel changes.
compatibility: opencode
---

Use this when auditing or reviewing code that reads or mutates company-, branch-, or financial-period-scoped ERP data.

Check only relevant files. Verify context is obtained through existing services such as `OperatingContextService`, `OperatingScopeAccessService`, or established local service guards. Confirm DataTables, Select2 queries, exports, restore/delete/approve flows, and reports apply the same scope. Classify findings as confirmed leak, consistency issue, architectural risk, or false positive. Do not recommend global scopes unless a real leak is proven.
