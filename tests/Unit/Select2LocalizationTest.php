<?php

use Illuminate\Support\Facades\Validator;
use Modules\Accounting\Http\Requests\StoreJournalEntryRequest;
use Modules\Core\Http\Requests\StorePostedInvoiceLineCorrectionRequest;
use Modules\Core\Services\OperatingContextService;
use Modules\Inventory\Http\Requests\OpeningStockCostCorrectionRequest;
use Modules\Inventory\Http\Requests\OpeningStockQuantityCorrectionRequest;
use Modules\Inventory\Http\Requests\StoreInventoryMovementCorrectionRequest;
use Modules\Production\Http\Requests\ProposeProductionRunCorrectionRequest;
use Modules\Production\Http\Requests\StoreProductionMaterialSubstitutionRequest;
use Modules\Sales\Http\Requests\StoreCustomerInvoiceCorrectionRequest;
use Modules\Sales\Http\Requests\StoreSalesReturnCorrectionRequest;
use Tests\TestCase;

uses(TestCase::class);

test('shared select2 configuration renders all messages in the active locale', function (string $locale, string $searching, string $placeholder): void {
    app()->setLocale($locale);
    $html = view('layouts.partials.select2-config')->render();
    preg_match('/window.AppSelect2 = (.*);<\/script>/', $html, $match);
    $config = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);

    expect($config['placeholder'])->toBe($placeholder)
        ->and($config['messages']['searching'])->toBe($searching);

    foreach (['errorLoading', 'inputTooShort', 'inputTooLong', 'loadingMore', 'maximumSelected', 'noResults', 'removeAllItems', 'removeItem', 'search', 'searching'] as $key) {
        expect($config['messages'][$key])->not->toBeEmpty()
            ->not->toContain('common.');
    }
})->with([
    ['ar', 'جاري البحث...', 'اختر...'],
    ['en', 'Searching...', 'Select...'],
]);

test('journal and correction validation rules have localized names including nested fields', function (string $locale): void {
    app()->setLocale($locale);
    $this->mock(OperatingContextService::class)->shouldReceive('snapshot')->andReturn(['company_id' => null]);

    foreach ([StoreJournalEntryRequest::class, StorePostedInvoiceLineCorrectionRequest::class,
        StoreInventoryMovementCorrectionRequest::class, StoreCustomerInvoiceCorrectionRequest::class,
        StoreSalesReturnCorrectionRequest::class, ProposeProductionRunCorrectionRequest::class,
        StoreProductionMaterialSubstitutionRequest::class, OpeningStockQuantityCorrectionRequest::class,
        OpeningStockCostCorrectionRequest::class] as $class) {
        $request = new $class;
        $attributes = array_merge(__('validation.attributes'), $request->attributes());

        foreach (array_keys($request->rules()) as $field) {
            expect(array_key_exists($field, $attributes))->toBeTrue($class.' '.$field);
            $concreteField = str_replace('*', '0', $field);
            $validator = Validator::make([], [$concreteField => ['required']], [], $request->attributes());
            expect($validator->errors()->first($concreteField))
                ->toBe(__('validation.required', ['attribute' => $attributes[$field]]));
        }
    }
})->with(['ar', 'en']);

test('journal nested party and description validation uses the visible labels', function (string $locale): void {
    app()->setLocale($locale);
    $request = new StoreJournalEntryRequest;
    $fields = ['description' => 'line_description', 'customer_doc_num' => 'customer', 'supplier_doc_num' => 'supplier', 'employee_doc_num' => 'employee'];
    $data = ['lines' => [['description' => [], 'customer_doc_num' => [], 'supplier_doc_num' => [], 'employee_doc_num' => []]]];
    $rules = array_intersect_key($request->rules(), array_fill_keys(array_map(fn (string $field): string => 'lines.*.'.$field, array_keys($fields)), true));
    $validator = Validator::make($data, $rules, [], $request->attributes());

    foreach ($fields as $field => $label) {
        expect($validator->errors()->first('lines.0.'.$field))
            ->toContain(__('journal_entries.attributes.'.$label))
            ->not->toContain('lines.0');
    }
})->with(['ar', 'en']);

test('posted invoice tax headings are translated in both locales', function (): void {
    expect(__('posted_invoice_correction.tax_rate', [], 'ar'))->toBe('نسبة الضريبة')
        ->and(__('posted_invoice_correction.tax_amount', [], 'ar'))->toBe('مبلغ الضريبة')
        ->and(__('posted_invoice_correction.tax_rate', [], 'en'))->toBe('Tax rate')
        ->and(__('posted_invoice_correction.tax_amount', [], 'en'))->toBe('Tax amount');
});
