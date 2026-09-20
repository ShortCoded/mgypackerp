# Permissions & Scoping — MgyPack ERP

> Reference for the permission system, scope-access model, and factory hierarchy.

---

## § 17 — Roles, Users & Permission Tree

### 17.1 Permission Sources

Permissions are **not** hardcoded. They are assembled at runtime from:

1. **Config-defined permissions** — loaded from permission config files
2. **Menu-defined permissions** — derived from menu visibility rules
3. **ErpUi dynamic permissions** — UI-level permission checks

All three merge into a single `PermissionRegistryService` cache (24h TTL).

### 17.2 Permission Aliases

61 permission aliases are registered. Aliases map human-readable names to
internal permission strings. Example aliases:

| Alias group | Examples |
|-------------|----------|
| Auth (core) | `users.view`, `users.create`, `users.edit`, `users.delete` |
| Finance | `currencies.view`, `bank-accounts.create`, `vouchers.post`, `cheques.approve` |
| Accounting | `journals.view`, `coa.edit`, `cost-centers.create`, `closing.post` |
| Sales | `orders.create`, `invoices.post`, `customers.view` |
| Purchases | `orders.create`, `receipts.post`, `suppliers.view` |
| Production | `work-orders.create`, `machines.view`, `bom.edit` |
| Inventory | `stock.view`, `transfers.create`, `stores.view` |
| HR | `employees.view`, `payroll.process`, `attendance.view` |
| Maintenance | `plans.create`, `breakdowns.log`, `orders.track` |
| Quality | `inspections.create`, `reports.view` |
| Fixed Assets | `assets.create`, `movements.create`, `depreciation.run` |
| Tools | `calendar.view`, `boards.view`, `chat.view`, `pwa.configure` |

### 17.3 Role Assignment

- Roles are assigned per user via a pivot table
- A user may hold multiple roles
- Permissions aggregate across all assigned roles (union)
- Admin role bypasses all scope restrictions

---

## § 18 — Scope Access & Sensitivity

### 18.1 OperatingContextService

Session carries three scope tokens:

| Token | Source | Purpose |
|-------|--------|---------|
| `company_id` | User's company assignment or auto-select | Isolates all queries |
| `branch_id` | User's branch assignment or auto-select | Sub-company filtering |
| `financial_period_id` | Active period selection | Temporal scoping |

On first login or session start, **auto-select** picks the user's single
company/branch/period if only one exists.

### 18.2 OperatingScopeAccessService — Scope Access Pivot

Three boolean flags per user-role assignment control which scope dimensions
the user may access:

| Flag | Meaning when TRUE | Meaning when FALSE |
|------|-------------------|--------------------|
| `company_access` | User can switch between companies | Locked to assigned company |
| `branch_access` | User can switch between branches | Locked to assigned branch |
| `financial_period_access` | User can switch between periods | Locked to active period |

**Admin bypass:** When the user holds the admin role, all three flags are
ignored — the user has unrestricted access across all dimensions.

### 18.3 Scope Access Pivot Tables

| Pivot table | Controls |
|-------------|----------|
| `role_company_access` | Which companies a role may access |
| `role_branch_access` | Which branches a role may access |
| `role_financial_period_access` | Which periods a role may access |

When a flag is `restricted`, the pivot table is authoritative. When
unrestricted, the user sees everything in that dimension.

---

### 18.4 Operation Permission Meanings

| Operation | Meaning |
|-----------|---------|
| **View** | Read access to the entity list and detail; no modification |
| **Create** | Can open the creation form and submit new records |
| **Edit** | Can modify existing records (subject to status/state rules) |
| **Delete** | Can soft-delete records; may require restore permission |
| **Restore** | Can recover soft-deleted records back to active state |
| **Approve** | Can change status to approved/pending-approval; financial gate |
| **Post** | Can post approved records to the general ledger (irreversible) |
| **Reverse** | Can reverse posted records (creates reversal journal entries) |
| **Print** | Can generate print/PDF output for the entity |
| **Export** | Can download data in CSV/Excel format |

---

### 18.5 Full Scope Matrix

