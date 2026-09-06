<?php

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Imports\ProductExcelImportDefinition;
use Tests\TestCase;

uses(TestCase::class);

test('task import quotation and period references have translations without fallback', function (string $path, string $locale): void {
    $source = file_get_contents(base_path($path));
    preg_match_all('/__\(\s*[\'"]([a-z_]+(?:\.[a-z_]+)+)[\'"]/', $source, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $key) {
        expect(Lang::hasForLocale($key, $locale))->toBeTrue("Missing {$locale} translation [{$key}] in {$path}");
    }
})->with([
    'task buttons' => ['resources/views/modules/core/user-tasks/partials/form-actions.blade.php'],
    'task list' => ['resources/views/modules/core/user-tasks/index.blade.php'],
    'import wizard' => ['resources/views/modules/core/excel-imports/wizard.blade.php'],
    'quotation report' => ['resources/views/reports/sales/quotation.blade.php'],
    'quotation print' => ['resources/views/modules/sales/quotations/print.blade.php'],
    'product import' => ['modules/Core/Imports/ProductExcelImportDefinition.php'],
    'financial period service' => ['modules/Core/Services/FinancialPeriodService.php'],
])->with(['ar', 'en']);

test('task save actions render translated labels', function (string $locale): void {
    app()->setLocale($locale);

    foreach (['tasks.view', 'tasks.edit', 'tasks.create'] as $permission) {
        Gate::shouldReceive('check')->with($permission)->once()->andReturnTrue();
    }

    $html = view('modules.core.user-tasks.partials.form-actions', ['mode' => 'create'])->render();

    expect($html)
        ->toContain(__('common.actions.save_and_view'))
        ->toContain(__('common.actions.save_and_edit'))
        ->toContain(__('common.actions.save_and_new'))
        ->not->toContain('common.actions.');
})->with(['ar', 'en']);

test('product component import exposes a localized input source heading', function (string $locale, string $label): void {
    app()->setLocale($locale);

    $fields = app(ProductExcelImportDefinition::class)->componentFields();

    expect($fields['input_source']['label'])->toBe($label);
})->with([
    'arabic' => ['ar', 'مصدر إدخال المكوّن'],
    'english' => ['en', 'Component Input Source'],
]);

test('arabic conditional presence rules replace the complete value placeholder', function (string $rule, array $data, string $message): void {
    app()->setLocale('ar');

    $validator = Validator::make($data, ['field' => ["{$rule}:other,y"]], [], [
        'field' => 'الاسم',
        'other' => 'النوع',
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('field'))->toBe($message);
})->with([
    'missing unless' => ['missing_unless', ['field' => 'value', 'other' => 'x'], 'حقل الاسم يجب أن يكون غير موجود إلا إذا كان النوع هو y.'],
    'present unless' => ['present_unless', ['other' => 'x'], 'حقل الاسم يجب أن يكون موجودًا إلا إذا كان النوع هو y.'],
]);
