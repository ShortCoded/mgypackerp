<?php

use Modules\Core\Models\Currency;
use Modules\Core\Services\OperatingContextService;
use Spatie\Permission\Models\Permission;

require_once dirname(__DIR__).'/SalesCycleSupport.php';

test('dashboard summaries are lazy in the collapsed container and independently authorized', function (): void {
    $fixture = salesCycleFixture();
    $session = salesCycleSession($fixture);
    Permission::findOrCreate('dashboard.summaries.sales.view', 'web');
    $fixture['user']->givePermissionTo('dashboard.summaries.sales.view');
    $this->actingAs($fixture['user'])->withSession($session);

    $page = $this->get(route('dashboard'))->assertOk();
    $page->assertSee('data-dashboard-summaries', false)
        ->assertSee('AppDashboardSummaries', false)
        ->assertSee('value="'.$fixture['currency']->doc_num.'" selected', false)
        ->assertDontSee('purchases:', false);
    expect($page->getContent())->toContain('<details class="card mb-3" data-dashboard-summaries>')
        ->not->toContain('<details class="card mb-3" data-dashboard-summaries open>');

    $this->getJson(route('dashboard.summaries.purchases'))->assertForbidden();
    $sales = $this->getJson(route('dashboard.summaries.sales'))
        ->assertOk()
        ->assertJsonPath('empty', false)
        ->assertJsonPath('filters.branch', $fixture['branch']->doc_num)
        ->assertJsonPath('filters.financial_period', $fixture['period']->doc_num)
        ->assertJsonPath('filters.currency', $fixture['currency']->code);
    expect(collect($sales->json('metrics'))->pluck('title')->all())->toContain(
        __('dashboard.summaries.metrics.sales_orders'),
        __('dashboard.summaries.metrics.sales_invoices'),
        __('dashboard.summaries.metrics.sales_value'),
        __('dashboard.summaries.metrics.sales_returns'),
        __('dashboard.summaries.metrics.sales_outstanding'),
    );
});

test('dashboard summary filters are scope checked and currency is applied without cross currency totals', function (): void {
    $fixture = salesCycleFixture();
    foreach (['dashboard.summaries.sales.view', 'dashboard.summaries.purchases.view'] as $permission) {
        Permission::findOrCreate($permission, 'web');
        $fixture['user']->givePermissionTo($permission);
    }
    $this->actingAs($fixture['user'])->withSession(salesCycleSession($fixture));
    salesPostedServiceInvoice($fixture, '125.0000');
    $otherCurrency = Currency::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 99001,
        'doc_num' => 'Currency-DASHBOARD-USD',
        'name' => 'US Dollar',
        'code' => 'USD',
        'minor_unit_name' => 'Cent',
        'minor_unit_factor' => 100,
        'is_main' => false,
        'status' => 'active',
    ]);
    $filters = [
        'branch_doc_num' => $fixture['branch']->doc_num,
        'financial_period_doc_num' => $fixture['period']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'date_from' => $fixture['period']->from_date->toDateString(),
        'date_to' => $fixture['period']->to_date->toDateString(),
    ];

    $sales = $this->getJson(route('dashboard.summaries.sales', $filters))
        ->assertOk()
        ->assertJsonPath('filters.currency', $fixture['currency']->code);
    $salesMetrics = collect($sales->json('metrics'))->keyBy('key');
    expect($salesMetrics['sales_invoices']['value'])->toBe('1')
        ->and($salesMetrics['sales_value']['value'])->toContain('125')
        ->and($salesMetrics['sales_outstanding']['value'])->toContain('125');
    $this->getJson(route('dashboard.summaries.purchases', $filters))
        ->assertOk()
        ->assertJsonPath('filters.currency', $fixture['currency']->code);
    $this->getJson(route('dashboard.summaries.sales', [...$filters, 'branch_doc_num' => 'OTHER-BRANCH']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('branch_doc_num');
    $this->getJson(route('dashboard.summaries.sales', [...$filters, 'currency_doc_num' => 'OTHER-CURRENCY']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('currency_doc_num');

    $this->getJson(route('dashboard.summaries.sales', [...$filters, 'currency_doc_num' => $otherCurrency->doc_num]))
        ->assertOk()
        ->assertJsonPath('filters.currency', $otherCurrency->code);

    $allCurrencies = $this->getJson(route('dashboard.summaries.sales', [...$filters, 'currency_doc_num' => '']))
        ->assertOk()
        ->assertJsonPath('filters.currency', null);
    expect(collect($allCurrencies->json('metrics'))->keyBy('key')['sales_value']['value'])
        ->toBe(__('dashboard.summaries.select_currency_for_value'));
});

test('dashboard summary endpoint returns the context-required empty state without an operating selection', function (): void {
    $fixture = salesCycleFixture();
    Permission::findOrCreate('dashboard.summaries.sales.view', 'web');
    $fixture['user']->givePermissionTo('dashboard.summaries.sales.view');

    $this->actingAs($fixture['user'])
        ->withSession([
            OperatingContextService::CompanyIdKey => null,
            OperatingContextService::CompanyDocNumKey => null,
            OperatingContextService::BranchIdKey => null,
            OperatingContextService::BranchDocNumKey => null,
            OperatingContextService::FinancialPeriodIdKey => null,
            OperatingContextService::FinancialPeriodDocNumKey => null,
        ])
        ->getJson(route('dashboard.summaries.sales'))
        ->assertOk()
        ->assertJsonPath('empty', true)
        ->assertJsonPath('message', __('dashboard.summaries.context_required'));
});