| Scope Dimension | Applies to | Enforcement | Notes |
|-----------------|------------|-------------|-------|
| **Global** | System-wide settings, languages, mail config | All queries unscoped | Only super-admin should access |
| **Company** | All business entities | `WHERE company_id = ?` on every query | Baseline scope for all users |
| **Branch** | Orders, vouchers, inventory, employees | `WHERE branch_id = ?` | Subset of company |
| **Period** | Journals, closing, trial balance, ledger | `WHERE financial_period_id = ?` | Temporal isolation |
| **Branch + Period** | Vouchers, journal entries, reports | Both filters combined | Most restrictive operational scope |
| **Factory** (branch) | Production machines, cost centers | Branch-level grouping | Machines belong to a branch factory |
| **Store** (branch) | Inventory stock, transfers | Branch-level sub-location | Stores are children of branches |
| **Hall** (branch) | Branch halls | Branch-level sub-location | Halls are children of branches |
| **Machine** (factory) | Work orders, production costs | Machine-level tracking | Machines belong to a factory/branch |
| **Employee** (branch) | Attendance, requests, payroll | Branch-level HR | Employees assigned to one branch |
| **Customer/Supplier** | Sales, purchases, receivables/payables | Company-level | Customers/suppliers span the company |

---

### 18.6 Enforcement Notes

1. **Query-level scoping:** Every list/detail query applies company, branch,
   and period filters based on the user's session context. The scope is
   injected by `OperatingContextService` into the query builder.

2. **Controller-level enforcement:** Controllers call scope access checks
   before returning data. Unauthorized scope switches return 403.

3. **Admin bypass:** The admin role bypasses all scope access checks — the
   user can see and modify any company, branch, or period.

4. **Spatie Teams:** Teams are **disabled**. The wildcard permission is
   **disabled**. The permission cache TTL is **24 hours**.

5. **Sentry/security:** Sensitive permissions (delete, reverse, approve, post)
   are flagged in the permission registry and may require additional
   confirmation or audit logging.

---

### 18.7 Factory Hierarchy

The physical-resource hierarchy flows top-down:

```
Company
  └── Branch
        ├── Halls          (branch_halls)
        ├── Stores         (branch_stores)
        ├── Machines       (production_machines)
        ├── Cost Centers
        ├── Production screens
        ├── Quality screens
        └── Maintenance screens
```

**Key relationships:**

| Parent | Child | Link | Notes |
|--------|-------|------|-------|
| Branch | Hall | `branch_halls.branch_id` | Physical spaces within a branch |
| Branch | Store | `branch_stores.branch_id` | Inventory storage locations |
| Branch | Machine | `production_machines.branch_id` | Production equipment |
| Branch | Cost Center | `cost_centers.branch_id` | Financial cost allocation |
| Machine | Work Order | FK | Each work order targets one machine |
| Hall | Employee | FK | Employees assigned to a hall |

> **Note:** The legacy `storage-location` entity has been **removed** from the
> system. Stores now serve this purpose directly.

---

### 18.8 Menu Groups

| Menu group | Sub-screens | Scope level |
|------------|-------------|-------------|
| core/auth | Users, roles, permissions, settings | Global / Company |
| finance | Currencies, bank accounts, cashboxes, vouchers, cheques, transfers, opening balances | Company + Period |
| accounting | Journals, COA, cost centers, closing, general journal, ledger, trial balance, reconciliation, statements, expense analysis, ratios | Company + Period |
| sales | Customers, orders, invoices, returns, price lists | Company + Branch |
| purchases | Suppliers, orders, receipts, returns | Company + Branch |
| production | Work orders, BOM, machines, cost tracking | Branch + Machine |
| inventory | Stock queries, transfers, stores, movements | Branch + Store |
| hr | Employees, +18 screens, geography (hidden), shifts, biometric, attendance, requests, payroll, self-service | Branch + Employee |
| maintenance | Plans, breakdowns, orders, materials, expenses, reports | Branch + Machine |
| quality | Inspections, reports | Branch |
| fixed_assets | Register, movements, depreciation, reports | Company |
| tools | Open documents, file manager, calendar, boards, chat, PWA config | Global |
| reports | Cross-module report generation | Company + Period |

---

## Footer Counts

| Category | Count |
|----------|-------|
| Permission aliases | 61 |
| Operation types | 10 (View, Create, Edit, Delete, Restore, Approve, Post, Reverse, Print, Export) |
| Scope dimensions | 11 (Global, Company, Branch, Period, Branch+Period, Factory, Store, Hall, Machine, Employee, Customer/Supplier) |
| Menu groups | 13 |
| Pivot tables (scope access) | 3 |
| HR sub-screens | 18+ |
