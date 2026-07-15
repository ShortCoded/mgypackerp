# CODEX_PROJECT_CONTEXT.md

Last context rewrite: 2026-05-13
Project root from latest read-only scan: `/mnt/Data/ShortCoded/Projects/EgyptianFurniture/ERP`

This is the only canonical project context for future Codex work.

Codex must read this file first before changing the project. For normal bug fixes or small UI changes, inspect only the directly relevant files. Do not perform a full project scan unless the user explicitly asks for a context rewrite, architecture audit, or project-wide standard update.

If this file and the actual code disagree, trust the code. Fix the implementation if the task requires it, and update this file only when the task changes a real convention, architecture rule, route surface, permission model, or feature contract.

Do not use old context backups, historical prompt libraries, archived notes, or previous generated contexts as source of truth unless the user explicitly asks for a context/audit rewrite.

---

## 1. Current Scan Baseline

Latest read-only scan facts:

- Git branch: `main`.
- Project root: `/mnt/Data/ShortCoded/Projects/EgyptianFurniture/ERP`.
- PHP: `8.2.12`.
- Laravel Framework: `12.58.0`.
- Laravel Breeze: `2.4.1`.
- Laravel Boost: `2.4.4`.
- Livewire: `4.2.4`.
- Pest: `3.8.6`.
- PHPUnit: `11.5.50`.
- Node: `24.15.0`.
- npm: `11.12.1`.
- pnpm: `10.33.2`.
- Lockfiles present: `composer.lock`, `pnpm-lock.yaml`.
- `package-lock.json` was not present in the latest scan.
- Database driver: PostgreSQL / `pgsql`.
- Cache driver: database.
- Session driver: database.
- Queue driver: database.
- Mail driver: log.
- Non-vendor routes from latest scan: `778`.
- Duplicate route names found: none.
- Duplicate method/URI routes found: none.

Important dirty worktree note from latest scan:

- Modified/uncommitted auth seat-limit-related files existed at scan time.
- Untracked files existed:
  - `config/erp_seats.php`
  - `modules/Auth/Services/OnlineSeatLimitService.php`
- Treat the online seat-limit implementation as pending/uncommitted until the user confirms it was committed or finalized.
- Do not describe the seat-limit feature as canonical committed behavior unless current code proves it is now committed and integrated.

---

## 2. Project Identity

This is a Laravel 12 modular-monolith ERP application for complex business operations, especially manufacturing, export/station workflows, HR, accounting foundations, finance foundation, warehouses, purchasing, sales, production, costing, delivery, assets, maintenance, reporting, permissions, audit trails, soft deletes, and Arabic/English UI.

The current codebase is still a foundation-stage ERP, not a complete manufacturing/export ERP.

Implemented modules in actual code:

