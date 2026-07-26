<?php

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\NumericFormatService;

test('it formats business decimals without losing meaningful precision', function (mixed $input, string $expected) {
    expect(app(NumericFormatService::class)->format($input))->toBe($expected);
})->with([
    'fraction' => ['2.50000000', '2.5'],
    'whole decimal' => ['2.00000000', '2'],
    'grouped integer' => ['1000', '1,000'],
    'grouped fraction' => ['1250.50000000', '1,250.5'],
    'large number' => ['1234567.89000000', '1,234,567.89'],
    'small fraction' => ['0.05000000', '0.05'],
    'eight-place value' => ['0.00045820', '0.0004582'],
    'negative value' => ['-1250.50000000', '-1,250.5'],
    'zero' => ['0.00000000', '0'],
    'negative zero' => ['-0.0000', '0'],
    'maximum precision string' => ['99999999999999.9999', '99,999,999,999,999.9999'],
    'small scientific float' => [1.0E-7, '0.0000001'],
    'large scientific float' => [1.0E+21, '1,000,000,000,000,000,000,000'],
]);

test('it preserves empty values separately from zero', function () {
    $formatter = app(NumericFormatService::class);

    expect($formatter->format(null))->toBe('')
        ->and($formatter->format(''))->toBe('')
        ->and($formatter->format('0'))->toBe('0')
        ->and($formatter->normalize(null))->toBeNull()
        ->and($formatter->normalize(''))->toBeNull()
        ->and($formatter->normalize('0'))->toBe('0');
});

test('it normalizes valid grouped input to a canonical decimal string', function (mixed $input, ?string $expected) {
    expect(app(NumericFormatService::class)->normalize($input))->toBe($expected);
})->with([
    'ungrouped' => ['1250.5', '1250.5'],
    'grouped' => ['1,250.5000', '1250.5'],
    'leading decimal point' => ['.05', '0.05'],
    'negative leading decimal point' => ['-.0500', '-0.05'],
    'leading zeros' => ['0001250.500', '1250.5'],
    'empty' => ['', null],
]);

test('it rejects malformed or non-decimal input', function (mixed $input) {
    expect(fn () => app(NumericFormatService::class)->normalize($input))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'bad grouping' => ['1,2,3'],
    'short final group' => ['12,34'],
    'leading grouped zero' => ['01,000'],
    'scientific notation string' => ['1e3'],
    'multiple decimal points' => ['1.2.3'],
    'identifier-like text' => ['INV-0001'],
]);

test('validation normalization leaves malformed values untouched for Laravel validation', function () {
    $formatter = app(NumericFormatService::class);

    expect($formatter->normalizeForValidation('1,250.5000'))->toBe('1250.5')
        ->and($formatter->normalizeForValidation('1,2,3'))->toBe('1,2,3')
        ->and($formatter->normalizeForValidation(''))->toBe('');
});

test('it compares numeric values without float equality', function () {
    $formatter = app(NumericFormatService::class);

    expect($formatter->equivalent('2.5', '2.50000000'))->toBeTrue()
        ->and($formatter->equivalent('2,500', '2500.000'))->toBeTrue()
        ->and($formatter->equivalent('0.0004582', '0.00045820'))->toBeTrue()
        ->and($formatter->equivalent('2.5', '2.5001'))->toBeFalse();
});

test('it pads canonical decimals to a storage scale without rounding', function () {
    $formatter = app(NumericFormatService::class);

    expect($formatter->normalizeToScale('2.5', 4))->toBe('2.5000')
        ->and($formatter->normalizeToScale('0.0004582', 8))->toBe('0.00045820')
        ->and($formatter->normalizeToScale(null, 8))->toBeNull()
        ->and(fn () => $formatter->normalizeToScale('1.23456', 4))
        ->toThrow(InvalidArgumentException::class);
});

test('it exposes an Excel number format matching the real field scale', function () {
    $formatter = app(NumericFormatService::class);

    expect($formatter->excelNumberFormat(0))->toBe('#,##0')
        ->and($formatter->excelNumberFormat(4))->toBe('#,##0.####')
        ->and($formatter->excelNumberFormat(8))->toBe('#,##0.########');
});

test('request normalization supports form and JSON input including nested wildcard paths', function () {
    $formRequest = new class extends FormRequest
    {
        use NormalizesNumericInput;

        public function normalizeTestValues(): void
        {
            $this->normalizeNumericInput(['amount', 'lines.*.quantity']);
        }
    };
    $formRequest->initialize([], [
        'amount' => '1,250.5000',
        'lines' => [
            ['quantity' => '0.00045820'],
            ['quantity' => '1,2,3'],
        ],
    ], server: ['REQUEST_METHOD' => 'POST']);
    $formRequest->setMethod('POST');
    $formRequest->setContainer(app());
    $formRequest->normalizeTestValues();

    expect($formRequest->input('amount'))->toBe('1250.5')
        ->and($formRequest->input('lines.0.quantity'))->toBe('0.0004582')
        ->and($formRequest->input('lines.1.quantity'))->toBe('1,2,3');

    $jsonRequest = new class([], [], [], [], [], ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json'], json_encode(['amount' => '2,500.5000', 'lines' => [['quantity' => '-0.0000']]], JSON_THROW_ON_ERROR)) extends FormRequest
    {
        use NormalizesNumericInput;

        public function normalizeTestValues(): void
        {
            $this->normalizeNumericInput(['amount', 'lines.*.quantity']);
        }
    };
    $jsonRequest->setContainer(app());
    $jsonRequest->normalizeTestValues();

    expect($jsonRequest->input('amount'))->toBe('2500.5')
        ->and($jsonRequest->input('lines.0.quantity'))->toBe('0');
});
