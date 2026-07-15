<?php

use Database\Seeders\DefaultOperatingContextSeeder;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\FinancialPeriod;

test('default operating context seeder creates valid defaults once', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 10, 12));

    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(DefaultOperatingContextSeeder::class);

    $company = Company::query()->where('name', 'Short Coded')->firstOrFail();
    $branch = Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->firstOrFail();
    $period = FinancialPeriod::query()->where('name', '2026')->firstOrFail();

    expect(Company::query()->where('name', 'Short Coded')->count())->toBe(1)
        ->and(Company::query()->main()->count())->toBe(1)
        ->and($company->name)->toBe('Short Coded')
        ->and($company->legal_name)->toBe('Short Coded')
        ->and($company->status)->toBe('active')
        ->and($company->is_main)->toBeTrue()
        ->and($company->doc_num)->toBe('Company-00001')
        ->and(Branch::query()->where('company_id', $company->getKey())->where('name', 'Main Branch')->count())->toBe(1)
        ->and($branch->type)->toBe('administrative')
        ->and($branch->status)->toBe('active')
        ->and($branch->doc_num)->toBe('Branch-00001')
        ->and($period->from_date?->toDateString())->toBe('2026-01-01')
        ->and($period->to_date?->toDateString())->toBe('2026-12-31')
        ->and($period->is_closed)->toBeFalse()
        ->and($period->doc_num)->toBe('Period-00001')
        ->and(FinancialPeriod::query()->where('name', '2026')->count())->toBe(1);

    Carbon::setTestNow();
});

test('default operating context seeder does not unset an existing active main company', function () {
    $existing = Company::factory()->main()->create([
        'name' => 'Existing Main',
    ]);

    $this->seed(DefaultOperatingContextSeeder::class);

    $existing->refresh();
    $default = Company::query()->where('name', 'Short Coded')->firstOrFail();

    expect(Company::query()->count())->toBe(2)
        ->and($existing->is_main)->toBeTrue()
        ->and($existing->status)->toBe('active')
        ->and($default->name)->toBe('Short Coded')
        ->and($default->is_main)->toBeFalse()
        ->and($default->status)->toBe('active')
        ->and(Branch::query()->where('company_id', $default->getKey())->where('name', 'Main Branch')->exists())->toBeTrue();
});
