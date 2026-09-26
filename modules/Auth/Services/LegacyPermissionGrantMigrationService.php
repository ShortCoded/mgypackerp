<?php

namespace Modules\Auth\Services;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class LegacyPermissionGrantMigrationService
{
    private const MigrationKey = 'permissions.legacy_menu_grants_migrated_2026_09_26';

    public function __construct(private readonly PermissionRegistryService $registry) {}

    /**
     * Copy existing role and direct user grants to their replacement screens once.
     * Later permission syncs must respect a revoked replacement permission.
     *
     * @param  list<string>  $permissionNames
     */
    public function migrate(array $permissionNames, string $guardName = 'web'): int
    {
        return DB::transaction(function () use ($permissionNames, $guardName): int {
            $now = now();
            DB::table('settings')->insertOrIgnore([
                'key' => self::MigrationKey,
                'value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $marker = DB::table('settings')
                ->where('key', self::MigrationKey)
                ->lockForUpdate()
                ->first(['value']);

            if ($marker?->value === 'completed') {
                return 0;
            }

            $validNames = array_fill_keys($permissionNames, true);
            $map = $this->migrationMap($permissionNames);
            $legacyPermissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', array_keys($map))
                ->get(['id', 'name']);
            $targetPermissions = Permission::query()
                ->where('guard_name', $guardName)
                ->whereIn('name', $permissionNames)
                ->pluck('id', 'name');
            $pivotKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';
            $inserted = 0;

            foreach ($legacyPermissions as $legacyPermission) {
                $targetIds = collect($map[$legacyPermission->name] ?? [])
                    ->filter(static fn (string $name): bool => isset($validNames[$name]))
                    ->map(static fn (string $name): mixed => $targetPermissions->get($name))
                    ->filter()
                    ->unique()
                    ->values();

                foreach (['role_has_permissions', 'model_has_permissions'] as $pivotName) {
                    $table = config("permission.table_names.{$pivotName}") ?: $pivotName;
                    $grants = DB::table($table)->where($pivotKey, $legacyPermission->id)->get();

                    foreach ($targetIds as $targetId) {
                        foreach ($grants->chunk(500) as $batch) {
                            $rows = $batch->map(static function (object $grant) use ($pivotKey, $targetId): array {
                                $row = (array) $grant;
                                $row[$pivotKey] = $targetId;

                                return $row;
                            })->all();
                            $inserted += DB::table($table)->insertOrIgnore($rows);
                        }
                    }

                    if (! isset($validNames[$legacyPermission->name])) {
                        DB::table($table)->where($pivotKey, $legacyPermission->id)->delete();
                    }
                }
            }

            DB::table('settings')->where('key', self::MigrationKey)->update([
                'value' => 'completed',
                'updated_at' => now(),
            ]);

            return $inserted;
        });
    }

    /**
     * @param  list<string>  $permissionNames
     * @return array<string, list<string>>
     */
    private function migrationMap(array $permissionNames): array
    {
        $reportNames = static fn (string $prefix, string $action): array => array_values(array_filter(
            $permissionNames,
            static fn (string $name): bool => str_starts_with($name, $prefix.'.')
                && substr_count(substr($name, strlen($prefix) + 1), '.') === 1
                && str_ends_with($name, '.'.$action),
        ));
        $inventoryNames = static fn (array $reports, array $actions): array => array_values(array_filter(
            $permissionNames,
            static function (string $name) use ($reports, $actions): bool {
                foreach ($reports as $report) {
                    foreach ($actions as $action) {
                        if ($name === "inventory.reports.{$report}.{$action}") {
                            return true;
                        }
                    }
                }

                return false;
            },
        ));

        $map = [
            'inventory.reports.operational' => $inventoryNames(['stock_balances', 'operations', 'sales_valuation'], ['view']),
            'inventory.reports.financial' => $inventoryNames(['valuation'], ['view']),
            'inventory.reports.export' => $inventoryNames(['stock_balances', 'operations', 'valuation', 'sales_valuation'], ['export', 'print']),
            'production.reports.operational' => $reportNames('production.reports', 'view'),
            'production.reports.export' => array_merge($reportNames('production.reports', 'export'), $reportNames('production.reports', 'print')),
            'reports.purchases.view' => array_merge($reportNames('reports.purchases', 'view'), ['reports.supplier_statement.view']),
            'reports.purchases.export' => array_merge($reportNames('reports.purchases', 'export'), $reportNames('reports.purchases', 'print'), ['reports.supplier_statement.export']),
            'reports.sales.sales_orders.view' => $reportNames('reports.sales', 'view'),
            'reports.sales.sales_orders.export' => $reportNames('reports.sales', 'export'),
            'reports.sales.sales_orders.print' => $reportNames('reports.sales', 'print'),
            'reports.account_ledger.view' => ['reports.general_journal.view', 'reports.reconciliation_center.view'],
            'reports.account_ledger.export' => ['reports.general_journal.export', 'reports.reconciliation_center.export'],
            'hr.employee_attendance.view' => ['hr.attendance_report.view'],
            'hr.employee_attendance.export' => ['hr.attendance_report.export'],
            'production.quality.view' => ['production.quality.reports.view'],
            'production.quality.export' => ['production.quality.reports.export'],
            'production.quality.print' => ['production.quality.reports.print'],
            'customers.view' => ['customer_terms.view'],
            'customers.edit' => ['customer_terms.edit'],
            'financial_periods.view' => ['financial_periods.closing.view'],
            'fixed_assets.view' => ['fixed_assets.movements.view'],
        ];

        foreach ($this->registry->legacyPermissionMap() as $oldName => $newName) {
            $map[$oldName] = [$newName];
        }

        return $map;
    }
}
