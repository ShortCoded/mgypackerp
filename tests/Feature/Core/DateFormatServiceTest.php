<?php

use Modules\Core\Services\DateFormatService;

test('date format service parses configured day month datetime without swapping month and day', function () {
    $dates = app(DateFormatService::class);

    expect($dates->dateFormat())->toBe('d/m/Y')
        ->and($dates->dateTimeFormat())->toBe('d/m/Y h:i A')
        ->and($dates->normalizeDateTimeForStorage('10/05/2026 3:00 AM'))->toBe('2026-05-10 03:00:00')
        ->and($dates->normalizeDateTimeForStorage('05/10/2026 3:00 AM'))->toBe('2026-10-05 03:00:00')
        ->and($dates->normalizeForStorage('10/05/2026'))->toBe('2026-05-10');
});

test('date format service rejects invalid date values instead of guessing', function () {
    $dates = app(DateFormatService::class);

    expect($dates->parseDate('31/02/2026'))->toBeNull()
        ->and($dates->parseDateTime('31/02/2026 3:00 AM'))->toBeNull()
        ->and($dates->normalizeForStorage('not-a-date'))->toBeNull()
        ->and($dates->normalizeDateTimeForStorage('not-a-date'))->toBeNull();
});
