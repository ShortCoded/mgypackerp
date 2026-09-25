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

test('operational reset refuses apply outside maintenance mode', function (): void {
    $this->artisan('erp:reset-operational-data', ['--apply' => true])
        ->expectsOutputToContain('Put Laravel in maintenance mode')
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
