<?php

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\Company;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;

function createFixedAssetFoundationAccount(
    Company $company,
    int $documentNumber,
    string $accountCode,
    string $name,
    ?Account $parent = null,
    bool $isSystem = false,
    ?string $notes = null,
): Account {
    return Account::query()->create([
        'doc_number' => $documentNumber,
        'doc_num' => "HOTFIX-ACC-{$documentNumber}",
        'company_id' => $company->getKey(),
        'account_code' => $accountCode,
        'name' => $name,
        'name_en' => $name,
        'parent_id' => $parent?->getKey(),
        'level' => $parent instanceof Account ? (int) $parent->level + 1 : 1,
        'account_type' => Account::TypeAsset,
        'statement_type' => Account::StatementFinancialPosition,
        'normal_balance' => Account::BalanceDebit,
        'is_group' => true,
        'is_postable' => false,
        'is_system' => $isSystem,
        'status' => 'active',
        'notes' => $notes,
    ]);
}

test('Fixed Asset foundation preserves active code owners and leaves conflicting legacy duplicates deleted', function (): void {
    $company = Company::query()->create([
        'doc_number' => 91001,
        'doc_num' => 'HOTFIX-COMPANY-91001',
        'name' => 'Migration Conflict Company',
        'status' => 'active',
    ]);

    $legacyAssets = createFixedAssetFoundationAccount($company, 91101, '1', 'Legacy Assets', isSystem: true);
    $legacyAssets->delete();
    $activeAssets = createFixedAssetFoundationAccount($company, 91201, '1', 'Customer Assets');

    $legacyNonCurrent = createFixedAssetFoundationAccount($company, 91102, '12', 'Legacy Non-current Assets', $legacyAssets, true);
    $legacyNonCurrent->delete();
    $activeNonCurrent = createFixedAssetFoundationAccount($company, 91202, '12', 'Customer Non-current Assets', $activeAssets);

    $legacyRoot = createFixedAssetFoundationAccount($company, 91103, '121', 'Legacy Fixed Assets', $legacyNonCurrent, true);
    $legacyRoot->delete();
    $activeRoot = createFixedAssetFoundationAccount($company, 91203, '121', 'Customer Fixed Asset Register', $activeNonCurrent);

    $activeCodeTwelveAttributes = $activeNonCurrent->fresh()->getAttributes();
    $preferredCodes = [
        'machinery' => '1216',
        'vehicles' => '1217',
        'it_office' => '1218',
        'furniture' => '1219',
        'land' => '12110',
        'buildings' => '12111',
    ];
    $legacyCategories = collect();
    $activeCategories = collect();
    $documentOffset = 0;

    foreach ($preferredCodes as $key => $accountCode) {
        $legacy = createFixedAssetFoundationAccount(
            $company,
            91300 + $documentOffset,
            $accountCode,
            "Legacy {$key}",
            $activeRoot,
            true,
            "system:fixed_asset_category:{$key}",
        );
        $legacy->delete();
        $legacyCategories->push($legacy);
        $activeCategories->push(createFixedAssetFoundationAccount(
            $company,
            91400 + $documentOffset,
            $accountCode,
            "Customer-owned {$key}",
            $activeRoot,
        ));
        $documentOffset++;
    }

    $foundation = app(BusinessPartnerAccountService::class);
    $foundation->ensureFixedAssetBaselineForCompany((int) $company->getKey());
    $foundation->ensureFixedAssetBaselineForCompany((int) $company->getKey());

    $classification = AccountClassification::query()->where('code', 'fixed_assets')->firstOrFail();

    expect($activeNonCurrent->fresh()->getAttributes())->toBe($activeCodeTwelveAttributes)
        ->and(Account::withTrashed()->findOrFail($legacyAssets->getKey())->trashed())->toBeTrue()
        ->and(Account::withTrashed()->findOrFail($legacyNonCurrent->getKey())->trashed())->toBeTrue()
        ->and(Account::withTrashed()->findOrFail($legacyRoot->getKey())->trashed())->toBeTrue()
        ->and($legacyCategories->every(fn (Account $account): bool => Account::withTrashed()->findOrFail($account->getKey())->trashed()))->toBeTrue()
        ->and($activeRoot->fresh()->name)->toBe('Customer Fixed Asset Register')
        ->and((int) $activeRoot->fresh()->account_classification_id)->toBe((int) $classification->getKey())
        ->and($activeCategories->every(fn (Account $account): bool => (int) $account->fresh()->account_classification_id === (int) $classification->getKey()))->toBeTrue()
        ->and(Account::query()->where('company_id', $company->getKey())->whereIn('account_code', ['1', '12', '121', ...array_values($preferredCodes)])->count())->toBe(9)
        ->and(FixedAssetCategoryMapping::query()->where('company_id', $company->getKey())->count())->toBe(0);
});

test('Fixed Asset reference classification installs without rewriting an incompatible customer account tree', function (): void {
    $company = Company::query()->create([
        'doc_number' => 92001,
        'doc_num' => 'HOTFIX-COMPANY-92001',
        'name' => 'Unconfigured Mapping Company',
        'status' => 'active',
    ]);
    $assets = createFixedAssetFoundationAccount($company, 92101, '1', 'Customer Assets');
    $account = createFixedAssetFoundationAccount($company, 92102, '12', 'Customer Posting Account', $assets);
    $account->forceFill(['is_group' => false, 'is_postable' => true])->save();
    $originalAttributes = $account->fresh()->getAttributes();

    app(BusinessPartnerAccountService::class)->ensureFixedAssetBaselineForCompany((int) $company->getKey());

    expect(AccountClassification::query()->where('code', 'fixed_assets')->where('status', 'active')->exists())->toBeTrue()
        ->and($account->fresh()->getAttributes())->toBe($originalAttributes)
        ->and(Account::query()->where('company_id', $company->getKey())->where('account_code', '121')->exists())->toBeFalse()
        ->and(FixedAssetCategoryMapping::query()->where('company_id', $company->getKey())->exists())->toBeFalse();
});
