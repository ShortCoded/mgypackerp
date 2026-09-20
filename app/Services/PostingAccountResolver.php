<?php

namespace App\Services;

use DomainException;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Models\Product;

class PostingAccountResolver
{
    public const AbnormalWasteLoss = 'abnormal_waste_loss';

    public const CostOfGoodsSold = 'cost_of_goods_sold';

    public const FinishedGoodsInventory = 'finished_goods_inventory';

    public const FactoryMaintenanceExpense = 'factory_maintenance_expense';

    public const FreightIn = 'freight_in';

    public const GoodsReceivedNotInvoiced = 'goods_received_not_invoiced';

    public const InventoryAdjustmentGain = 'inventory_adjustment_gain';

    public const InventoryAdjustmentLoss = 'inventory_adjustment_loss';

    public const OtherPurchases = 'other_purchases';

    public const OutputVatPayable = 'output_vat_payable';

    public const PackagingMaterialInventory = 'packaging_material_inventory';

    public const OperatingSuppliesPurchases = 'operating_supplies_purchases';

    public const PurchasePriceVariance = 'purchase_price_variance';

    public const ProductionServicesExpense = 'production_services_expense';

    public const QuarantineInventory = 'quarantine_inventory';

    public const RawMaterialInventory = 'raw_material_inventory';

    public const RawMaterialPurchases = 'raw_material_purchases';

    public const RecoverableVat = 'recoverable_vat';

    public const ReworkInventory = 'rework_inventory';

    public const SemiFinishedGoodsInventory = 'semi_finished_goods_inventory';

    public const SalesRevenue = 'sales_revenue';

    public const SalesReturns = 'sales_returns';

    public const ServiceRevenue = 'service_revenue';

    public const WarehouseDamageLoss = 'warehouse_damage_loss';

    public const WorkInProcessInventory = 'work_in_process_inventory';

    /** @return list<string> */
    public static function requiredClassificationCodes(): array
    {
        return [
            self::RawMaterialInventory,
            self::PackagingMaterialInventory,
            self::SemiFinishedGoodsInventory,
            self::FinishedGoodsInventory,
            self::WorkInProcessInventory,
            self::QuarantineInventory,
            self::ReworkInventory,
            self::GoodsReceivedNotInvoiced,
            self::RecoverableVat,
            self::FreightIn,
            self::ProductionServicesExpense,
            self::RawMaterialPurchases,
            self::OperatingSuppliesPurchases,
            self::OtherPurchases,
            self::PurchasePriceVariance,
            self::AbnormalWasteLoss,
            self::WarehouseDamageLoss,
            self::InventoryAdjustmentGain,
            self::InventoryAdjustmentLoss,
            self::FactoryMaintenanceExpense,
        ];
    }

    public function resolveFirst(int $companyId, string $classificationCode, string $event): Account
    {
        $classification = AccountClassification::query()
            ->active()
            ->where('code', $classificationCode)
            ->first();

        if (! $classification instanceof AccountClassification) {
            throw new DomainException(__('accounts.messages.posting_classification_missing', [
                'classification' => $classificationCode,
                'event' => $event,
            ]));
        }

        $account = Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('account_classification_id', $classification->getKey())
            ->ordered()
            ->first();

        if (! $account instanceof Account) {
            throw new DomainException(__('accounts.messages.posting_account_missing', [
                'classification' => $classification->displayName(),
                'code' => $classificationCode,
                'event' => $event,
            ]));
        }

        return $account;
    }

    public function resolve(int $companyId, string $classificationCode, string $event): Account
    {
        $classification = AccountClassification::query()
            ->active()
            ->where('code', $classificationCode)
            ->first();

        if (! $classification instanceof AccountClassification) {
            throw new DomainException(__('accounts.messages.posting_classification_missing', [
                'classification' => $classificationCode,
                'event' => $event,
            ]));
        }

        $accounts = Account::query()
            ->forCompany($companyId)
            ->eligibleForDirectPosting()
            ->where('account_classification_id', $classification->getKey())
            ->ordered()
            ->get(['id', 'company_id', 'doc_num', 'account_code', 'name', 'name_en']);

        if ($accounts->isEmpty()) {
            throw new DomainException(__('accounts.messages.posting_account_missing', [
                'classification' => $classification->displayName(),
                'code' => $classificationCode,
                'event' => $event,
            ]));
        }

        if ($accounts->count() > 1) {
            throw new DomainException(__('accounts.messages.posting_account_ambiguous', [
                'classification' => $classification->displayName(),
                'code' => $classificationCode,
                'event' => $event,
                'accounts' => $accounts->map->codeNameLabel()->implode('، '),
            ]));
        }

        return $accounts->firstOrFail();
    }

    public function inventoryForProduct(int $companyId, Product $product, string $event): Account
    {
        $classificationCode = match ($product->item_classification) {
            Product::ClassificationRawMaterial => self::RawMaterialInventory,
            Product::ClassificationPackaging, Product::ClassificationOther => self::PackagingMaterialInventory,
            Product::ClassificationSemiFinished => self::SemiFinishedGoodsInventory,
            Product::ClassificationFinishedProduct => self::FinishedGoodsInventory,
            default => throw new DomainException(__('accounts.messages.non_inventory_product', ['event' => $event])),
        };

        return $this->resolve($companyId, $classificationCode, $event);
    }

    public function purchaseDebitForProduct(int $companyId, Product $product, string $event): Account
    {
        if ($product->isService()) {
            return $this->resolve($companyId, self::ProductionServicesExpense, $event);
        }

        if ($product->cost_as_inventory) {
            return $this->inventoryForProduct($companyId, $product, $event);
        }

        $classificationCode = match ($product->item_classification) {
            Product::ClassificationRawMaterial => self::RawMaterialPurchases,
            Product::ClassificationPackaging => self::OperatingSuppliesPurchases,
            default => self::OtherPurchases,
        };

        return $this->resolve($companyId, $classificationCode, $event);
    }
}
