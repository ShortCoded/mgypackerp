<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Modules\Core\Services\AssetVersionService;
use Modules\Core\Services\RequestMemo;

beforeEach(function (): void {
    app()->instance('request', Request::create('/'));
});

test('an explicit deployment version is shared by every asset without per-file discovery', function (): void {
    config()->set('app.asset_version', 'release 2026.09.06');
    $assets = new AssetVersionService(new RequestMemo, app('config'));

    expect($assets->url('assets/does-not-need-to-exist.js'))
        ->toEndWith('/assets/does-not-need-to-exist.js?v=release%202026.09.06')
        ->and($assets->url('vendors/also-not-read.css'))
        ->toEndWith('/vendors/also-not-read.css?v=release%202026.09.06');
});

test('an absent release version uses the requested asset metadata even in an optimized deployment', function (): void {
    config()->set('app.asset_version', null);
    $path = 'assets/js/theme.js';
    $stat = stat(public_path($path));
    $expectedVersion = implode('-', [
        (string) $stat['mtime'],
        (string) $stat['ctime'],
        (string) $stat['size'],
        (string) $stat['ino'],
    ]);
    $assets = new AssetVersionService(new RequestMemo, app('config'));

    expect($assets->url($path))
        ->toEndWith('/'.$path.'?v='.$expectedVersion);
});

test('uncached development keeps per-file metadata cache busting', function (): void {
    config()->set('app.asset_version', null);
    $assets = new AssetVersionService(new RequestMemo, app('config'));
    $path = 'assets/js/theme.js';
    $stat = stat(public_path($path));
    $expectedVersion = implode('-', [
        (string) $stat['mtime'],
        (string) $stat['ctime'],
        (string) $stat['size'],
        (string) $stat['ino'],
    ]);

    expect($assets->url($path))
        ->toEndWith('/'.$path.'?v='.$expectedVersion);
});

test('a missing asset stays unversioned when there is no release identifier', function (): void {
    config()->set('app.asset_version', null);
    $assets = new AssetVersionService(new RequestMemo, app('config'));
    $path = 'assets/does-not-exist.js';

    expect($assets->url($path))
        ->toEndWith('/'.$path)
        ->not->toContain('?v=');
});

test('Blade asset URLs never call filemtime directly', function (): void {
    $violations = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'php')
        ->filter(fn (SplFileInfo $file): bool => preg_match('/\bfilemtime\s*\(/', File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($violations)->toBeEmpty();
});

test('dynamic Blade asset paths remain delegated to the asset version service', function (): void {
    $calendar = File::get(resource_path('views/modules/core/calendar/index.blade.php'));
    $myBoard = File::get(resource_path('views/modules/core/my-board/index.blade.php'));

    expect($calendar)
        ->toContain('->url($calendarCssPath)')
        ->toContain('->url($calendarJsPath)')
        ->and($myBoard)
        ->toContain('->url($myBoardJsPath)');
});
