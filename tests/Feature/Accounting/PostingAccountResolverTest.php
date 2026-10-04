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

test('posting resolver and audit exclude incompatible legacy dimensions without changing their rows', function (string $dimension, string $value): void {
    $company = Company::query()->active()->firstOrFail();
    $classification = AccountClassification::query()->where('code', PostingAccountResolver::InventoryAdjustmentLoss)->firstOrFail();
    $valid = Account::query()->forCompany($company->id)->where('account_classification_id', $classification->id)->sole();
    $legacy = $valid->replicate(['id', 'created_at', 'updated_at']);
    $legacy->forceFill(['doc_number' => 999669, 'doc_num' => 'SYNTHETIC-LEGACY-669', 'account_code' => '999669',
        'name' => 'Synthetic incompatible legacy mapping', $dimension => $value])->save();
    $before = $legacy->fresh()->getAttributes();
    $resolver = app(PostingAccountResolver::class);
    expect($resolver->resolve($company->id, $classification->code, 'synthetic acceptance')->id)->toBe($valid->id)
        ->and($resolver->resolveFirst($company->id, $classification->code, 'synthetic acceptance')->id)->toBe($valid->id);
    $audit = app(PostingAccountConfigurationAudit::class)->forCompany($company->id);
    $row = collect($audit['rows'])->firstWhere('code', $classification->code);
    expect($row['status'])->toBe('ready')->and($row['incompatible_accounts'])->toContain('999669')
        ->and($audit['incompatible_count'])->toBe(1)->and($legacy->fresh()->getAttributes())->toBe($before);
    $valid->update(['status' => 'inactive']);
    expect(fn () => $resolver->resolve($company->id, $classification->code, 'synthetic acceptance'))->toThrow(DomainException::class)
        ->and(fn () => $resolver->resolveFirst($company->id, $classification->code, 'synthetic acceptance'))->toThrow(DomainException::class);
    $row = collect(app(PostingAccountConfigurationAudit::class)->forCompany($company->id)['rows'])->firstWhere('code', $classification->code);
    expect($row['status'])->toBe('account_missing')->and($row['incompatible_accounts'])->toContain('999669')
        ->and($legacy->fresh()->getAttributes())->toBe($before);
})->with(['account type' => ['account_type', 'asset'], 'statement' => ['statement_type', 'financial_position'],
    'normal balance' => ['normal_balance', 'credit']]);

test('posting compatibility follows current classification metadata instead of stale account selections', function (): void {
    $company = Company::query()->active()->firstOrFail();
    $classification = AccountClassification::query()->where('code', PostingAccountResolver::InventoryAdjustmentLoss)->firstOrFail();
    $resolver = app(PostingAccountResolver::class);
    $original = $resolver->resolve($company->id, $classification->code, 'synthetic metadata acceptance');
    $classification->update(['normal_balance' => 'credit']);
    expect(fn () => $resolver->resolve($company->id, $classification->code, 'synthetic metadata acceptance'))->toThrow(DomainException::class);
    $classification->update(['normal_balance' => 'debit']);
    expect($resolver->resolve($company->id, $classification->code, 'synthetic metadata acceptance')->id)->toBe($original->id);
});
