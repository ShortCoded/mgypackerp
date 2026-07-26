<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

test('business numeric inputs render grouped values with decimal input semantics', function () {
    $html = Blade::render(<<<'BLADE'
        <x-forms.numeric-input
            name="quantity"
            value="1250.50000000"
            :scale="8"
            :allow-negative="false"
            min="0.00000001"
            step="0.00000001"
        />
    BLADE);

    expect($html)
        ->toContain('type="text"')
        ->toContain('inputmode="decimal"')
        ->toContain('dir="ltr"')
        ->toContain('value="1,250.5"')
        ->toContain('data-numeric-scale="8"')
        ->toContain('data-numeric-allow-negative="false"')
        ->not->toContain('type="number"');
});

test('numeric view fields preserve zero and display western grouped decimals', function () {
    $grouped = Blade::render(
        '<x-forms.view-field for="amount" :value="$value" numeric />',
        ['value' => '1234567.89000000'],
    );
    $zero = Blade::render(
        '<x-forms.view-field for="amount" :value="$value" dir="ltr" numeric />',
        ['value' => '0.00000000'],
    );

    expect($grouped)
        ->toContain('value="1,234,567.89"')
        ->toContain('dir="ltr"')
        ->and($zero)
        ->toContain('value="0"');
});
