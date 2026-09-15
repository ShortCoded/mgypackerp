<?php

use App\Models\User;
use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingContextService;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Services\ProductionCycleService;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

test('authorized users see an Arabic production order toolbar and can open the create form', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    app()->setLocale('ar');
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $user = User::factory()->create(['locale' => 'ar']);

    foreach (['production.orders.view', 'production.orders.create', 'production.orders.delete', 'production.orders.view_trashed', 'production.orders.document_number_settings.update'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];
    $order = ProductionOrder::query()->create([
        'doc_number' => 99001,
        'doc_num' => 'PRODUCTION-UI-TEST',
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'source_type' => 'make_to_stock',
        'production_order_date' => now()->toDateString(),
        'created_by' => $user->getKey(),
    ]);

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.work-orders.index'))
        ->assertSuccessful()
        ->assertSee('إضافة أمر إنتاج')
        ->assertSee('إعدادات رقم المستند')
        ->assertSee('production-orders-document-number-settings')
        ->assertSee(route('admin.production.work-orders.document-number-settings.update'), false)
        ->assertSee('production-orders-table')
        ->assertDontSee('Production orders may be standalone');

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.work-orders.create'))
        ->assertSuccessful()
        ->assertSee('إنتاج مستقل للمخزون')
        ->assertSee('أمر بيع')
        ->assertSee('فاتورة مبيعات')
        ->assertSee('data-production-order-form', false)
        ->assertSee('data-duplicate-production-line', false)
        ->assertSee('source_line_reference', false)
        ->assertDontSee('Create Production Order');

    $this->actingAs($user)->withSession($context)
        ->getJson(route('admin.production.work-orders.data', ['draw' => 1, 'start' => 0, 'length' => 10]))
        ->assertSuccessful()
        ->assertJsonPath('data.0.doc_num', fn (string $value): bool => str_contains($value, 'PRODUCTION-UI-TEST'))
        ->assertJsonPath('data.0.source_document_number', 'إنتاج مستقل للمخزون')
        ->assertJsonPath('data.0.created_by', $user->name)
        ->assertJsonPath('recordsFiltered', 1);

    $this->actingAs($user)->withSession($context)
        ->get(route('admin.production.work-orders.show', $order))
        ->assertSuccessful()
        ->assertSee('إنتاج مستقل للمخزون')
        ->assertSee($branch->name)
        ->assertDontSee('Operating factory');

    $this->actingAs($user)->withSession($context)
        ->putJson(route('admin.production.work-orders.document-number-settings.update'), [
            'prefix' => 'PO-',
            'padding' => 6,
        ])
        ->assertSuccessful()
        ->assertJsonPath('message', 'تم تحديث إعدادات رقم أمر الإنتاج بنجاح.')
        ->assertJsonPath('data.prefix', 'PO-')
        ->assertJsonPath('data.padding', 6);

    $this->assertDatabaseHas('settings', ['key' => 'document_numbers.production_orders.prefix', 'value' => 'PO-']);
    $this->assertDatabaseHas('settings', ['key' => 'document_numbers.production_orders.padding', 'value' => '6']);

    $viewOnlyUser = User::factory()->create(['locale' => 'ar']);
    Permission::findOrCreate('production.orders.view', 'web');
    $viewOnlyUser->givePermissionTo('production.orders.view');

    $this->actingAs($viewOnlyUser)->withSession($context)
        ->get(route('admin.production.work-orders.index'))
        ->assertSuccessful()
        ->assertDontSee('إضافة أمر إنتاج')
        ->assertDontSee('production-orders-document-number-settings');

    $this->actingAs($viewOnlyUser)->withSession($context)
        ->get(route('admin.production.work-orders.create'))
        ->assertForbidden();

    $this->actingAs($viewOnlyUser)->withSession($context)
        ->putJson(route('admin.production.work-orders.document-number-settings.update'), [
            'prefix' => 'NO-',
            'padding' => 4,
        ])
        ->assertForbidden();
});

test('all production create forms and operational report pages render in Arabic', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    app()->setLocale('ar');
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $user = User::factory()->create(['locale' => 'ar']);

    foreach ([
        'production.stages.create',
        'production.orders.create',
        'production.runs.plan',
        'production.material_requests.create',
        'production.expenses.create',
        'production.quality.create',
        'production.reports.operational',
        'production.reports.export',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $user->givePermissionTo($permission);
    }

    $context = [
        OperatingContextService::CompanyIdKey => $company->getKey(),
        OperatingContextService::CompanyDocNumKey => $company->doc_num,
        OperatingContextService::BranchIdKey => $branch->getKey(),
        OperatingContextService::BranchDocNumKey => $branch->doc_num,
        OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
    ];

    foreach ([
        'admin.production.stages.create' => 'إضافة مرحلة إنتاجية',
        'admin.production.work-orders.create' => 'إضافة أمر إنتاج',
        'admin.production.runs.create' => 'إضافة تشغيلة',
        'admin.production.material-requests.create' => 'إضافة طلب خامات',
        'admin.production.expenses.create' => 'إضافة طلب مصروف',
        'admin.production.quality.create' => 'إنشاء فحص جودة',
        'admin.production.reports.index' => 'ملخص تقارير الإنتاج',
        'admin.production.reports.orders' => 'موقف أوامر الإنتاج',
        'admin.production.reports.runs' => 'أداء التشغيلات والورديات',
        'admin.production.reports.materials' => 'ترصيد خامات الإنتاج',
        'admin.production.reports.quality' => 'متابعة جودة الإنتاج',
        'admin.production.reports.receipts' => 'استلامات المنتج التام',
    ] as $routeName => $visibleTitle) {
        $this->actingAs($user)->withSession($context)
            ->get(route($routeName))
            ->assertSuccessful()
            ->assertSee($visibleTitle)
            ->assertDontSee('Internal Server Error');
    }
});

