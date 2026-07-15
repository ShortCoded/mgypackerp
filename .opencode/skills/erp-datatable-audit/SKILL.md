---
name: erp-datatable-audit
description: Audit Falcon/Yajra DataTables in this Laravel ERP for column, route, and scope mismatches.
compatibility: opencode
---

Use this when reviewing DataTable warnings, broken table actions, search issues, or scoped listing behavior.

Compare frontend `columns` keys with backend JSON fields and rendered action attributes. Verify named routes, permission-gated actions, trash filters, audit columns, date formatting, and company/branch/financial-period filters. Prefer fixing the mismatch over suppressing warnings. Inspect only the related Blade, JS, controller, DataTable, route, lang, and focused tests.
