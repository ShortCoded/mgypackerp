# Permission Registry Auditing

## Architecture: three discovery sources

`PermissionRegistryService::all()` builds the canonical permission list from three sources. Know which source covers which kind of permission before adding anything.

| Source | What it scans | Typical permission shape | Where to add |
| --- | --- | --- | --- |
| `fromMenus()` | `config/menu/*.php` files | `actions` key-value pairs, `permission` strings, `permissions` arrays | Add to existing menu item's `actions`, or add a new menu item |
| `ErpUiScreenRegistry::permissions()` | `config/erp_ui_screens/*.php` files | Each screen has `permission_prefix` + `actions` list; full permission = `prefix.action` | Add a hidden screen (`shell_enabled => false, menu_visible => false`) with proper `permission_prefix` and `actions` |
| `ErpUiScreenRegistry::legacyPlaceholderPermissions()` | Placeholder alias config (`erp_expanded_screens.screens`) | Placeholder screens that redirect to canonical screens | Only for placeholder/redirect scenarios |

### ERP UI screen `$screen()` helper signature

The helper in each `config/erp_ui_screens/*.php` file takes:
```php
$screen = static fn (string $slug, string $en, string $ar, string $group = 'analysis', string $profile = 'document', array $extra = []): array => [
    'key' => '...',
    ...
    ...$extra,
];
```

The 5th positional argument is `profile` (string), NOT an array. A common pitfall: passing an options array as the 5th argument produces a `TypeError` at runtime because `profile` must be a string. Always pass `profile` as a string and put options in the 6th `extra` argument:

```php
// WRONG — TypeError: profile must be string
$screen('cost-closing', 'Cost Closing', 'إقفال التكاليف', 'closing', ['shell_enabled' => false])

// RIGHT — profile is a string, options in extra
$screen('cost-closing', 'Cost Closing', 'إقفال التكاليف', 'closing', 'closing', ['shell_enabled' => false, 'menu_visible' => false])
```

### Actions format in ERP UI screens

The `actions` array uses **plain string action names** as values. The `ErpUiScreenDefinition::permission($action)` method builds the full permission as `permission_prefix . '.' . action`.

```php
// CORRECT — plain string actions
'actions' => ['view', 'create', 'edit', 'update', 'delete', 'clone', 'bulk_delete'],

// WRONG (produces wrong permission names) — key-value with action names as keys
'actions' => [
    'view' => 'view',
    'create' => 'create',
],
```

The `actions()` method returns `array_values()` of the filtered actions array, so the keys are discarded. Only the values matter. Using key-value pairs where both key and value are the same produces the correct permission, but using descriptive keys that differ from values produces wrong permissions (the key becomes the action passed to `permission()`, not the value).

## Scanning for missing permissions

### Step 1: Get the canonical list

```bash
php artisan tinker --execute '
$registry = app(Modules\Auth\Services\PermissionRegistryService::class);
$all = $registry->all();
sort($all);
file_put_contents("/tmp/canonical_perms.txt", implode("\n", $all) . "\n");
echo "Wrote " . count($all) . " permissions\n";
'
```

### Step 2: Scan application code for permission references

Scan these patterns across `modules/`, `app/`, `resources/`, `routes/`, `bootstrap/`:

**Blade templates** (`@can`, `@cannot`, `@canany`):
```python
bp1 = re.compile(r"""@can\s*\(\s*['"]([a-z0-9][a-z0-9._-]+)['"]""", re.IGNORECASE)
bp2 = re.compile(r"""@cannot\s*\(\s*['"]([a-z0-9][a-z0-9._-]+)['"]""", re.IGNORECASE)
bp3 = re.compile(r"""@canany\s*\(\s*\[(.*?)\]""", re.IGNORECASE)
```

**PHP files** (`Gate::`, `can()`, `cannot()`, `authorize()`, `permission =>`):
```python
php_p2 = re.compile(r"""Gate::(?:allows|denies|check|authorize|define)\s*\(\s*['"]([a-z0-9][a-z0-9._-]+)['"]""")
php_p3 = re.compile(r"""(?:can|cannot|authorize)\s*\(\s*['"]([a-z0-9][a-z0-9._-]+)['"]""")
```

### Step 3: Categorize the missing permissions

After computing `missing = referenced - canonical`, filter into categories:

| Category | Filter | Action |
| --- | --- | --- |
| **Genuinely missing** | Has dots, not `admin.*`, not empty suffix, not in canonical | Add to canonical source |
| **Legacy map handled** | Present as key in `legacyPermissionMap()` | Already resolves to a canonical permission — skip |
| **Framework / internal** | Prefixes like `auth.`, `cache.`, `queue.`, `mail.`, `translation.`, `validation.`, `hash.`, `keys.`, `payload.`, `migration.`, `redis.`, `memcached.`, `command.`, `queue.` | These are Laravel/framework internals, not user permissions — skip |
| **Empty suffix** | Ends with `.` (e.g. `reports.costing.`, `quotations.`) | Corrupted reference — fix the referencing code |
| **Already in canonical** | Present in canonical list | No action needed |

### Step 4: Add missing permissions to the correct canonical source