test('standalone production orders support safe draft crud and human quantities', function (): void {
    $this->seed(DefaultOperatingContextSeeder::class);
    $user = User::factory()->create();
    $this->actingAs($user);
    auth()->login($user);
    $company = Company::query()->where('status', 'active')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->firstOrFail();
    $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->firstOrFail();
    $unit = ItemUnit::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 87001,
        'doc_num' => 'UNIT-PROD-CRUD',
        'name' => 'Piece',
        'status' => 'active',
    ]);
    $product = Product::query()->create([
        'company_id' => $company->getKey(),
        'doc_number' => 87001,
        'doc_num' => 'FG-PROD-CRUD',
        'name' => 'Finished Product',
        'item_classification' => Product::ClassificationFinishedProduct,
        'item_unit_id' => $unit->getKey(),
        'status' => 'active',
    ]);
    $service = app(ProductionCycleService::class);
    $header = [
        'company_id' => $company->getKey(),
        'financial_period_id' => $period->getKey(),
        'branch_id' => $branch->getKey(),
        'source_type' => 'make_to_stock',
        'production_order_date' => now()->toDateString(),
        'priority' => 'normal',
    ];

    $order = $service->createMakeToStockOrder($header, [[
        'product_id' => $product->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '12.50000000',
    ]]);

    expect($order->source_type)->toBe('make_to_stock')
        ->and($order->sales_order_id)->toBeNull()
        ->and($order->lines)->toHaveCount(1)
        ->and(app(NumericFormatService::class)->format($order->lines->sole()->quantity))->toBe('12.5');

    $updated = $service->updateDraftOrder($order, [...$header, 'priority' => 'urgent'], [[
        'product_id' => $product->getKey(),
        'unit_id' => $unit->getKey(),
        'quantity' => '15',
    ]]);
    expect($updated->priority)->toBe('urgent')
        ->and($updated->lines->sole()->quantity)->toBe('15.00000000');

    $service->deleteDraftOrder($updated);
    expect(ProductionOrder::onlyTrashed()->whereKey($updated->getKey())->exists())->toBeTrue();

    $restored = $service->restoreDraftOrder(ProductionOrder::onlyTrashed()->findOrFail($updated->getKey()));
    expect($restored->trashed())->toBeFalse()
        ->and($restored->restored_by)->toBe($user->getKey());
});

test('movement quantity fields stay numeric and date fields stay date pickers', function (): void {
    $view = file_get_contents(resource_path('views/modules/inventory/documents/create.blade.php'));
    $numericComponent = file_get_contents(resource_path('views/components/forms/numeric-input.blade.php'));
    $numericJavascript = file_get_contents(public_path('assets/js/modules/Core/numeric-input.js'));

    expect($view)
        ->toContain('lines[__INDEX__][quantity]')
        ->toContain('<x-forms.numeric-input')
        ->toContain('lines[__INDEX__][manufacture_date]')
        ->toContain('<x-forms.date-input')
        ->toContain("['scope' => 'destination']")
        ->and($numericComponent)->toContain('data-numeric-arrow-step')
        ->and($numericJavascript)->toContain("event.key === 'ArrowUp'")
        ->toContain('incrementInput');

    expect(Route::has('admin.inventory.documents.clone'))->toBeTrue()
        ->and(Route::has('admin.inventory.documents.destroy'))->toBeTrue()
        ->and(Route::has('admin.inventory.documents.restore'))->toBeTrue()
        ->and(Route::has('admin.inventory.documents.bulk-delete'))->toBeTrue();
});

test('production labor is constrained to active labor employees', function (): void {
    $storeRequest = file_get_contents(base_path('modules/Production/Http/Requests/StoreProductionRunRequest.php'));
    $laborRequest = file_get_contents(base_path('modules/Production/Http/Requests/RecordProductionLaborRequest.php'));
    $runView = file_get_contents(resource_path('views/modules/production/runs/show.blade.php'));
    $runForm = file_get_contents(resource_path('views/modules/production/runs/form.blade.php'));

    expect($storeRequest)->toContain("'regular_labor', 'casual_labor'")
        ->and($laborRequest)->toContain("'regular_labor', 'casual_labor'")
        ->and($runForm)->toContain('production_order_line_public_id')
        ->toContain('employee_doc_num')
        ->toContain('data-duplicate-labor-row')
        ->toContain('modules.finance.partials.form-actions')
        ->and($runView)->toContain('labor_details[{{ $index }}][employee_id]')
        ->not->toContain('labor_details[{{ $index }}][name]');
});
