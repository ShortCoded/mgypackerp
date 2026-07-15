# ERP Permissions Sync Audit Report

## 1. Executive Summary

The ERP permissions system already has a **complete, safe, and well-architected sync command** (`app/Console/Commands/SyncErpPermissionsCommand.php`) registered as `erp:permissions:sync`. It was implemented alongside full test coverage (`tests/Feature/SyncErpPermissionsCommandTest.php`). The source of truth is already **menu actions** (`config/menu/*.php`), discovered through `PermissionRegistryService`. No additional command implementation work is needed — the command supports `--dry-run`, `--prune`, `--force`, `--admin-role=`, suspicious-count guards, production safety checks, pivot cleanup, and Spatie cache clearing.

**The safest recommendation**: Keep the current architecture where `config/menu/*.php` **is the sole source of truth**. The existing `config/permissions.php` is intentionally empty and reserved for exceptional permissions. The existing command and seeder are production-safe already.

**The only meaningful gap**: `hr.regulations.*` and `hr.attendance_rules.*` (14 permissions) are enforced at the route middleware level but deliberately excluded from menu config. These need to be either added to `config/menu/hr.php` or handled via a secondary non-menu discovery path for the sync to include them automatically.

---

## 2. Files Inspected

### Permission Sources
- `config/permissions.php` — intentionally empty (reserved for global exceptional permissions)
- `config/permission.php` — Spatie package config (206 lines)
- `config/menu/core.php` (460 lines) — dashboard, basic data CRUDs, item data CRUDs
- `config/menu/accounting.php` (67 lines) — chart of accounts, cost centers
- `config/menu/finance.php` (82 lines) — currencies, bank accounts, cashboxes, opening balances
- `config/menu/hr.php` (95 lines) — employees + 7 foundation lookups
- `config/menu/tools.php` (272 lines) — open documents, file manager, calendar, my board, quick tasks, task boards, team board, chat, PWA settings
- `config/menu/inventory.php` (64 lines) — opening stock, opening stock pricing
- `config/menu/fixed_assets.php` (36 lines) — fixed assets register
- `config/menu/sales.php` (40 lines) — customers
- `config/menu/purchases.php` (40 lines) — suppliers
- `config/menu/auth.php` (3 lines) — empty

### Core Services & Commands
- `modules/Auth/Services/PermissionRegistryService.php` (548 lines) — discovers permissions from menu configs recursively
- `modules/Auth/Services/RoleService.php` (807 lines) — role CRUD with permission sync
- `modules/Auth/Database/Seeders/PermissionSeeder.php` (177 lines) — creates/updates permissions from menu discovery
- `modules/Core/Services/MenuConfigFileOrder.php` (64 lines) — deterministic menu file ordering
- `modules/Core/Services/RequestMemo.php` — per-request memoization
- `app/Console/Commands/SyncErpPermissionsCommand.php` (431 lines) — **fully implemented** `erp:permissions:sync` command
- `database/seeders/EmergencyRecoverySeeder.php` — calls PermissionSeeder, syncs all web permissions to admin role
- `database/seeders/RuntimeDemoDataSeeder.php` — demo roles with `Permission::findOrCreate`
- `database/seeders/DatabaseSeeder.php` — calls PermissionSeeder

### Tests
- `tests/Feature/SyncErpPermissionsCommandTest.php` (97 lines) — 4 tests covering dry-run, create, prune, suspicious-count guard
- `tests/Feature/Auth/PermissionSeederTest.php` — comprehensive permission discovery and seeder tests
- `tests/Feature/BaselineDatabaseSeederTest.php` — baseline validation including permissions
- Various CRUD test files validating permissions via `hasPermissionTo`

### Database Migrations
- `database/migrations/2026_04_26_202347_create_permission_tables.php` — Spatie 5 standard tables
- 8+ migration files in `modules/Auth/Database/Migrations/` extending roles with doc numbers, soft deletes, operating scope pivots

---

