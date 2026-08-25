<?php

use App\Console\Commands\ErpDemoDataCommand;
use Illuminate\Support\Facades\DB;

test('client demo command is registered with an isolated read-only verification mode', function (): void {
    expect(collect(Artisan::all())->keys())->toContain('erp:client-demo-data');

    $before = DB::table('companies')->count();

    $this->artisan('erp:client-demo-data', ['--verify' => true])
        ->expectsOutputToContain('[FAIL] active company exists')
        ->assertFailed();

    expect(DB::table('companies')->count())->toBe($before);
});

test('client demo command refuses unsafe environments', function (): void {
    $originalEnvironment = app()->environment();

    try {
        app()->detectEnvironment(fn (): string => 'production');

        $this->artisan('erp:client-demo-data')
            ->expectsOutputToContain('disabled outside local, development, and testing')
            ->assertFailed();
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
});

test('client demo command exposes only the approved environment allow-list', function (): void {
    expect(app(ErpDemoDataCommand::class)->allowedEnvironments())->toBe(['local', 'development', 'testing']);
});
