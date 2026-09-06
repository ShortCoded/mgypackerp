<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Core\Services\DataTableSearchService;

test('complete date terms compile to sargable half-open ranges', function (): void {
    $query = DB::table('users')->select('users.id');

    app(DataTableSearchService::class)->applyMultiTermSearch(
        $query,
        ['06/09/2026'],
        [
            'text' => ['users.name'],
            'dates' => ['users.created_at'],
            'date_text' => ['users.created_at'],
        ],
    );

    expect($query->toSql())
        ->toContain('LOWER(CAST("users"."name" AS TEXT)) LIKE LOWER(?)')
        ->toContain('"users"."created_at" >= ?')
        ->toContain('"users"."created_at" < ?')
        ->not->toContain('strftime(')
        ->not->toContain('to_char(')
        ->not->toContain('::date')
        ->and($query->getBindings())->toBe([
            '%06/09/2026%',
            '2026-09-06',
            '2026-09-07',
        ]);
});

test('complete date terms keep PostgreSQL date columns unwrapped', function (): void {
    $query = DB::connection('pgsql')->table('users')->select('users.id');

    app(DataTableSearchService::class)->applyMultiTermSearch(
        $query,
        ['2026-09-06'],
        [
            'dates' => ['users.created_at'],
            'date_text' => ['users.created_at'],
        ],
    );

    expect($query->toSql())
        ->toContain('"users"."created_at" >= ?')
        ->toContain('"users"."created_at" < ?')
        ->not->toContain('to_char(')
        ->not->toContain('::date')
        ->and($query->getBindings())->toBe([
            '2026-09-06',
            '2026-09-07',
        ]);
});

test('complete date ranges match values stored in date-only columns', function (): void {
    $matchingPeriodId = DB::table('financial_periods')->insertGetId([
        'name' => 'Exact date search match',
        'from_date' => '2026-09-06',
        'to_date' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('financial_periods')->insert([
        'name' => 'Exact date search non-match',
        'from_date' => '2026-09-07',
        'to_date' => '2026-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $query = DB::table('financial_periods')->select('financial_periods.id');

    app(DataTableSearchService::class)->applyMultiTermSearch(
        $query,
        ['06/09/2026'],
        [
            'dates' => ['financial_periods.from_date'],
            'date_text' => ['financial_periods.from_date'],
        ],
    );

    expect($query->pluck('financial_periods.id')->all())->toBe([$matchingPeriodId]);
});

test('partial date text keeps the existing formatted contains search', function (): void {
    $query = DB::table('users')->select('users.id');

    app(DataTableSearchService::class)->applyMultiTermSearch(
        $query,
        ['06/09'],
        [
            'text' => ['users.name'],
            'dates' => ['users.created_at'],
            'date_text' => ['users.created_at'],
        ],
    );

    expect($query->toSql())
        ->toContain('LOWER(CAST("users"."name" AS TEXT)) LIKE LOWER(?)')
        ->toContain("strftime('%d/%m/%Y %H:%M', \"users\".\"created_at\") LIKE ?")
        ->toContain("strftime('%Y-%m-%d %H:%M', \"users\".\"created_at\") LIKE ?")
        ->not->toContain('"users"."created_at" >= ?')
        ->not->toContain('"users"."created_at" < ?')
        ->and($query->getBindings())->toBe([
            '%06/09%',
            '%06/09%',
            '%06/09%',
        ]);
});

test('complete date ranges include the whole day and exclude both boundaries outside it', function (): void {
    $previousDay = User::factory()->create(['created_at' => '2026-09-05 23:59:59']);
    $startOfDay = User::factory()->create(['created_at' => '2026-09-06 00:00:00']);
    $endOfDay = User::factory()->create(['created_at' => '2026-09-06 23:59:59']);
    $nextDay = User::factory()->create(['created_at' => '2026-09-07 00:00:00']);

    $query = User::query()->select('users.id');

    app(DataTableSearchService::class)->applyMultiTermSearch(
        $query,
        ['06/09/2026'],
        [
            'dates' => ['users.created_at'],
            'date_text' => ['users.created_at'],
        ],
    );

    expect($query->orderBy('users.id')->pluck('users.id')->all())
        ->toBe([$startOfDay->getKey(), $endOfDay->getKey()])
        ->not->toContain($previousDay->getKey(), $nextDay->getKey());
});

test('partial date text still matches formatted date values', function (): void {
    $matchingUser = User::factory()->create(['created_at' => '2026-09-06 12:30:00']);
    User::factory()->create(['created_at' => '2026-10-06 12:30:00']);

    $query = User::query()->select('users.id');

    app(DataTableSearchService::class)->applyMultiTermSearch(
        $query,
        ['06/09'],
        [
            'dates' => ['users.created_at'],
            'date_text' => ['users.created_at'],
        ],
    );

    expect($query->pluck('users.id')->all())->toBe([$matchingUser->getKey()]);
});

test('date parsing accepts only complete valid supported formats', function (string $term, ?string $expected): void {
    expect(app(DataTableSearchService::class)->parseDateTerm($term)?->toDateString())->toBe($expected);
})->with([
    'slash format' => ['06/09/2026', '2026-09-06'],
    'dash day-first format' => ['06-09-2026', '2026-09-06'],
    'ISO format' => ['2026-09-06', '2026-09-06'],
    'partial date' => ['06/09', null],
    'date with time' => ['06/09/2026 10:30', null],
    'invalid date' => ['31/02/2026', null],
]);
