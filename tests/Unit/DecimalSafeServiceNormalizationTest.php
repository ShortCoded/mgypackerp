<?php

use Modules\Finance\Services\FinanceAmountService;

test('finance amount normalization preserves accepted maximum decimal precision', function (): void {
    $amounts = app(FinanceAmountService::class);

    expect($amounts->normalize('99999999999999.9999', 4))->toBe('99999999999999.9999')
        ->and($amounts->normalize('999999999999.999999', 6))->toBe('999999999999.999999')
        ->and($amounts->normalize('2.5', 4))->toBe('2.5000')
        ->and($amounts->normalize('-0.0000', 4))->toBe('0.0000');
});
