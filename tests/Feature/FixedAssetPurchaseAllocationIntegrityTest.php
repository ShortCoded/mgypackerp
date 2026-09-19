<?php

use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\JournalEntry;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetMovement;
use Modules\FixedAssets\Services\FixedAssetBookValueService;
use Modules\FixedAssets\Services\FixedAssetPurchaseIntegrationService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseInvoiceLine;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../ProcurementSupport.php';

test('purchase line allocation is revalidated atomically when assets are persisted', function (): void {
    $fixture = procurementFixture();
    $supplierAccount = procurementPostingAccount($fixture['company'], '2111', '2111098', 'Allocation Supplier Payable');
    $fixture['firstSupplier']->forceFill(['account_id' => $supplierAccount->getKey()])->save();
    $assetCategory = app(BusinessPartnerAccountService::class)->createGroup(
        BusinessPartnerAccountService::FixedAsset,
        'Allocation Integrity Assets',
    );
    $product = Product::query()->create([
        'company_id' => $fixture['company']->getKey(),
        'doc_number' => 9198,
        'doc_num' => 'Product-ASSET-ALLOCATION',
        'name' => 'Allocation Integrity Asset',
        'item_classification' => Product::ClassificationOther,
        'item_unit_id' => $fixture['unit']->getKey(),
        'cost_as_inventory' => false,
        'status' => 'active',
    ]);
    $invoiceDate = now()->toDateString();
    $otherPeriod = FinancialPeriod::query()->create([
        'doc_number' => 9198,
        'doc_num' => 'Period-ASSET-ALLOCATION-OTHER',
        'company_id' => $fixture['company']->getKey(),
        'name' => 'Other allocation period',
        'from_date' => now()->addYear()->startOfYear()->toDateString(),
        'to_date' => now()->addYear()->endOfYear()->toDateString(),
        'is_closed' => false,
    ]);
    $invoice = PurchaseInvoice::query()->create([
        'doc_number' => 9198,
        'doc_num' => 'PINV-ASSET-ALLOCATION',
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'branch_id' => $fixture['branch']->getKey(),
        'supplier_id' => $fixture['firstSupplier']->getKey(),
        'invoice_date' => $invoiceDate,
        'currency_id' => $fixture['currency']->getKey(),
        'exchange_rate' => '1.000000',
        'payment_type' => PurchaseInvoice::PaymentTypeCredit,
        'subtotal_amount' => '10.0000',
        'taxable_amount' => '10.0000',
        'total_amount' => '10.0000',
        'remaining_amount' => '10.0000',
        'status' => PurchaseInvoice::StatusDraft,
    ]);
    $line = PurchaseInvoiceLine::query()->create([
        'purchase_invoice_id' => $invoice->getKey(),
        'company_id' => $fixture['company']->getKey(),
        'financial_period_id' => $fixture['period']->getKey(),
        'line_number' => 1,
        'product_id' => $product->getKey(),
        'unit_id' => $fixture['unit']->getKey(),
        'quantity' => '1.00000000',
        'unit_price' => '10.0000',
        'subtotal_amount' => '10.0000',
        'total_before_tax' => '10.0000',
        'total_after_tax' => '10.0000',
    ]);
    $payload = [
        'entry_type' => FixedAsset::EntryTypeNewAsset,
        'source_type' => FixedAssetPurchaseIntegrationService::SourceType,
        'source_id' => $line->getKey(),
        'source_doc_num' => $invoice->doc_num,
        'asset_date' => $invoiceDate,
        'asset_name' => 'Initial Fractional Allocation',
        'asset_group_account_doc_num' => $assetCategory->doc_num,
        'credit_account_doc_num' => $supplierAccount->doc_num,
        'branch_doc_num' => $fixture['branch']->doc_num,
        'currency_doc_num' => $fixture['currency']->doc_num,
        'description' => 'Purchase allocation concurrency regression',
        'purchase_date' => $invoiceDate,
        'acquisition_date' => $invoiceDate,
        'operation_date' => $invoiceDate,
        'purchase_value' => '4.9999',
        'exchange_rate' => '1.000000',
        'is_depreciable' => false,
        'status' => FixedAsset::StatusDraft,
    ];
    $assets = app(FixedAssetService::class);
    $purchaseIntegration = app(FixedAssetPurchaseIntegrationService::class);
    $purchaseIntegration->assertAssetPayload($payload);

    $invoice->forceFill(['status' => PurchaseInvoice::StatusApproved])->save();
    expect(fn () => $assets->create($payload))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.invoice_must_be_draft'));
    expect(FixedAsset::query()->count())->toBe(0)
        ->and(Account::query()->where('parent_id', $assetCategory->getKey())->count())->toBe(0);

    $invoice->forceFill(['status' => PurchaseInvoice::StatusDraft])->save();
    $purchaseIntegration->assertAssetPayload($payload);
    $invoice->forceFill(['status' => PurchaseInvoice::StatusCancelled])->save();
    expect(fn () => $assets->create($payload))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.invoice_must_be_draft'));
    expect(FixedAsset::query()->count())->toBe(0)
        ->and(Account::query()->where('parent_id', $assetCategory->getKey())->count())->toBe(0);
    $invoice->forceFill(['status' => PurchaseInvoice::StatusDraft])->save();

    session([
        OperatingContextService::FinancialPeriodIdKey => $otherPeriod->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $otherPeriod->doc_num,
    ]);
    expect(fn () => $assets->create($payload))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.invoice_must_be_draft'));
    expect(FixedAsset::query()->count())->toBe(0)
        ->and(Account::query()->where('parent_id', $assetCategory->getKey())->count())->toBe(0);
    session([
        OperatingContextService::FinancialPeriodIdKey => $fixture['period']->getKey(),
        OperatingContextService::FinancialPeriodDocNumKey => $fixture['period']->doc_num,
    ]);

    $initialAsset = $assets->create($payload)['record'];
    $stalePayload = [
        ...$payload,
        'asset_name' => 'Stale Exact Remainder',
        'purchase_value' => '5.0001',
    ];

    $purchaseIntegration->assertAssetPayload($stalePayload);

    $competitor = $assets->create([
        ...$payload,
        'asset_name' => 'Competing Fractional Allocation',
        'purchase_value' => '0.0001',
    ])['record'];
    $assetCountAfterCompetitor = FixedAsset::query()->count();
    $linkedAccountCountAfterCompetitor = Account::query()->where('parent_id', $assetCategory->getKey())->count();
    $journalCount = JournalEntry::query()->count();
    $movementCount = FixedAssetMovement::query()->count();

    expect(fn () => $assets->create($stalePayload))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.allocation_exceeds_line'));
    expect(FixedAsset::query()->count())->toBe($assetCountAfterCompetitor)
        ->and(Account::query()->where('parent_id', $assetCategory->getKey())->count())->toBe($linkedAccountCountAfterCompetitor)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(FixedAssetMovement::query()->count())->toBe($movementCount);

    $exactRemainderPayload = [
        ...$payload,
        'asset_name' => 'Exact Fractional Remainder',
        'purchase_value' => '5.0000',
    ];
    $exactRemainder = $assets->create($exactRemainderPayload)['record'];
    $assetCountAtExactAllocation = FixedAsset::query()->count();
    $linkedAccountCountAtExactAllocation = Account::query()->where('parent_id', $assetCategory->getKey())->count();

    expect($purchaseIntegration->allocatedAmount($line->fresh()))->toBe('10.0000')
        ->and(app(FixedAssetBookValueService::class)->position($initialAsset->fresh())['net_book_value'])->toBe('4.9999')
        ->and(app(FixedAssetBookValueService::class)->position($competitor->fresh())['net_book_value'])->toBe('0.0001')
        ->and(app(FixedAssetBookValueService::class)->position($exactRemainder->fresh())['net_book_value'])->toBe('5.0000');

    expect(fn () => $assets->update($initialAsset, [
        ...$payload,
        'purchase_value' => '5.0000',
    ]))->toThrow(DomainException::class, __('fixed_assets.purchase_source.allocation_exceeds_line'));
    expect(fn () => $assets->create($exactRemainderPayload))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.allocation_exceeds_line'));

    expect(FixedAsset::query()->count())->toBe($assetCountAtExactAllocation)
        ->and(Account::query()->where('parent_id', $assetCategory->getKey())->count())->toBe($linkedAccountCountAtExactAllocation)
        ->and($purchaseIntegration->allocatedAmount($line->fresh()))->toBe('10.0000')
        ->and($initialAsset->fresh()->purchase_value)->toBe('4.9999')
        ->and($initialAsset->fresh()->net_value)->toBe('4.9999')
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(FixedAssetMovement::query()->count())->toBe($movementCount);

    $initialAccountId = $initialAsset->account_id;
    $assets->delete($initialAsset->fresh());
    $restoredInitialAsset = $assets->restore(FixedAsset::withTrashed()->findOrFail($initialAsset->getKey()));
    expect($restoredInitialAsset->trashed())->toBeFalse()
        ->and(Account::query()->findOrFail($initialAccountId)->trashed())->toBeFalse()
        ->and($purchaseIntegration->allocatedAmount($line->fresh()))->toBe('10.0000');

    $assets->delete($restoredInitialAsset);
    $replacement = $assets->create([
        ...$payload,
        'asset_name' => 'Replacement Fractional Allocation',
        'purchase_value' => '4.9999',
    ])['record'];
    $deletedAsset = FixedAsset::withTrashed()->findOrFail($initialAsset->getKey());
    $fixedAssetCountBeforeRestore = FixedAsset::withTrashed()->count();
    $accountCountBeforeRestore = Account::withTrashed()->count();
    $activityCountBeforeRestore = Activity::query()->count();

    expect(fn () => $assets->restore($deletedAsset))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.allocation_exceeds_line'));
    expect(FixedAsset::withTrashed()->findOrFail($initialAsset->getKey())->trashed())->toBeTrue()
        ->and(Account::withTrashed()->findOrFail($initialAccountId)->trashed())->toBeTrue()
        ->and(FixedAsset::withTrashed()->count())->toBe($fixedAssetCountBeforeRestore)
        ->and(Account::withTrashed()->count())->toBe($accountCountBeforeRestore)
        ->and(Activity::query()->count())->toBe($activityCountBeforeRestore)
        ->and($purchaseIntegration->allocatedAmount($line->fresh()))->toBe('10.0000')
        ->and(app(FixedAssetBookValueService::class)->position($replacement->fresh())['net_book_value'])->toBe('4.9999')
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(FixedAssetMovement::query()->count())->toBe($movementCount);

    $line->delete();
    $activityCountBeforeOrphanRestore = Activity::query()->count();
    expect(fn () => $assets->restore($deletedAsset))
        ->toThrow(DomainException::class, __('fixed_assets.purchase_source.invalid'));
    expect(FixedAsset::withTrashed()->findOrFail($initialAsset->getKey())->trashed())->toBeTrue()
        ->and(Account::withTrashed()->findOrFail($initialAccountId)->trashed())->toBeTrue()
        ->and(FixedAsset::withTrashed()->count())->toBe($fixedAssetCountBeforeRestore)
        ->and(Account::withTrashed()->count())->toBe($accountCountBeforeRestore)
        ->and(Activity::query()->count())->toBe($activityCountBeforeOrphanRestore)
        ->and(JournalEntry::query()->count())->toBe($journalCount)
        ->and(FixedAssetMovement::query()->count())->toBe($movementCount);
});
