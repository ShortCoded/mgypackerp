<?php

use App\Console\Commands\ResetLocalDatabaseCommand;
use Database\Seeders\EmergencyRecoverySeeder;
use Database\Seeders\RuntimeDemoDataSeeder;
use Modules\Auth\Database\Seeders\PermissionSeeder;

function withResetCommandEnvironment(string $environment, callable $callback): mixed
{
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn (): string => $environment);

        return $callback();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
}

test('reset local database command refuses production', function () {
    withResetCommandEnvironment('production', function (): void {
        $this->artisan('erp:reset-local-db', ['--force' => true])
            ->expectsOutputToContain('Refusing to reset the database in production.')
            ->assertFailed();
    });
});

test('reset local database command refuses unsafe environments', function () {
    withResetCommandEnvironment('staging', function (): void {
        $this->artisan('erp:reset-local-db', ['--force' => true])
            ->expectsOutputToContain('Refusing to reset the database in APP_ENV [staging].')
            ->assertFailed();
    });
});

test('reset local database command refuses empty and production-like database names', function (?string $databaseName, string $expectedOutput) {
    config(['database.connections.sqlite.database' => $databaseName]);

    $this->artisan('erp:reset-local-db', ['--force' => true])
        ->expectsOutputToContain($expectedOutput)
        ->assertFailed();
})->with([
    'empty database' => [null, 'configured database name is empty'],
    'production database' => ['erp_production', 'looks production-like'],
    'live database' => ['erp_live_copy', 'looks production-like'],
    'prod database' => ['erp_prod', 'looks production-like'],
    'client database' => ['real_client_db', 'looks production-like'],
]);

test('reset local database command accepts local development and testing environments in its guard', function () {
    $command = app(ResetLocalDatabaseCommand::class);

    expect($command->isAllowedEnvironment('local'))->toBeTrue()
        ->and($command->isAllowedEnvironment('development'))->toBeTrue()
        ->and($command->isAllowedEnvironment('testing'))->toBeTrue()
        ->and($command->isAllowedEnvironment('staging'))->toBeFalse();
});

test('reset local database command requires confirmation before deleting data', function () {
    $this->artisan('erp:reset-local-db')
        ->expectsOutputToContain('DESTRUCTIVE LOCAL DATABASE RESET')
        ->expectsOutputToContain('All database data will be deleted before the ERP is reseeded.')
        ->expectsConfirmation('Do you understand that all database data will be deleted?', 'no')
        ->expectsOutputToContain('Database reset cancelled.')
        ->assertFailed();
});

test('reset local database command seeds baseline and optional demo in the expected order', function () {
    $command = app(ResetLocalDatabaseCommand::class);

    expect($command->seederClasses(false))->toBe([
        PermissionSeeder::class,
        EmergencyRecoverySeeder::class,
    ])->and($command->seederClasses(true))->toBe([
        PermissionSeeder::class,
        EmergencyRecoverySeeder::class,
        RuntimeDemoDataSeeder::class,
    ]);
});
