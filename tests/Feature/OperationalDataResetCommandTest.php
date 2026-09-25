<?php

use App\Console\Commands\VerifyOperationalResetBackupCommand;
use App\Services\OperationalDataResetService;

test('operational reset manifest keeps all core coding and communication tables', function (): void {
    $delete = config('operational_reset.delete_tables');
    $preserve = config('operational_reset.preserve_tables');

    expect(array_intersect($delete, $preserve))->toBe([])
        ->and($delete)->toContain(
            'account_opening_balances',
            'opening_balances',
            'inventory_opening_stocks',
            'inventory_transactions',
            'journal_entries',
            'production_orders',
            'production_runs',
            'sales_orders',
            'purchase_orders',
        )
        ->and($preserve)->toContain(
            'accounts',
            'branches',
            'cost_centers',
            'customers',
            'suppliers',
            'hr_employees',
            'fixed_assets',
            'product_components',
            'production_stages',
            'archive_files',
            'excel_import_batches',
            'excel_import_rows',
            'chat_messages',
            'user_notifications',
        );
});

test('soft-deleted coding cleanup is limited to reviewed dependent links', function (): void {
    $rules = config('operational_reset.deleted_master_links');

    expect(array_keys($rules))->toBe([
        'cost_center_accounts_account_id_foreign',
        'cost_center_accounts_cost_center_id_foreign',
        'fixed_asset_category_mappings_asset_group_account_id_foreign',
        'fixed_assets_cost_center_id_foreign',
        'hr_employees_department_id_foreign',
        'hr_employees_section_id_foreign',
        'my_board_task_comments_user_task_id_foreign',
        'my_board_task_label_user_task_id_foreign',
        'my_board_task_views_user_task_id_foreign',
        'product_components_product_id_foreign',
        'product_components_component_product_id_foreign',
        'role_has_permissions_role_id_foreign',
        'user_task_assignees_user_task_id_foreign',
    ]);
    expect($rules['fixed_assets_cost_center_id_foreign']['action'])->toBe('null_reference')
        ->and($rules['fixed_assets_cost_center_id_foreign']['column'])->toBe('cost_center_id')
        ->and($rules['hr_employees_department_id_foreign']['action'])->toBe('null_reference')
        ->and($rules['hr_employees_section_id_foreign']['action'])->toBe('null_reference')
        ->and($rules['my_board_task_comments_user_task_id_foreign']['action'])->toBe('delete_child')
        ->and($rules['my_board_task_label_user_task_id_foreign']['action'])->toBe('delete_child')
        ->and($rules['my_board_task_views_user_task_id_foreign']['action'])->toBe('delete_child')
        ->and($rules['product_components_product_id_foreign']['action'])->toBe('delete_child')
        ->and($rules['product_components_component_product_id_foreign']['action'])->toBe('delete_child')
        ->and($rules['user_task_assignees_user_task_id_foreign']['action'])->toBe('delete_child');
});

test('operational reset refuses apply outside maintenance mode', function (): void {
    $this->artisan('erp:reset-operational-data', ['--apply' => true])
        ->expectsOutputToContain('Put Laravel in maintenance mode')
        ->assertExitCode(1);
});

test('one-command reset refuses an unsafe maintenance driver before touching data', function (): void {
    config(['app.maintenance.driver' => 'cache']);

    $this->artisan('erp:reset-operational-data-now')
        ->expectsOutputToContain('requires file-based Laravel maintenance mode')
        ->assertExitCode(1);
});

test('service refuses a direct commit without the backup and write gate', function (): void {
    expect(fn () => app(OperationalDataResetService::class)
        ->run(str_repeat('0', 64), true))
        ->toThrow(RuntimeException::class, 'requires maintenance mode');
});

test('restored backup proof signature changes when its contents change', function (): void {
    $proof = [
        'source_database' => 'source_db',
        'review_token' => str_repeat('a', 64),
        'backup_sha256' => str_repeat('b', 64),
    ];
    $signature = VerifyOperationalResetBackupCommand::proofSignature($proof);
    $proof['review_token'] = str_repeat('c', 64);

    expect($signature)->not->toBe(VerifyOperationalResetBackupCommand::proofSignature($proof));
});

test('backup proof accepts absolute paths on Linux and Windows', function (): void {
    $command = new VerifyOperationalResetBackupCommand;
    $pathCheck = new ReflectionMethod($command, 'isAbsolutePath');

    expect($pathCheck->invoke($command, '/var/backups/proof.json'))->toBeTrue()
        ->and($pathCheck->invoke($command, 'D:\\apps\\ERP\\storage\\proof.json'))->toBeTrue()
        ->and($pathCheck->invoke($command, 'D:/apps/ERP/storage/proof.json'))->toBeTrue()
        ->and($pathCheck->invoke($command, 'storage/proof.json'))->toBeFalse();
});