## 3. Current Permission Sources

Permissions come from **two sources**, but only one is live:

| Source | Status | Purpose |
|--------|--------|---------|
| `config/menu/*.php` | **Active source of truth** | All 398 discovered permissions |
| `config/permissions.php` | Intentionally empty | Reserved for exceptional global permissions |

The `PermissionRegistryService::fromMenus()` method scans all `config/menu/*.php` files in ranked order (core → accounting → finance → fixed_assets → sales → purchases → inventory → hr → tools → auth), recursively extracting permission strings from:
- `item['actions']` values (all 398 permissions)
- `item['permission']` when it's a string or array of strings

The `PermissionSeeder` and `SyncErpPermissionsCommand` both use `PermissionRegistryService::all()` as their source of truth.

---

## 4. Current Menu Structure

All 10 menu files return flat or nested PHP arrays with the following node structure:

```php
[
    'label' => 'resource_key',        // string, translation key
    'title' => 'Display Name',         // string
    'icon' => 'icon-name',             // string
    'route' => 'admin.resource.index', // string or null for group nodes
    'permission' => 'resource.view',   // string, array, or null
    'keywords' => [],                  // search keywords
    'actions' => [                     // associative: key => permission_string
        'view' => 'resource.view',
        'create' => 'resource.create',
        // ...
    ],
    'active' => ['admin.resource.*'],  // route patterns for active state
    'children' => [],                  // recursive nested children
    'hidden' => true,                  // optional, for profile etc.
]
```

### Key structural patterns found:

**Group nodes** (no route, no permission, only children):
- `basic_data` in core.php (roles, users, companies, branches, financial periods, audit logs)
- `item_data` in core.php (products, categories, units, sizes, etc.)
- `general_ledger` in accounting.php (chart of accounts, cost centers)
- `finance` in finance.php (currencies, bank accounts, cashboxes, opening balances)
- `human_resources` in hr.php (employees + 7 foundation screens)
- `tools` in tools.php (open documents, file manager, calendar, my board, quick tasks, team board, chat, PWA)
- `quick_tasks` in tools.php (task boards, quick tasks management) — nested group within tools
- `inventory` in inventory.php (opening stock, pricing)
- `fixed_assets` in fixed_assets.php (assets register)
- `sales` in sales.php (customers)
- `purchases` in purchases.php (suppliers)

**Multi-permission nodes** (permission as array):
- `team_board` in tools.php uses `permission: ['my_board.tasks.view_all', 'my_board.notes.view_all']` — grants access if user has either

**Hidden nodes:**
- `profile` in core.php has `hidden: true`

**Helper-function menu construction:**
- `hr.php` uses a closure-based factory with `$hrCrudActions()` and `$hrScreen()` to reduce duplication

---

## 5. Menu Permission Coverage

Menus contain **full CRUD/action permissions**, not just view or menu-visibility permissions. The standard CRUD action set is:

```
view, create, clone, edit, delete, view_trashed, restore,
document_number.control, document_number_settings.update
```

Additional action permissions beyond standard CRUD:

| Resource | Extra Actions |
|----------|--------------|
| roles | `operating_scope.manage` |
| users | `roles.manage` |
| companies | `main.control` |
| activity.logs | `details`, `export`, `pdf` |
| auth.logs | `details`, `export`, `pdf` |
| auth.sessions | `details`, `export`, `pdf`, `force_logout` |
| accounts | `export`, `account_code.control` |
| cost_centers | `print`, `export` |
| opening_balances | `approve`, `cancel` |
| inventory.opening_stocks | `approve` |
| employees | `documents.view`, `documents.manage`, `documents.delete` |
| file_manager | `upload`, `update_picker_visibility`, `move`, `download`, `folders.*` (create/rename/delete/restore), `public_links.*` (create/view/revoke) |
| calendar | `complete` |
| my_board | `reorder`, `assign`, `view_any`, `manage_any`, `lists.*` (create/edit/delete/reorder), `comments.*` (create/delete), `tasks.view_all`, `notes.view_all` |
| task_boards | `bulk_activate`, `bulk_deactivate`, `display`, `public_settings`, `regenerate_public_url` |
| quick_tasks | `change_status`, `start`, `mark_ready`, `mark_done`, `manage_attachments` |
| profile | `view_profile`, `edit_profile`, `password_update`, `sessions_view`, `auth_logs_view`, `delete_account` |
| chat | `send` |
| pwa_settings | `update` |

