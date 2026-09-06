<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemColor;
use Modules\Core\Models\ItemDecal;
use Modules\Core\Models\ItemModel;
use Modules\Core\Models\ItemOriginCountry;
use Modules\Core\Models\ItemSize;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashVoucher;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Models\OpeningBalance;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\OpeningStockPricing;
use Modules\Inventory\Models\UnpricedInventoryReceipt;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\ProjectStructureModel;
use Modules\Sales\Models\Quotation;

class ScreenDataVisibilityRegistry
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $unsupportedDefinitions = null;

    /** @var array<class-string<Model>, array<string, list<string>>>|null */
    private ?array $routePatternsByModel = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->definitions ??= [
            'customers' => $this->makeDefinition(Customer::class, 'customers', 'Sales', 'المبيعات', 'Customers', 'العملاء', 'customers', 'admin.sales.customers.*', [
                'admin.sales.select2.customers',
                'admin.sales.quotations.*',
                'admin.reports.customers.*',
                'admin.finance.select2.customers',
                'admin.finance.cash-receipt-vouchers.*',
                'admin.finance.cash-payment-vouchers.*',
                'admin.finance.cheques.*',
            ]),
            'suppliers' => $this->makeDefinition(Supplier::class, 'suppliers', 'Purchases', 'المشتريات', 'Suppliers', 'الموردون', 'suppliers', 'admin.purchases.suppliers.*', [
                'admin.purchases.select2.suppliers',
                'admin.purchases.purchase-orders.*',
                'admin.purchases.purchase-invoices.*',
                'admin.inventory.select2.unpriced-inventory-receipt-suppliers',
                'admin.inventory.unpriced-inventory-receipts.*',
                'admin.reports.suppliers.*',
                'admin.finance.select2.suppliers',
            ]),
            'products' => $this->makeDefinition(Product::class, 'products', 'Core', 'البيانات الأساسية', 'Products', 'المنتجات', 'products', 'admin.products.*', [
                'admin.sales.select2.quotation-products',
            ], conditions: [
                ['column' => 'item_classification', 'operator' => 'not_equal_or_null', 'value' => Product::ClassificationRawMaterial],
                ['column' => 'item_classification', 'operator' => 'not_equal_or_null', 'value' => Product::ClassificationPackaging],
            ]),
            'raw_materials' => $this->makeDefinition(Product::class, 'products', 'Core', 'البيانات الأساسية', 'Raw Materials', 'الخامات', 'raw_materials', 'admin.raw-materials.*', [
                'admin.select2.raw-material-products',
                'admin.select2.component-products',
            ], conditions: [['column' => 'item_classification', 'operator' => 'equal', 'value' => Product::ClassificationRawMaterial]]),
            'packaging_materials' => $this->makeDefinition(Product::class, 'products', 'Core', 'البيانات الأساسية', 'Packaging Materials', 'مواد التعبئة والتغليف', 'packaging_materials', 'admin.packaging-materials.*', conditions: [['column' => 'item_classification', 'operator' => 'equal', 'value' => Product::ClassificationPackaging]]),
            'quotations' => $this->makeDefinition(Quotation::class, 'quotations', 'Sales', 'المبيعات', 'Quotations', 'عروض الأسعار', 'quotations', 'admin.sales.quotations.*'),
            'project_structure_models' => $this->makeDefinition(ProjectStructureModel::class, 'project_structure_models', 'Sales', 'المبيعات', 'Project Structure Models', 'نماذج تكوين المشروعات', 'project_structure_models', 'admin.sales.project-structure-models.*'),
            'purchase_orders' => $this->makeDefinition(PurchaseOrder::class, 'purchase_orders', 'Purchases', 'المشتريات', 'Purchase Orders', 'أوامر الشراء', 'purchase_orders', 'admin.purchases.purchase-orders.*', branch: 'branch_id', period: 'financial_period_id'),
            'purchase_invoices' => $this->makeDefinition(PurchaseInvoice::class, 'purchase_invoices', 'Purchases', 'المشتريات', 'Purchase Invoices', 'فواتير المشتريات', 'purchase_invoices', 'admin.purchases.purchase-invoices.*', branch: 'branch_id', period: 'financial_period_id'),
            'opening_stocks' => $this->makeDefinition(OpeningStock::class, 'inventory_opening_stocks', 'Inventory', 'المخزون', 'Opening Stock', 'الأرصدة الافتتاحية للمخزون', 'inventory.opening_stocks', 'admin.inventory.opening-stocks.*', [
                'admin.inventory.select2.opening-stock-pricing-documents',
            ], branch: 'branch_id', period: 'financial_period_id'),
            'opening_stock_pricings' => $this->makeDefinition(OpeningStockPricing::class, 'inventory_opening_stock_pricings', 'Inventory', 'المخزون', 'Opening Stock Pricing', 'تسعير الأرصدة الافتتاحية', 'inventory.opening_stock_pricings', 'admin.inventory.opening-stock-pricings.*', branch: 'branch_id', period: 'financial_period_id'),
            'unpriced_inventory_receipts' => $this->makeDefinition(UnpricedInventoryReceipt::class, 'unpriced_inventory_receipts', 'Inventory', 'المخزون', 'Unpriced Receipts', 'إذون الاستلام غير المسعرة', 'inventory.unpriced_inventory_receipts', 'admin.inventory.unpriced-inventory-receipts.*', branch: 'branch_id', period: 'financial_period_id'),
            'currencies' => $this->makeDefinition(Currency::class, 'currencies', 'Finance', 'المالية', 'Currencies', 'العملات', 'currencies', 'admin.currencies.*', [
                'admin.select2.currencies',
                'admin.purchases.select2.currencies',
                'admin.inventory.select2.opening-stock-pricing-currencies',
                'admin.finance.select2.holder-currencies',
                'admin.finance.select2.cash-voucher-currencies',
                'admin.fixed-assets.select2.currencies',
            ]),
            'bank_accounts' => $this->makeDefinition(BankAccount::class, 'bank_accounts', 'Finance', 'المالية', 'Bank Accounts', 'الحسابات البنكية', 'bank_accounts', 'admin.finance.bank-accounts.*', ['admin.finance.select2.bank-accounts', 'admin.purchases.select2.bank-accounts']),
            'cashboxes' => $this->makeDefinition(Cashbox::class, 'cashboxes', 'Finance', 'المالية', 'Cashboxes', 'الخزائن', 'cashboxes', 'admin.finance.cashboxes.*', ['admin.finance.select2.cashboxes', 'admin.finance.select2.cash-voucher-cashboxes', 'admin.purchases.select2.cashboxes'], branch: 'branch_id'),
            'cash_receipt_vouchers' => $this->makeDefinition(CashVoucher::class, 'cash_vouchers', 'Finance', 'المالية', 'Cash Receipt Vouchers', 'سندات القبض النقدية', 'cash_receipt_vouchers', 'admin.finance.cash-receipt-vouchers.*', conditions: [['column' => 'voucher_type', 'operator' => 'equal', 'value' => CashVoucher::TypeReceipt]]),
            'cash_payment_vouchers' => $this->makeDefinition(CashVoucher::class, 'cash_vouchers', 'Finance', 'المالية', 'Cash Payment Vouchers', 'سندات الصرف النقدية', 'cash_payment_vouchers', 'admin.finance.cash-payment-vouchers.*', conditions: [['column' => 'voucher_type', 'operator' => 'equal', 'value' => CashVoucher::TypePayment]]),
            'cheques' => $this->makeDefinition(Cheque::class, 'cheques', 'Finance', 'المالية', 'Cheques', 'الشيكات', 'cheques', 'admin.finance.cheques.*'),
            'fund_transfers' => $this->makeDefinition(FundTransfer::class, 'fund_transfers', 'Finance', 'المالية', 'Fund Transfers', 'التحويلات المالية', 'fund_transfers', 'admin.finance.fund-transfers.*'),
            'opening_balances' => $this->makeDefinition(OpeningBalance::class, 'opening_balances', 'Finance', 'المالية', 'Opening Balances', 'الأرصدة الافتتاحية', 'opening_balances', 'admin.finance.opening-balances.*', period: 'financial_period_id'),
            'fixed_assets' => $this->makeDefinition(FixedAsset::class, 'fixed_assets', 'Fixed Assets', 'الأصول الثابتة', 'Fixed Assets Register', 'دليل الأصول الثابتة', 'fixed_assets', 'admin.fixed-assets.assets.*', branch: 'branch_id', period: 'period_id'),
            'hr_employees' => $this->makeDefinition(HrEmployee::class, 'hr_employees', 'HR', 'الموارد البشرية', 'Employees', 'الموظفون', 'hr.employees', 'admin.hr.employees.*', ['admin.hr.select2.employees'], branch: 'branch_id'),
            'branches' => $this->makeDefinition(Branch::class, 'branches', 'Core', 'البيانات الأساسية', 'Branches', 'الفروع', 'branches', 'admin.branches.*', [
                'admin.select2.branches',
                'admin.finance.select2.branches',
                'admin.fixed-assets.select2.branches',
                'admin.inventory.select2.opening-stock-pricing-branches',
                'admin.inventory.select2.unpriced-inventory-receipt-branches',
            ]),
            'financial_periods' => $this->makeDefinition(FinancialPeriod::class, 'financial_periods', 'Core', 'البيانات الأساسية', 'Financial Periods', 'الفترات المالية', 'financial_periods', 'admin.financial-periods.*', ['admin.select2.financial-periods']),
            'item_units' => $this->makeDefinition(ItemUnit::class, 'item_units', 'Core', 'بيانات الأصناف', 'Item Units', 'وحدات الأصناف', 'item_units', 'admin.item-units.*', ['admin.select2.item-units']),
            'item_sizes' => $this->makeDefinition(ItemSize::class, 'item_sizes', 'Core', 'بيانات الأصناف', 'Item Sizes', 'مقاسات الأصناف', 'item_sizes', 'admin.item-sizes.*', ['admin.select2.item-sizes']),
            'item_colors' => $this->makeDefinition(ItemColor::class, 'item_colors', 'Core', 'بيانات الأصناف', 'Item Colors', 'ألوان الأصناف', 'item_colors', 'admin.item-colors.*', ['admin.select2.item-colors']),
            'item_decals' => $this->makeDefinition(ItemDecal::class, 'item_decals', 'Core', 'بيانات الأصناف', 'Item Decals', 'ديكالات الأصناف', 'item_decals', 'admin.item-decals.*', ['admin.select2.item-decals']),
            'item_models' => $this->makeDefinition(ItemModel::class, 'item_models', 'Core', 'بيانات الأصناف', 'Item Models', 'موديلات الأصناف', 'item_models', 'admin.item-models.*', ['admin.select2.item-models']),
            'item_origin_countries' => $this->makeDefinition(ItemOriginCountry::class, 'item_origin_countries', 'Core', 'بيانات الأصناف', 'Origin Countries', 'بلدان منشأ الأصناف', 'item_origin_countries', 'admin.item-origin-countries.*', ['admin.select2.item-origin-countries']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function unsupported(): array
    {
        return $this->unsupportedDefinitions ??= [
            'accounts' => $this->unsupportedDefinition('Accounting', 'الحسابات', 'Accounts Tree', 'شجرة الحسابات', 'accounts', 'hierarchical'),
            'cost_centers' => $this->unsupportedDefinition('Accounting', 'الحسابات', 'Cost Centers Tree', 'شجرة مراكز التكلفة', 'cost_centers', 'hierarchical'),
            'item_categories' => $this->unsupportedDefinition('Core', 'البيانات الأساسية', 'Item Categories Tree', 'شجرة فئات الأصناف', 'item_categories', 'hierarchical'),
            'item_groups' => $this->unsupportedDefinition('Core', 'البيانات الأساسية', 'Item Groups Tree', 'شجرة مجموعات الأصناف', 'item_groups', 'hierarchical'),
            'production_identifiers' => $this->unsupportedDefinition('Production', 'الإنتاج', 'Production Identifiers Tree', 'شجرة معرفات الإنتاج', 'production_identifiers', 'hierarchical'),
            'project_structures' => $this->unsupportedDefinition('Sales', 'المبيعات', 'Project Structures Tree', 'شجرة تكوينات المشروعات', 'project_structures', 'hierarchical'),
        ];
    }

    /** @return array<string, mixed>|null */
    public function definition(string $screenKey): ?array
    {
        return $this->all()[$screenKey] ?? $this->unsupported()[$screenKey] ?? null;
    }

    public function isSupported(string $screenKey): bool
    {
        return (bool) ($this->all()[$screenKey]['supported'] ?? false);
    }

    /** @return list<array<string, mixed>> */
    public function selectorOptions(bool $includeUnsupported = true): array
    {
        $definitions = $includeUnsupported ? [...$this->all(), ...$this->unsupported()] : $this->all();

        return collect($definitions)
            ->map(function (array $definition, string $key): array {
                $isArabic = app()->getLocale() === 'ar';
                $module = $isArabic ? $definition['module_ar'] : $definition['module_en'];
                $title = $isArabic ? $definition['title_ar'] : $definition['title_en'];

                return [
                    'id' => $key,
                    'text' => $module.' — '.$title,
                    'module' => $module,
                    'title' => $title,
                    'permission_prefix' => $definition['permission_prefix'],
                    'route_name' => $definition['route_pattern'] ?? null,
                    'supported' => (bool) $definition['supported'],
                    'reason' => $definition['unsupported_reason'] ?? null,
                ];
            })
            ->sortBy(['module', 'text'])
            ->values()
            ->all();
    }

    /** @param class-string<Model> $modelClass */
    public function screenKeyForModelAndRoute(string $modelClass, ?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        foreach ($this->routePatternsByModel()[$modelClass] ?? [] as $screenKey => $patterns) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $routeName)) {
                    return $screenKey;
                }
            }
        }

        return null;
    }

    /**
     * @return array<class-string<Model>, array<string, list<string>>>
     */
    private function routePatternsByModel(): array
    {
        if ($this->routePatternsByModel !== null) {
            return $this->routePatternsByModel;
        }

        $index = [];

        foreach ($this->all() as $screenKey => $definition) {
            $modelClass = $definition['model'];
            $index[$modelClass][$screenKey] = $definition['route_patterns'];
        }

        return $this->routePatternsByModel = $index;
    }

    /** @return list<class-string<Model>> */
    public function modelClasses(): array
    {
        return collect($this->all())->pluck('model')->unique()->values()->all();
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $relatedRoutes
     * @param  list<array{column: string, operator: string, value: mixed}>  $conditions
     * @return array<string, mixed>
     */
    private function makeDefinition(
        string $model,
        string $table,
        string $moduleEn,
        string $moduleAr,
        string $titleEn,
        string $titleAr,
        string $permissionPrefix,
        string $routePattern,
        array $relatedRoutes = [],
        string $company = 'company_id',
        ?string $branch = null,
        ?string $period = null,
        array $conditions = [],
    ): array {
        return [
            'model' => $model,
            'table' => $table,
            'ownership_column' => 'created_by',
            'date_column' => 'created_at',
            'primary_column' => 'id',
            'public_column' => 'doc_num',
            'company_column' => $company,
            'branch_column' => $branch,
            'financial_period_column' => $period,
            'permission_prefix' => $permissionPrefix,
            'route_pattern' => $routePattern,
            'route_patterns' => [$routePattern, ...$relatedRoutes],
            'module_en' => $moduleEn,
            'module_ar' => $moduleAr,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'supported' => true,
            'flat' => true,
            'hierarchical' => false,
            'conditions' => $conditions,
            'custom_query' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function unsupportedDefinition(string $moduleEn, string $moduleAr, string $titleEn, string $titleAr, string $permissionPrefix, string $reason): array
    {
        return [
            'module_en' => $moduleEn,
            'module_ar' => $moduleAr,
            'title_en' => $titleEn,
            'title_ar' => $titleAr,
            'permission_prefix' => $permissionPrefix,
            'supported' => false,
            'flat' => false,
            'hierarchical' => true,
            'unsupported_reason' => $reason,
        ];
    }
}