```text
modules/
├── Accounting
├── Auth
├── Core
├── Finance
└── HR
````

Not implemented as real ERP modules yet:

* Sales
* Purchasing
* Warehouse / inventory
* Production / manufacturing
* Costing
* Delivery / logistics
* Assets
* Maintenance

Do not generate those future ERP modules unless explicitly requested.

---

## 3. Technology Stack

Backend:

* PHP `^8.2`.
* Laravel Framework `12.x`.
* PostgreSQL intended database.
* Laravel Breeze installed, but auth is heavily customized under `modules/Auth`.
* Spatie Permission.
* Spatie Activitylog.
* Yajra Laravel DataTables.
* Maatwebsite Excel.
* mPDF.
* Pest/PHPUnit tests.
* Laravel Boost / MCP packages may exist, but their tools are not always exposed to Codex sessions.

Frontend:

* Blade templates.
* Falcon Admin Template `v3.26.0`.
* Bootstrap/Falcon static runtime.
* jQuery-heavy AJAX UI.
* DataTables.
* SweetAlert2.
* Select2.
* Flatpickr.
* FullCalendar for Calendar.
* Summernote for My Board rich descriptions/notes where available.
* Vite/Tailwind may exist, but ERP runtime UI is Falcon/Bootstrap-first and mostly uses static scripts under `public/assets/js/modules`.

Package manager rules:

* Latest scan found `pnpm-lock.yaml` and no `package-lock.json`.
* Do not run `npm install`, `pnpm install`, or change frontend dependencies without explicit approval.
* Prefer existing checked-in Falcon/public assets for UI changes.
* Do not edit vendor/minified Falcon assets.

Local destructive reset:

* Local/dev database reset command: `php artisan erp:reset-local-db`.
* Clean baseline only: `php artisan erp:reset-local-db --force`.
* Clean baseline plus demo data: `php artisan erp:reset-local-db --demo --force`.
* Use only on local/development/testing databases. It runs `migrate:fresh`, wipes all data, and is guarded against production-like environments/database names.

---

## 4. Architecture and File Placement

Real module location:

```text
modules/{Module}/
```

Do not create new code under `app/Modules`.

Standard placement:

```text
modules/{Module}/Routes/web.php
modules/{Module}/Routes/api.php
modules/{Module}/Http/Controllers
modules/{Module}/Http/Requests
modules/{Module}/Services
modules/{Module}/DataTables
modules/{Module}/Models
modules/{Module}/Database/Migrations
resources/views/modules/{module}/...
resources/lang/{ar,en}/...
public/assets/js/modules/{Module}/...
public/assets/css/user.css
config/menu/*.php
config/document_numbers.php
```

Notes:

* `App\Models\User` remains the User model.
* Module views live under `resources/views/modules`, not inside `modules`.
* Frontend JS lives under `public/assets/js/modules`, not inside `modules`.
* Translations live under `resources/lang/ar` and `resources/lang/en`.
* `App\Providers\ModuleServiceProvider` scans `modules/*` and loads module routes/migrations.
* Laravel 12 middleware/routing configuration is in `bootstrap/app.php`.
* Providers are registered in `bootstrap/providers.php`.
* Console scheduling is in `routes/console.php`.
* Console commands live in `app/Console/Commands`.

---

## 5. Source-of-Truth Rules for Codex

For every future task:

1. Read this file first.
2. Inspect the actual relevant files before changing code.
3. The actual code wins over documentation.
4. Do not scan the whole project for small tasks.
5. Do not update this file unless a real project convention, architecture rule, route surface, or feature contract changes.
6. Do not touch unrelated modules.
7. Do not run unrelated migrations/tests.
8. Use focused tests/commands.
9. Report what could not be verified.
10. Never claim browser/runtime verification was done unless it was actually done.

For DataTables bugs:

* Prove frontend `columns` and backend JSON fields match.
* Do not suppress DataTables warnings; fix the mismatch.

For Calendar/My Board/Falcon UI bugs:

* Compare against the relevant local `FalconTemplate` example.
* Inspect the actually loaded Blade/JS/CSS assets.

For normal future prompts, use this contract:

```text
Read CODEX_PROJECT_CONTEXT.md first.

Work only on [specific page/feature/bug].
Inspect only relevant route/controller/service/model/request/view/JS/lang/test files.
Do not scan the whole project.
Do not touch unrelated modules.
Do not update docs unless a real convention changes.
Do not run unrelated migrations.
Fix the exact issue.
Run targeted commands/tests only.

Final response:
- Files changed
- Root cause
- Exact fix
- Commands/tests run
- Remaining limitations
```

---

## 6. Module and Feature Inventory

### Auth Module

Status: complete foundation.

Implemented:

* Login/logout.
* Password reset.
* Registration disabled.
* Profile.
* Lock screen.
* Remember-me forces lock screen before app access.
* Active/inactive/blocked/deleted user enforcement.
* Duplicate login prevention.
* Session timeout status/touch endpoints.
* User presence/session tracking.
* Users CRUD.
* Roles CRUD.
* Auth logs report.
* Activity logs report.
* Active sessions report.

Important:

* Auth/security/session events go to `auth_logs`.
* ERP/business CRUD actions go to Spatie `activity_log`.
* Never log passwords, reset tokens, remember tokens, CSRF tokens, raw session IDs, sensitive cookies, or full sensitive request payloads.
* Online seat-limit files existed as uncommitted work at latest scan. Verify current git status before treating seat-limit as canonical.

### Core Module

Status: complete foundation for many app surfaces.

Implemented foundation areas:

* Dashboard shell.
* Settings/branding.
* Menu service.
* Breadcrumbs.
* Companies CRUD.
* Branches CRUD.
* Financial Periods CRUD.
* Units / Sizes / Item Models / Item Categories / Item Groups via ItemLookup stack.
* Products CRUD.
* Currencies CRUD, routed in Core but shown under Finance menu.
* User tasks.
* My Board.
* Calendar.
* Internal chat.
* Archive/File Manager.
* Public archive links.
* Report export/PDF helpers.
* Document numbers.
* CRUD generators.
* Select2 shared endpoints.
* Tools pages.

Risks:

* Branches and Financial Periods have delete dependency TODOs unless current code proves they were fixed after this context.
* CRUD JS duplication remains a maintainability risk.

### Accounting Module

Status: partial foundation.

Implemented:

* General Ledger menu.
* Internal account classifications.
* Chart of Accounts CRUD.
* Account list/tree views.
* Exports.
* Select2/account endpoints.
* Seeded system root accounts.

Not fully implemented:

* Full accounting posting engine.
* Journal entry UI/routes were not found in latest scan, even though model/service/config-like files may exist.
* Financial statements and advanced accounting reports.

### Finance Module

Status: complete foundation CRUDs.

Implemented:

* Bank Accounts.
* Cashboxes.
* Opening Balances.
* Opening Balances include approve/cancel routes.
* Bank Accounts and Cashboxes link to postable Chart of Accounts accounts classified as bank/cash.
* Opening Balances are scoped by company, financial period, optional branch, account, and currency.

Important:

* Opening Balances do not generate journal entries yet unless current code later proves otherwise.
* Currencies are routed in Core but appear under Finance menu.

### HR Module

Status: complete/partial foundation depending on area.

Implemented:

* 16 HR lookup CRUDs.
* HR regulations.
* Attendance rules.
* HR enterprise foundation such as org units/departments/jobs and related structure screens.
* Employees with document routes/uploads.

HR lookup list:

* Countries
* Governorates
* Cities
* Areas
* Nationalities
* Religions
* Qualifications
* Universities
* Faculties
* Specializations
* Insurances
* Military Services
* Allowances
* Work Permissions
* Hiring Statuses
* Identifications

Risks:

* Verify migrations before HR employee/regulation work in any environment.
* Some HR menu visibility permissions may intentionally use hidden permission keys such as `hr.hidden_org_unit_types.view`; document or normalize them before broader permission refactors.

---

## 7. Routing and Public Identifier Standard

Route naming:

* Admin routes use `admin.*`.
* HR routes use `admin.hr.*`.
* Finance routes use `admin.finance.*`.
* Accounting routes use `admin.accounting.*`.

Public identifiers:

* Normal CRUDs use `doc_num` in URLs, JS payloads, DataTables, visible HTML, exports, and activity log properties.
* Calendar/chat-style resources may use `public_uuid`.
* Report/log-style resources may use `public_id`.
* Internal database IDs must not be exposed in UI, routes, visible HTML, JS payloads, DataTables JSON, exports, or activity log properties.

Route ordering:

* Fixed routes must come before `{model:doc_num}` dynamic routes.
* Examples: `data`, `create`, `bulk-delete`, `document-number-settings`, `restore` must appear before `{record:doc_num}` routes.

Latest scan facts:

* Non-vendor route count: `778`.
* Duplicate route names: none found.
* Duplicate method/URI pairs: none found.
* No internal `{id}` route binding patterns found in the latest scan.

---

## 8. Permissions and Menu Standard

Source of truth:

* `config/menu/*.php` is the permission source of truth.
* `config/permissions.php` is reserved for exceptional global permissions and is normally empty/comment-only.
* `PermissionRegistryService` discovers permissions from menu config.
* `PermissionSeeder` creates/updates discovered permissions.
* Stale DB permissions are reported/kept, not deleted automatically.
* The `admin` role must exist and receive all discovered permissions.
* Do not add permissions only directly to the DB.

Current menu files:

```text
config/menu/auth.php
config/menu/core.php
config/menu/hr.php
config/menu/accounting.php
config/menu/finance.php
```

Standard CRUD permissions:

```text
{permission}.view
{permission}.create
{permission}.clone
{permission}.edit
{permission}.delete
{permission}.view_trashed
{permission}.restore
{permission}.document_number.control
{permission}.document_number_settings.update
```

Notes:

* Bulk delete generally uses the canonical delete permission unless a resource has an explicitly approved separate policy.
* Hidden menu items may exist for registry/seeding even when no visible route group exists.
* Permissions CRUD UI is not implemented. Do not claim `admin.permissions.*` routes/controllers exist unless the code proves it.

---

## 9. Document Number Standard

Common fields:

```text
doc_number
doc_num
```

Rules:

* `doc_number` is numeric.
* `doc_num` is formatted public identifier such as `Role-00001`, `Company-00001`, `Unit-00001`.
* `DocumentNumberService` is the source of truth.
* Runtime prefix/padding can be overridden through settings.
* PostgreSQL path can lock the table during number generation.
* Active uniqueness should use partial unique indexes where `deleted_at IS NULL`.
* Restore flows must check active conflicts before restoring.
* Never accept `doc_num` directly from request data.
* Manual `doc_number` edits require `{permission}.document_number.control`.

Config:

```text
config/document_numbers.php
```

---

## 10. Common CRUD Database Fields

Common CRUD fields:

```text
doc_number
doc_num
status
notes
created_by
updated_by
deleted_by
restored_by
created_at
updated_at
deleted_at
restored_at
```

Rules:

* Main CRUDs should use soft deletes.
* Deletes must set `deleted_by` where the column exists.
* Restores must clear delete fields and set restore fields where supported.
* Do not force delete unless explicitly approved.
* Use safe additive PostgreSQL migrations.
* Use partial unique indexes for active uniqueness when possible.

---

## 11. Audit and Activity Logging Standard

CRUD audit:

* Use `Modules\Core\Services\CrudAuditService` for CRUD audit mutations.
* Do not hand-roll direct `deleted_by` saves around `delete()` or `restore()` in new CRUDs.

Required audit behavior:

* Create sets `created_by` and `created_at`, then clears `updated_by`, `updated_at`, `deleted_by`, `deleted_at`, `restored_by`, and `restored_at`.
* Update sets `updated_by` and `updated_at` only when real business data changes.
* No-change updates must not save the model and must not write activity update logs.
* Soft delete sets only `deleted_by` and `deleted_at`, while preserving create/update audit.
* Restore clears delete audit, sets restore audit, and preserves create/update audit.

Activity logging:

* Use `Modules\Core\Services\ActivityLogger`.
* Use `Modules\Core\Services\ActivityLogProperties` for new CRUD activity property schemas.
* Log ERP/business CRUD actions to Spatie `activity_log`.
* Never log internal IDs, full Eloquent models, passwords, tokens, raw session IDs, cookies, or request payload dumps.

Canonical activity property shape:

```json
{
  "record": {
    "type": "item_units",
    "label": "Kilogram",
    "doc_num": "Unit-00001"
  },
  "action": {
    "type": "create",
    "label_key": "item_units.actions.create"
  },
  "changes": {
    "name": {
      "old": "Old",
      "new": "New"
    }
  },
  "related": {
    "source": {
      "type": "item_units",
      "label": "Source Unit",
      "doc_num": "Unit-00002"
    }
  },
  "bulk": {
    "count": 2,
    "doc_nums": ["Unit-00001", "Unit-00002"]
  },
  "meta": {
    "submit_action": "save_edit"
  }
}
```

Auth/security logging:

* Auth/session/security events go to `auth_logs` only.
* AuthLogService sanitizes passwords, tokens, cookies, authorization/session/API keys/secrets.
* Presence heartbeat must not spam `auth_logs` or `activity_log`.

---

## 12. Canonical Simple Master-Data CRUD Base: Units and Sizes

The current canonical simple master-data CRUD reference is Units/Sizes Item Data.

Important: Units and Sizes are not standalone per-resource controller stacks. They are implemented through the shared Core ItemLookup stack.

### Units File Map

```text
modules/Core/Database/Migrations/2026_05_11_010500_create_item_lookup_tables.php
modules/Core/Database/Migrations/2026_05_11_051736_drop_code_from_item_lookup_tables.php
modules/Core/Models/ItemLookup.php
modules/Core/Models/ItemUnit.php
modules/Core/Services/ItemLookupRegistry.php
modules/Core/Services/ItemLookupDefinition.php
modules/Core/Http/Controllers/ItemLookupController.php
modules/Core/Services/ItemLookupService.php
modules/Core/Services/ItemLookupDocumentNumberSettingsService.php
modules/Core/Http/Requests/StoreItemLookupRequest.php
modules/Core/Http/Requests/UpdateItemLookupRequest.php
modules/Core/Http/Requests/BulkDeleteItemLookupRequest.php
modules/Core/Http/Requests/UpdateItemLookupDocumentNumberSettingsRequest.php
modules/Core/DataTables/ItemLookupDataTable.php
resources/views/modules/core/item-lookups/index.blade.php
resources/views/modules/core/item-lookups/form.blade.php
resources/views/modules/core/item-lookups/partials/actions.blade.php
resources/views/modules/core/item-lookups/partials/checkbox.blade.php
resources/views/modules/core/item-lookups/partials/form-actions.blade.php
resources/views/modules/core/item-lookups/partials/form-footer.blade.php
resources/views/modules/core/item-lookups/partials/form-header.blade.php
public/assets/js/modules/Core/item-lookups.js
resources/lang/en/item_lookups.php
resources/lang/ar/item_lookups.php
resources/lang/en/item_units.php
resources/lang/ar/item_units.php
config/menu/core.php
config/document_numbers.php
tests/Feature/Core/ItemLookupCrudTest.php
```

Units document number config:

```text
key: item_units
prefix: Unit-
padding: 5
```

### Sizes File Map

Sizes use the same shared ItemLookup stack.

```text
modules/Core/Models/ItemSize.php
registry key: item_sizes
route key: item-sizes
route names: admin.item-sizes.*
permission prefix: item_sizes
resources/lang/en/item_sizes.php
resources/lang/ar/item_sizes.php
config/document_numbers.php key: item_sizes
prefix: Size-
padding: 5
tests/Feature/Core/ItemLookupCrudTest.php
```

### Simple CRUD Behavior Proven by Units/Sizes

Units/Sizes prove the expected simple CRUD behavior:

* Public `doc_num` routing.
* Falcon-style index/form layout.
* Server-side Yajra DataTables.
* Checkbox/bulk selection by public `doc_num`.
* No internal ID output.
* Create/view/edit/clone/delete/bulk-delete/restore.
* Trash filter: `active`, `trashed`, `all`.
* Visible labels: `Actual records`, `Trashed records`, `All records` and Arabic equivalents.
* Trashed rows allow view/restore only.
* No edit/update/clone/delete for trashed rows.
* Document-number control/settings.
* Restore conflicts for `name`, `doc_number`, and `doc_num`.
* No-change responses.
* `CrudAuditService` audit behavior.
* `ActivityLogProperties` canonical activity schema.
* Bilingual translations.
* Focused tests.

### Simple CRUD Field Set

Use this only for simple master-data CRUDs:

```text
doc_number
doc_num
name
status
notes
created_by
updated_by
deleted_by
restored_by
restored_at
timestamps
softDeletes
```

### When Units/Sizes Are Not Enough

Units/Sizes are not enough as the only reference for rich ERP CRUDs because they do not cover:

* Relation fields.
* Select2 selectors.
* Date fields.
* File uploads.
* Dependent child rows.
* Approval/cancel workflows.
* Complex business dependency delete checks.
* Complex reports/exports.

For rich CRUDs, inspect the closest actual resource before coding.

---

## 13. Rich CRUD References

Use these as references depending on the requested feature:

* Companies: rich Core CRUD with business identity fields, files/branding-like behavior, location/selectors, main-company rules, trash/restore.
* Branches: branch/hall/refrigerator foundation, but verify dependency delete TODOs.
* Financial Periods: period state/date-overlap behavior, but verify dependency delete TODOs.
* Products: rich Core product CRUD foundation.
* Currencies: Core-routed Finance menu CRUD.
* Bank Accounts/Cashboxes: Finance CRUDs linked to postable accounts.
* Opening Balances: Finance operational foundation with approve/cancel.
* Chart of Accounts: Accounting tree/list/export/select2 reference.
* HR Regulations/Attendance Rules/Employees: HR full CRUD and document/reference patterns.

Do not blindly use Units/Sizes for rich operational resources.

---

## 14. Reports and File Manager Boundaries

Reports are not normal CRUDs.

Report references:

* Activity Logs report.
* Auth Logs report.
* Active Sessions report.
* Accounting exports.

Report rules:

* Use report-specific controllers/services/DataTables/export/humanizer/sanitizer classes.
* Keep raw technical JSON out of the main table and exports unless intentionally designed for details modal only.
* Use public identifiers.
* Export/details routes are separate from CRUD routes.

File Manager / Archive is not a normal CRUD.

File Manager rules:

* Use `FileManagerController`, archive models/services/views/JS.
* Use file/folder `doc_num` and public token routes.
* Preserve public link token/HMAC/encrypted storage behavior.
* Do not expose storage paths or internal IDs.
* Do not scaffold File Manager behavior from CRUD generator.

---

## 15. Frontend and Falcon Standard

Falcon Template `v3.26.0` is the UI reference.

Rules:

* Inspect local `FalconTemplate` before building UI.
* Do not edit `FalconTemplate/`, `public/vendors`, or minified/vendor Falcon assets.
* Copy/adapt structure into project-owned Blade/JS/CSS only.
* Use project wrappers before writing raw JS.
* Do not hardcode visible text.
* Preserve Arabic RTL and English LTR.

Shared JS helpers:

```text
public/assets/js/modules/Core/datatables-defaults.js      window.AppDataTables
public/assets/js/modules/Core/alerts.js                   window.AppAlerts
public/assets/js/modules/Core/select2-ajax.js             window.AppSelect2Ajax
public/assets/js/modules/Core/date-picker.js              window.AppDatePicker
public/assets/js/modules/Core/shortcuts.js                keyboard shortcuts
public/assets/js/modules/Core/page-cache-guard.js          bfcache/password guard
public/assets/js/modules/Core/report-ui.js                report UI helpers
public/assets/js/modules/Core/falcon-defaults.js           Falcon defaults
```

Falcon reference map:

| UI Need                           | FalconTemplate Reference                                                                                                                                                   |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CRUD index/card/table             | `FalconTemplate/modules/tables/advance-tables.html`, `FalconTemplate/modules/tables/bulk-select.html`                                                                      |
| CRUD forms                        | `FalconTemplate/modules/forms/basic/form-control.html`, `FalconTemplate/modules/forms/basic/layout.html`, `FalconTemplate/modules/forms/basic/validation.html`             |
| Dropdown action menus             | `FalconTemplate/modules/components/dropdowns.html`                                                                                                                         |
| Filter panels                     | `FalconTemplate/app/support-desk/table-view.html`                                                                                                                          |
| Buttons                           | `FalconTemplate/modules/components/buttons.html`                                                                                                                           |
| Modals                            | `FalconTemplate/modules/components/modals.html`                                                                                                                            |
| Date picker                       | `FalconTemplate/modules/forms/advance/date-picker.html`                                                                                                                    |
| Advanced select / Select2-like UI | `FalconTemplate/modules/forms/advance/advance-select.html`                                                                                                                 |
| File upload                       | `FalconTemplate/modules/forms/advance/file-uploader.html`                                                                                                                  |
| Tree view                         | `FalconTemplate/modules/components/treeview.html`                                                                                                                          |
| Reports/export dropdown           | `FalconTemplate/app/support-desk/reports.html`                                                                                                                             |
| Calendar                          | `FalconTemplate/app/calendar.html`, `FalconTemplate/modules/components/calendar.html`                                                                                      |
| Kanban/My Board                   | `FalconTemplate/app/kanban.html`                                                                                                                                           |
| Chat                              | `FalconTemplate/app/chat.html`                                                                                                                                             |
| Auth pages                        | `FalconTemplate/pages/authentication/card/login.html`, lock-screen pages                                                                                                   |
| Breadcrumbs                       | `FalconTemplate/modules/components/breadcrumbs.html`                                                                                                                       |
| Badges/status                     | `FalconTemplate/modules/components/badges.html`                                                                                                                            |
| Tabs/accordion                    | `FalconTemplate/modules/components/navs-and-tabs/tabs.html`, `FalconTemplate/modules/components/accordion.html`                                                            |
| RTL/dark/customizer/layout        | `FalconTemplate/demo/navbar-double-top.html`, `FalconTemplate/documentation/customization/configuration.html`, `FalconTemplate/documentation/customization/dark-mode.html` |

Canonical reusable UI patterns to preserve:

* DataTable card/table wrapper.
* CRUD action dropdown.
* Trash filter.
* Form header/footer/actions.
* Document number settings panel.
* AJAX validation error block.
* Select2/date picker wrappers.

---

## 16. DataTables Standard

Backend:

* Yajra DataTables classes live in `modules/*/DataTables`.
* Use explicit columns.
* Remove internal IDs from JSON.
* Use public `doc_num` for row actions and checkboxes.
* Use `DataTableSearchService` for multi-term PostgreSQL-aware search where applicable.
* Keep frontend column definitions exactly aligned with backend JSON.

Frontend:

* Shared defaults live in `public/assets/js/modules/Core/datatables-defaults.js`.
* Use Falcon-compatible card/table wrappers:

```text
.erp-datatable-card
.erp-datatable-wrapper
.erp-datatable-scroll
.erp-datatable
```

* Column visibility must protect checkbox/doc/actions columns.
* Tables must remain inside Falcon cards and must not render as detached raw DataTables.
* Removed visible columns must also be removed from JS `columns`.
* If JS asks for a field, backend must return it, or JS must stop asking for it.

---

## 17. Select2, Date Picker, and File Fields

Select2:

* Use `AppSelect2Ajax` / `.js-select2-ajax`.
* Relation selectors must use public identifiers such as `doc_num`, not internal IDs in visible HTML/JS payloads.
* Confirm the selected endpoint and hydration endpoint before adding a relation selector.

Date fields:

* Use `AppDatePicker` / `.js-date-picker`.
* Render text inputs with date format/locale/RTL-safe attributes.
* Use `DateFormatService` to parse display-format dates into storage `Y-m-d`.
* Display date/timestamps with `SettingService` / `DateFormatService`.
* Keep DB filtering/sorting on raw date/timestamp columns.

File uploads:

* Use existing archive/file upload conventions.
* Do not expose storage paths.
* Validate file count, extension, size, MIME where appropriate.
* Use Falcon file uploader/dropzone references, but adapt into project-owned code only.

---

## 18. Auth, Sessions, Presence, and Seat Limits

Auth implemented:

* Login by email/phone/username.
* Logout.
* Password reset.
* Registration disabled.
* Lock screen.
* Remember-me lock flow.
* User status checks: active/inactive/blocked/deleted.
* Duplicate login prevention.
* CSRF refresh for auth AJAX.
* Rich auth logging.
* Presence/session tracking.

User statuses:

```text
active
inactive
blocked
```

Presence statuses:

```text
online
idle
locked
offline
```

Rules:

* Presence is separate from account status.
* Active account status controls whether a user can log in/use the app.
* Presence only describes current session state.
* `presence:mark-stale-offline` is scheduled.
* Active Sessions report is live-only, not history.
* Ended/historical sessions remain stored and must not be deleted just to clean the report.

Seat-limit warning:

* Latest scan found uncommitted files for an online seat limit.
* Verify git status/current code before relying on it.
* If implemented, the seat limit must remain developer-owned config, not DB/admin/UI editable.

---

## 19. Calendar

Page:

```text
/admin/calendar
```

Reference:

```text
FalconTemplate/app/calendar.html
```

Rules:

* Events come from backend JSON, not hardcoded demo frontend events.
* Normal users see/manage only their own events unless a permission contract exists.
* Do not trust submitted `user_id`.
* FullCalendar event objects must match the loaded FullCalendar version.
* Drag/drop and resize must persist through backend endpoints if enabled.
* On drag/drop/resize failure, revert.
* Calendar JS must initialize once.

---

## 20. My Board

Page:

```text
/admin/my-board
```

Reference:

```text
FalconTemplate/app/kanban.html
```

Rules:

* Normal users see/manage only their own tasks/notes.
* Assignment to other users requires permission.
* Board list/column creation/update/delete/reorder requires list permissions.
* Empty columns must keep header and Add button.
* Add buttons must not be rendered inside item loops.
* Drag/drop must persist and respect ownership/permissions.
* Rich HTML must be rendered safely. Do not allow script injection.

---

## 21. Internal Chat

Page:

```text
/admin/chat
```

Reference:

```text
FalconTemplate/app/chat.html
```

Rules:

* Direct user-to-user chat.
* Authenticated HTTP polling only; no sockets/streams unless explicitly redesigned.
* Polling is page-scoped and passive.
* Messages support escaped plain text and emoji text.
* Attachments must be authorized.
* Direct-chat read receipts and topbar notifications exist.

---

## 22. CRUD Generator Reality

Commands:

```bash
php artisan erp:make-crud
php artisan erp:make-lookup-crud
```

Important files:

```text
app/Console/Commands/ErpMakeCrudCommand.php
app/Console/Commands/ErpMakeLookupCrudCommand.php
modules/Core/Services/Generators/*
stubs/erp/crud/*
stubs/erp/lookup-crud/*
docs/ERP_CRUD_GENERATOR_GUIDE.md
tests/Feature/Core/CrudGeneratorTest.php
```

Current reality:

* `erp:make-crud` generates a full CRUD scaffold.
* `erp:make-lookup-crud` generates HR-style lookup resources.
* Generator output is scaffold only, not finished business logic.
* Full CRUD output requires senior review before production use.
* `foreignId` fields require manual public-identifier-backed selectors.
* Generated delete dependency checks are extension points and must be customized.
* Generator should not be used to blindly create large business modules before foundation/scoping is stable.

Known generator gaps from latest scan:

* Full CRUD route/menu insertion is fragile for non-Core modules.
* HR lookup generator route marker appears stale: generator searches for `$lookupRoutes = [` while actual HR route file uses `$lookupResources = [`.
* Generated menu block can miss canonical actions such as `view_trashed` and `restore`.
* Restore conflicts and delete dependency checks are not fully solved automatically.
* Date fields must be aligned with `AppDatePicker`/`DateFormatService`.
* Relation fields must be aligned with `AppSelect2Ajax` and public identifiers.
* Generator docs must distinguish simple ItemLookup CRUDs from rich ERP CRUDs.
* CRUD JS duplication remains a maintainability concern.

---

## 23. Testing Reality

Existing focused test areas include:

* CRUD generator tests.
* Units/Sizes/item lookup tests.
* Companies, Branches, Financial Periods, Product/Core tests.
* Auth tests: login, lock screen, default admin, permission seeder, users, roles, auth reports, presence.
* Accounting accounts CRUD/export/tree tests.
* Finance foundation tests.
* HR lookup/foundation/employees/schema/select2 tests.
* Report/log sanitization tests.

Testing rules:

* Prefer focused tests/commands.
* Do not run broad migrations/tests unless the task requires it.
* Do not apply unrelated pending migrations during a focused bug fix.
* After PHP changes, run `vendor/bin/pint --dirty --format agent` when practical.
* Run `git diff --check` after changes.

Focused command examples:

```bash
php artisan route:list --path=admin/calendar
php artisan route:list --path=admin/my-board
php artisan route:list --path=admin/auth-logs
node --check public/assets/js/modules/Core/calendar.js
node --check public/assets/js/modules/Core/my-board.js
php artisan test --compact --filter=Calendar
php artisan test --compact --filter=MyBoard
php artisan test --compact --filter=ActiveSessions
php artisan test --compact --filter=AuthLog
php artisan test --compact --filter=ActivityLog
php artisan test --compact --filter=Permission
vendor/bin/pint --dirty --format agent
git diff --check
```

---

## 24. Known Problems and Risks

High priority:

* Dirty worktree contained uncommitted online seat-limit files at latest scan.
* Context root/modules were stale before this rewrite.
* Generator route insertion is fragile for non-Core modules.
* HR lookup generator route marker may be stale.
* Generated menu block may miss canonical actions.
* Restore/delete checks remain TODO/default in generated services.
* Date/select2 relation output is not fully project-standard.
* Generator docs lacked exact FalconTemplate mapping before this rewrite.
* HR hidden org-unit permission naming is unclear and should be documented or normalized before refactor.
* Branch and Financial Period delete dependency checks are still a concern unless current code proves fixed.

Medium priority:

* Permissions CRUD UI is not implemented even if hidden permissions exist.
* Company/branch/financial-period operating scope is partial.
* CRUD JS duplication remains a maintainability risk.
* Default admin password may still be predictable unless production-guarded.
* Static vendor assets can drift from package metadata.
* PostgreSQL-specific migrations and raw SQL need careful review.

Testing gaps:

* Tenant/operating scope.
* Delete dependency matrix.
* Document number concurrency.
* Large DataTable performance.
* File upload security.
* Generator output snapshots for real modules.
* Calendar/My Board runtime UI behavior beyond backend tests.

---

## 25. Recommended Next Priorities

1. Finalize or discard/commit the dirty online seat-limit worktree.
2. Fix generator route/menu insertion issues, especially HR lookup marker mismatch.
3. Align generator stubs with Units/Sizes ItemLookup behavior and FalconTemplate mapping.
4. Add generated restore conflict checks for parsed unique fields.
5. Add canonical relation/date recipes for generated fields.
6. Add/verify Branch and Financial Period dependency delete checks.
7. Decide whether Permissions CRUD UI is intentionally absent or should be built.
8. Define company/branch/financial-period operating context before accounting/warehouse/production expansion.
9. Consolidate repeated CRUD JS after current behavior is locked by tests.
10. Add targeted indexes for report-heavy/DataTable-heavy installs after measurement.
