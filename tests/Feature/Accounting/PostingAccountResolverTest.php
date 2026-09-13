<?php

use App\Services\PostingAccountConfigurationAudit;
use App\Services\PostingAccountResolver;
use Database\Seeders\DefaultOperatingContextSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Company;

beforeEach(function (): void {
    app()->setLocale('en');
    $this->seed(DefaultOperatingContextSeeder::class);
    $this->seed(DefaultChartOfAccountsSeeder::class);
});

test('default chart provides one eligible posting account for every required classification', function (): void {
    $company = Company::query()->active()->firstOrFail();
    $audit = app(PostingAccountConfigurationAudit::class)->forCompany((int) $company->getKey());

    expect(collect($audit['rows'])->where('status', '<>', 'ready')->values()->all())->toBe([])
        ->and($audit['ok'])->toBeTrue()
        ->and($audit['missing_count'])->toBe(0)
        ->and($audit['ambiguous_count'])->toBe(0)
        ->and($audit['valid_count'])->toBe(count(PostingAccountResolver::requiredClassificationCodes()))
        ->and(app(PostingAccountResolver::class)->resolve(
            (int) $company->getKey(),
            PostingAccountResolver::PackagingMaterialInventory,
            'Purchase invoice approval',
        )->account_code)->toBe('1134');
});

test('missing classification assignment names the chart classification instead of a hardcoded account code', function (): void {
    $company = Company::query()->active()->firstOrFail();
    $classification = AccountClassification::query()
        ->where('code', PostingAccountResolver::PackagingMaterialInventory)
        ->firstOrFail();
    Account::query()
        ->forCompany((int) $company->getKey())
        ->where('account_classification_id', $classification->getKey())
        ->update(['account_classification_id' => null]);

    expect(fn () => app(PostingAccountResolver::class)->resolve(
        (int) $company->getKey(),
        PostingAccountResolver::PackagingMaterialInventory,
        'Purchase invoice approval',
    ))->toThrow(DomainException::class, PostingAccountResolver::PackagingMaterialInventory);

    $audit = app(PostingAccountConfigurationAudit::class)->forCompany((int) $company->getKey());
    expect($audit['ok'])->toBeFalse()
        ->and(collect($audit['rows'])->firstWhere('code', PostingAccountResolver::PackagingMaterialInventory)['status'])
        ->toBe('account_missing');
});

test('duplicate posting accounts are rejected instead of selecting an arbitrary account', function (): void {
    $company = Company::query()->active()->firstOrFail();
    $classification = AccountClassification::query()
        ->where('code', PostingAccountResolver::PackagingMaterialInventory)
        ->firstOrFail();
    $source = Account::query()
        ->forCompany((int) $company->getKey())
        ->where('account_classification_id', $classification->getKey())
        ->sole();

    Account::query()->create([
        'doc_number' => 999991,
        'doc_num' => 'Account-999991',
        'company_id' => $company->getKey(),
        'account_code' => '1134999',
        'name' => 'Duplicate Packaging Posting',
        'parent_id' => $source->parent_id,
        'level' => $source->level,
        'account_classification_id' => $classification->getKey(),
        'account_type' => $source->account_type,
        'statement_type' => $source->statement_type,
        'normal_balance' => $source->normal_balance,
        'is_group' => false,
        'is_postable' => true,
        'status' => 'active',
    ]);

    expect(fn () => app(PostingAccountResolver::class)->resolve(
        (int) $company->getKey(),
        PostingAccountResolver::PackagingMaterialInventory,
        'Purchase invoice approval',
    ))->toThrow(DomainException::class, 'more than one active postable account');
});
