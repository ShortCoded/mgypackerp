<?php

use Symfony\Component\Process\Process;

test('refresh database fails before migrations for persistent database connections', function (string $connection, string $database): void {
    $script = <<<'PHP'
require 'vendor/autoload.php';
$test = new class('databaseSafety') extends Tests\TestCase {
    use Illuminate\Foundation\Testing\RefreshDatabase;
};
try {
    $test->createApplication();
    exit(1);
} catch (RuntimeException $exception) {
    if (!str_starts_with($exception->getMessage(), 'RefreshDatabase tests require SQLite :memory:.')) {
        fwrite(STDERR, $exception->getMessage());
        exit(2);
    }
    echo 'guarded';
}
PHP;
    $process = new Process([PHP_BINARY, '-r', $script], dirname(__DIR__, 2), [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => '/tmp/mgypack-erp-testing-config.php',
        'APP_ROUTES_CACHE' => '/tmp/mgypack-erp-testing-routes.php',
        'DB_CONNECTION' => $connection,
        'DB_DATABASE' => $database,
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'TELESCOPE_ENABLED' => 'false',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe('guarded');
})->with([
    'postgresql' => ['pgsql', 'never_connect_to_this_database'],
    'sqlite file' => ['sqlite', '/tmp/never-create-this-test-database.sqlite'],
]);

test('test caches cannot use the operational cache paths', function (): void {
    $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $name) {
        $setting = $configuration->xpath('/phpunit/php/env[@name="'.$name.'"]')[0];
        expect((string) $setting['force'])->toBe('true')
            ->and((string) $setting['value'])->toStartWith('/tmp/mgypack-erp-testing-');
    }
    expect((string) $configuration->xpath('/phpunit/php/env[@name="APP_MAINTENANCE_DRIVER"]')[0]['value'])->toBe('cache')
        ->and((string) $configuration->xpath('/phpunit/php/env[@name="APP_MAINTENANCE_STORE"]')[0]['value'])->toBe('array');
});