**For menu-driven permissions** (`config/menu/*.php`): add to the `actions` array of the relevant menu item:

```php
# config/menu/core.php — dashboard section
'actions' => [
    'view' => 'dashboard.view',
    'sales_summary' => 'dashboard.summaries.sales.view',    // ADD
    'purchases_summary' => 'dashboard.summaries.purchases.view',  // ADD
],
```

**For ERP UI screen permissions** (`config/erp_ui_screens/*.php`): add a hidden screen definition:

```php
# config/erp_ui_screens/core.php
[
    'key' => 'core_quick_tasks',
    'slug' => 'quick-tasks',
    'title' => ['en' => 'Quick Tasks', 'ar' => 'المهام السريعة'],
    'group' => 'configuration',
    'profile' => 'setup',
    'classification' => 'OUT_OF_SCOPE',
    'shell_enabled' => false,
    'menu_visible' => false,
    'permission_prefix' => 'quick_tasks',
    'actions' => ['view', 'create', 'update', 'delete', 'restore', 'change_status', 'bulk_delete'],
],
```

Key rules for hidden screen definitions:
- `classification => 'OUT_OF_SCOPE'` — keeps it out of navigation
- `shell_enabled => false` — disables ERP UI Shell routing
- `menu_visible => false` — removes from menu
- `permission_prefix` + `actions` — the only fields that matter for permission discovery

### Step 5: Run the sync

```bash
# Dry-run first to preview
php artisan erp:permissions:sync --dry-run --show-created

# Real execution
php artisan erp:permissions:sync --show-created
```

Key flags:
- `--dry-run` — preview without writing
- `--show-created` — list missing permissions that would be created
- `--prune` — delete stale permissions not in canonical (use with `--force` in production)
- `--admin-role=<id|name>` — specify admin role (default resolves from config or id 1 / name 'admin')
- `--skip-admin-sync` — skip assigning permissions to admin role

### Step 6: Verify

```bash
php artisan tinker --execute '
$permissionModel = config("permission.models.permission");
$roleModel = config("permission.models.role");

# Check DB presence
$checks = ["dashboard.summaries.sales.view", ...];
$inDb = 0;
foreach ($checks as $p) {
    if ($permissionModel::where("name", $p)->where("guard_name", "web")->exists()) $inDb++;
}
echo "In DB: $inDb / " . count($checks) . "\n";

# Check admin role
$admin = $roleModel::where("name", "admin")->where("guard_name", "web")->first();
$adminPerms = $admin->permissions()->pluck("name")->sort()->values();
$onAdmin = 0;
foreach ($checks as $p) {
    if (in_array($p, $adminPerms->all())) $onAdmin++;
}
echo "On admin: $onAdmin / " . count($checks) . "\n";
echo "Admin total: " . $adminPerms->count() . "\n";

// Clear cache
app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
'
```

### Step 7: Regression test

Add a test that scans known permission references and asserts they all exist in the canonical registry. When a permission referenced by application code is missing from the registry, the test fails with the list of missing permissions.

See `templates/permission-registry-coverage-test.php` for a starter template.

## Common pitfalls

- **Scanning includes vendor directories.** Only scan `modules/`, `app/`, `resources/`, `routes/`, `bootstrap/`. Vendor directories contain framework code with permission-like strings that are not user permissions.
- **Using key-value action pairs in ERP UI screens.** The `actions()` method returns `array_values()` of the actions array, so only the values matter. Using `['view' => 'something_else']` means the action passed to `permission()` is `'view'`, not `'something_else'`. Always use plain string action names that match the desired permission suffix.
- **Passing an array as the `$profile` argument to `$screen()`.** The 5th positional argument is `$profile` (string). Options must go in the 6th `$extra` argument. Passing an array as the 5th argument causes a `TypeError`.
- **Assuming a referenced permission is missing when it's handled by the legacy map.** `PermissionRegistryService::legacyPermissionMap()` maps old permission names to new ones (e.g. `roles.company_access.manage` → `roles.operating_scope.manage`). A permission referenced under its old name is not genuinely missing if the legacy map covers it.
- **Not clearing the Spatie permission cache after sync.** The `erp:permissions:sync` command clears the cache internally, but if permissions are checked before the cache is refreshed (e.g. in the same process that ran sync), stale results may appear. Always call `forgetCachedPermissions()` after manual permission changes.
- **Adding permissions to the wrong config file.** A permission for quick tasks should go in `config/erp_ui_screens/core.php`, not in a purchase or sales screen file. Match the module to the correct screen config file.

## Files to check when auditing permissions

- `modules/Auth/Services/PermissionRegistryService.php` — canonical registry, all 3 discovery sources, legacy map
- `modules/Core/Services/ErpUi/ErpUiScreenRegistry.php` — ERP UI screen permission discovery
- `modules/Core/Services/ErpUi/ErpUiScreenDefinition.php` — how individual screens build permission names
- `app/Console/Commands/SyncErpPermissionsCommand.php` — sync command, flags, admin role resolution
- `config/menu/*.php` — menu-driven permissions (actions, permission, permissions fields)
- `config/erp_ui_screens/*.php` — ERP UI screen permissions (permission_prefix + actions)