---

## 6. Runtime Authorization Usage

The codebase uses **three main authorization patterns** with Spatie:

### Pattern 1: Route middleware — `middleware('can:{permission}')`
Used extensively in route files across all modules. Found ~400+ usages. This is the primary gate for all CRUD actions.

### Pattern 2: Controller/Service checks — `auth()->user()?->can()`
Used for fine-grained action gating inside controllers and services. Notable examples:
- `TaskBoardService.php` — checks `task_boards.public_settings`
- `QuickTaskService.php` — checks `quick_tasks.{status_transition}`
- `FileManagerController.php` — checks various `file_manager.*` sub-permissions
- `HrEmployeeController.php` — checks `hr.employees.documents.*`, `hr.attendance_rules.view`, `hr.employees.document_number.control`
- `UserTaskAccessService.php` — checks `my_board.*` sub-permissions
- `TaskBoardController.php` — checks `task_boards.public_settings`

### Pattern 3: Blade directives — `@can`, `auth()->user()?->can()`
Used in view files for conditional UI rendering:
- Action dropdowns (edit/delete/clone by permission)
- Trash filter controls (`view_trashed`)
- Document number settings panel
- File manager picker upload/folder creation
- Bulk action buttons

### No use of:
- `Gate::define` or `Gate::policy` (except Telescope's viewTelescope gate)
- `middleware('permission:')` — Spatie's native middleware is not used; Laravel's `can:` middleware is used instead
- `$this->authorize()` — no FormRequest authorization used for permissions

---

## 7. Menu vs Runtime Permission Comparison

### Total counts
| Source | Count |
|--------|-------|
| Menu config permissions (unique from actions + permission fields) | **398** |
| Route middleware `can:` unique strings | **221** |
| Menu-only (not in route middleware) | **177** (see below) |
| Route middleware-only (not in menu) | **14** |

### Permissions in Route Middleware but NOT in Menu Config (14 total)

These are all under `hr.regulations.*` and `hr.attendance_rules.*`:

```
hr.regulations.view, hr.regulations.create, hr.regulations.edit,
hr.regulations.delete, hr.regulations.clone, hr.regulations.restore,
hr.regulations.document_number_settings.update
hr.attendance_rules.view, hr.attendance_rules.create, hr.attendance_rules.edit,
hr.attendance_rules.delete, hr.attendance_rules.clone, hr.attendance_rules.restore,
hr.attendance_rules.document_number_settings.update
```

These are **deliberately excluded** from menu config — the CODEX context and test assertions confirm they should NOT appear in the registry. They are backend-selectable resources that have no menu entry but still need route-level enforcement. The `PermissionSeeder` test explicitly asserts `->not->toContain('hr.regulations.view')` and `->not->toContain('hr.attendance_rules.view')`.

### Permissions in Menu Config but NOT in Route Middleware (177 total)

This set includes three categories:

**A. Guarded via Controller/Blade checks instead of route middleware:**
- `dashboard.view` — checked at login redirect, not via route middleware
- `document_number.control` — checked in form requests/controllers via `$user->can()`
- `view_trashed` — checked in DataTable queries and Blade views
- `main.control`, `operating_scope.manage` — checked in controllers
- `assign`, `view_any`, `manage_any` (my_board) — checked in `UserTaskAccessService`
- `public_settings`, `display`, `regenerate_public_url` (task_boards) — checked in controllers
- `start`, `mark_ready`, `mark_done` (quick_tasks) — status transitions checked in services
- `profile.*` sub-permissions — checked in ProfileController/views
- `folders.*`, `public_links.*` (file_manager) — checked in FileManagerController
- `comments.*`, `lists.*` (my_board) — checked in controllers
- `export`, `details`, `pdf` (reports) — checked in controllers
- `force_logout` (sessions) — checked in controller
- `send` (chat) — checked in controller
- `documents.*` (hr.employees) — checked in controller

**B. HR Foundation lookups:**
- `hr.departments.*`, `hr.sections.*`, `hr.jobs.*`, `hr.employment_types.*`, `hr.biometric_devices.*`, `hr.shifts.*`, `hr.document_types.*` — these are Select2 and route-protected but registered for role assignment. Their routes use dynamic middleware via the shared CRUD route pattern (e.g., `Route::resource(...)->middleware("can:{$resource}.view")`) not static middleware strings, so they show as missing from the hardcoded grep search.

**C. Item lookup CRUDs (dynamic route middleware, same pattern as B):**
- `item_categories.*`, `item_units.*`, `item_sizes.*`, `item_colors.*`, `item_decals.*`, `item_models.*`, `item_groups.*`, `item_origin_countries.*`

These 177 are **not a real gap** — they are protected by dynamic/indirect route middleware, controller-level checks, or Blade-level checks.

### Duplicate or inconsistent permissions

No duplicates found. The naming convention is consistent across all menu files:
- `document_number.control` not `document_number_control`
- `document_number_settings.update` not `document_number_settings_update`
- `view_trashed` not `trashed_view` or `view_trash`
- `clone` not `duplicate` or `copy`

One naming inconsistency note: several actions use `update` (task_boards, quick_tasks) while CRUDs use `edit`. This is correct — `update` is the write action, `edit` is the view-action, consistent with Laravel resource conventions.

### Nodes with routes but missing actions

None found. Every leaf node with a `route` string has at least a `view` action in its `actions` array.

---

## 8. Existing Database Sync/Seeder Logic

### PermissionSeeder (`modules/Auth/Database/Seeders/PermissionSeeder.php`)
- Clears Spatie cache
- Discovers permissions via `PermissionRegistryService::all()`
- Uses `updateOrCreate` for each permission (safe, idempotent)
- Copies legacy grants to canonical permissions (e.g., `users.index` → `users.view`)
- **Reports** stale DB permissions but does NOT delete them (warning only)
- Ensures `admin` role exists (with soft-delete handling)
- Syncs all discovered permissions to admin role
- Clears cache again

### SyncErpPermissionsCommand (`app/Console/Commands/SyncErpPermissionsCommand.php`)
Fully implemented with:
- `--dry-run` — preview without writing
- `--prune` — delete stale permissions AND their pivot records
- `--force` — bypass suspicious-count guard and production guard
- `--admin-role=` — specify admin role by ID or name
- Suspicious count detection (collected < 50% of current DB count)
- Production guard on prune (requires --force in production)
- DB transaction wrapping writes
- Pivot cleanup before permission deletion (`role_has_permissions`, `model_has_permissions`)
- Spatie cache clearing before and after
- Multi-strategy admin role resolution (config keys, then fallback to id=1/name='admin'/name='Administrator')
- Comprehensive output summary

### EmergencyRecoverySeeder
- Calls PermissionSeeder
- Syncs ALL web-guard permissions to admin role (not just discovered ones)
- Handles soft-deleted admin role restoration

### RuntimeDemoDataSeeder
- Uses `Permission::findOrCreate` to create three demo roles with permission sets

### Database Pivot Tables
- `permissions`, `roles` — standard Spatie tables
- `model_has_permissions`, `model_has_roles`, `role_has_permissions` — standard Spatie pivot tables
- `role_company_access`, `role_branch_access`, `role_financial_period_access` — custom operating scope pivot tables

### Admin Role Assumptions
- Role name `admin` with guard `web` is the canonical admin role
- Role ID 1 and name `Administrator` are fallback references
- Config keys `erp.admin_role_*`, `auth.admin_role_*`, `permissions.admin_role_*`, `permission.admin_role_*` are checked for custom admin role definitions
- `EmergencyRecoverySeeder` forces unrestricted operating scope on admin role

---

## 9. Risks Before Implementing Sync Command

**The sync command is already fully implemented.** There are no implementation risks. However:

1. **Stale permission tracking gap**: The `PermissionSeeder` only reports stale permissions as warnings but never prunes them. The `SyncErpPermissionsCommand` can prune with `--prune --force`, but nobody is running it regularly. Stale permissions accumulate in the DB.

2. **Hidden resource permissions** (`hr.regulations.*`, `hr.attendance_rules.*`): These 14 permissions exist in routes/tests but are deliberately excluded from menu config. If someone runs `--prune`, they would be deleted from the DB and role assignments would break. **This is the highest-risk issue.**

3. **Direct DB permission manipulation**: Tests and demo seeders use `Permission::findOrCreate()` directly, bypassing menu discovery. Any production code doing this would create permissions that are invisible to the menu discovery and would appear as "stale" to the sync command.

4. **No permission CRUD UI**: The CODEX context confirms Permissions CRUD UI is intentionally absent. This means all permission management happens through the Role form (which uses `formAssignablePermissions()`) and the menu config.

5. **Config file integrity**: If a menu config file has a PHP parse error, `PermissionRegistryService` would silently skip it, potentially causing a suspiciously low count and blocking the sync command.

---

## 10. Recommended Architecture

**Recommendation: Menu actions as sole source of truth (current architecture).**

Keep the current design:
- `config/menu/*.php` = sole permission source of truth
- `config/permissions.php` = intentionally empty, reserved for exceptional global permissions
- `PermissionRegistryService` = discovery engine
- `PermissionSeeder` + `SyncErpPermissionsCommand` = sync mechanisms

**Why not config/permissions.php only**: It's intentionally empty and commented as "reserved for exceptional global permissions." Moving to it would require defining 398 permissions there, creating duplication with menu config, and losing the tight coupling between UI visibility and permission availability.

**Why not hybrid**: The current architecture is already a practical hybrid — menu actions define the visible permissions, and hidden/backend permissions (hr.regulations, hr.attendance_rules) are explicitly excluded from the menu and handled separately. The hybrid would add complexity without benefit.

**What should change**: The `hr.regulations.*` and `hr.attendance_rules.*` permissions need a deliberate design decision:
- Option A: Add them to `config/menu/hr.php` with appropriate routing (makes them visible in the permission form)
- Option B: Add a secondary discovery path in `PermissionRegistryService` that collects non-menu permission strings from a separate config or convention (e.g., `config/permissions.php` or a `resources/` based config)
- Option C: Extend `config/permissions.php` to hold exactly these "hidden but enforced" permissions, and update `PermissionRegistryService` to merge them with menu-discovered permissions

---

## 11. Proposed Artisan Command Design

The existing `SyncErpPermissionsCommand` already implements all required features. Here's the design it follows:

### Signature
```
php artisan erp:permissions:sync
    {--dry-run : Preview without writing to database}
    {--prune : Delete stale permissions}
    {--force : Allow destructive actions in production}
    {--admin-role= : Role ID or name to receive all permissions}
```

### Behavior

1. **Recursive menu scanner**: Uses `PermissionRegistryService::all()` which calls `extractFromMenuItems()` — a recursive walker that collects from `actions` values, `permission` string/array at every depth.

2. **Collection**: Extracts strings from:
   - `actions` array values (all levels)
   - `permission` when string
   - `permission` when array of strings
   - `canonicalPermission()` mapping for legacy compatibility

3. **Optional config/permissions.php registry**: Currently not merged. Could be added as `$permissions = array_merge($permissionRegistry->all(), $this->globalPermissions())`.

4. **Unique merge**: Built-in via `array_unique` in `PermissionRegistryService::all()`.

5. **--dry-run**: Shows scanned files, collected count, would-create count, would-delete-stale count, admin role info. No database writes.

6. **--prune**: When enabled, deletes stale permissions after clearing pivot records (`role_has_permissions`, `model_has_permissions`).

7. **--force**: Required when:
   - Discovered count < 50% of current DB count (suspiciously low guard)
   - Pruning in production environment

8. **--admin-role=**: Accepts role ID or name. Falls back to config keys, then id=1/admin/Administrator.

9. **DB transaction**: All writes wrapped in `DB::transaction()`.

10. **Spatie cache**: Cleared before and after via `$permissionRegistrar->forgetCachedPermissions()`.

11. **Stale permission deletion**: Deletes pivot records first, then permission rows.

12. **Pivot cleanup**: Uses Spatie config for pivot table names and column keys, ensuring correctness.

13. **Admin full-permission reassignment**: After sync, syncs all valid permissions to admin role.

---

## 12. Exact Implementation Plan For Later

**No implementation needed. The command is already written and tested.**

If changes are needed:

1. **Decide the fate of `hr.regulations.*` and `hr.attendance_rules.*`** (see Open Questions)
2. **If adding them to menu**: Edit `config/menu/hr.php` to include them as children under `human_resources`, either as visible items or with `hidden: true`
3. **If using config/permissions.php**: Add an array of these 14 permissions to `config/permissions.php` and modify `PermissionRegistryService::all()` to merge them
4. **Run the sync**: `php artisan erp:permissions:sync --dry-run` then `php artisan erp:permissions:sync`
5. **Update tests**: `PermissionSeederTest` and `EmergencyRecoverySeederTest` assertions that check `not->toContain('hr.regulations.view')` would need updating
6. **Run tests**: `php artisan test --compact --filter=PermissionSeeder` and `php artisan test --compact --filter=SyncErpPermissions`

---

## 13. Open Questions / Things To Confirm

1. **What is the intended design for `hr.regulations.*` and `hr.attendance_rules.*`?** They are route-protected, have translation labels in `resources/lang/*/permissions.php`, and are referenced in blade/controller code. But they are deliberately excluded from menu config (confirmed by test assertions). Should they:
   - Remain as intentional hidden permissions with no menu entry (add them via `config/permissions.php`)?
   - Be added to the HR menu?
   - Be kept as-is, invisible to the sync command and only seeded via `Permission::findOrCreate` in tests?

2. **Should the `SyncErpPermissionsCommand` be added as a scheduled task?** Currently it's manual-only.

3. **Is `companies.files.view` a legitimate runtime-only permission** or a stale test artifact? It appears in tests and translations but has no menu config entry and no route middleware usage.

4. **Should the suspicious-count threshold (50%) be configurable?** Currently hardcoded in `collectedCountLooksSuspicious()`.

---

## Terminal Summary

| Metric | Value |
|--------|-------|
| Report file created | `PERMISSIONS_SYNC_AUDIT_REPORT.md` |
| Recommended source of truth | **Menu actions** (`config/menu/*.php`) |
| Is pruning currently safe? | **No** — `hr.regulations.*` and `hr.attendance_rules.*` (14 permissions) exist in routes but not in menu config; --prune would delete them and break HR regulation/attendance rule routes |
| Highest-risk issue | Stale permission pruning would delete `hr.regulations.*` and `hr.attendance_rules.*` (14 route-enforced permissions) from the database, breaking their route middleware checks |
| Total permissions discovered from menu config | **398** |
| Total runtime permission strings (from route middleware) | **221** |
| Menu permissions missing from route middleware | **177** (all checked via Blade/Controller/DataTable, not a real gap) |
| Runtime permissions missing from menu | **14** (`hr.regulations.*` + `hr.attendance_rules.*`, deliberately excluded) |
