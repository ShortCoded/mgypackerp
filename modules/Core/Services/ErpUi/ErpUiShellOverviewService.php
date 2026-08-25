<?php

namespace Modules\Core\Services\ErpUi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Models\JournalEntry;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Company;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\Cheque;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\HR\Models\HrEmployee;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryReservation;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\Supplier;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerInvoice;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;

class ErpUiShellOverviewService
{
    /**
     * @return array{metrics: list<array{label: string, count: int}>, links: list<array{label: string, url: string}>}
     */
    public function for(ErpUiScreenDefinition $screen, Request $request): array
    {
        $companyId = (int) $request->session()->get('current_company_id') ?: null;

        return [
            'metrics' => collect($this->metricDefinitions($screen->module()))
                ->map(fn (array $metric): array => [
                    'label' => __('erp_ui_shell.overview.metrics.'.$metric['label']),
                    'count' => $this->count($metric['model'], $companyId),
                ])
                ->values()
                ->all(),
            'links' => collect($this->workflowDefinitions($screen->module()))
                ->filter(fn (array $link): bool => Route::has($link['route'])
                    && ($link['permission'] === null || $request->user()?->can($link['permission'])))
                ->map(fn (array $link): array => [
                    'label' => __('erp_ui_shell.overview.workflows.'.$link['label']),
                    'url' => route($link['route']),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function count(string $modelClass, ?int $companyId): int
    {
        $model = new $modelClass;
        $query = $modelClass::query();

        if ($companyId !== null && $model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), 'company_id')) {
            $query->where($model->qualifyColumn('company_id'), $companyId);
        }

        return $query->count();
    }

    /**
     * @return list<array{label: string, model: class-string<Model>}>
     */
    private function metricDefinitions(string $module): array
    {
        return match ($module) {
            'core' => $this->metrics([['companies', Company::class], ['branches', Branch::class], ['products', Product::class]]),
            'product_data' => $this->metrics([['products', Product::class], ['bom_lines', ProductComponent::class], ['units', ItemUnit::class]]),
            'sales' => $this->metrics([['customers', Customer::class], ['quotations', Quotation::class], ['sales_orders', SalesOrder::class], ['sales_invoices', CustomerInvoice::class], ['sales_returns', SalesReturn::class]]),
            'purchases' => $this->metrics([['suppliers', Supplier::class], ['requisitions', PurchaseRequisition::class], ['purchase_orders', PurchaseOrder::class], ['purchase_invoices', PurchaseInvoice::class], ['purchase_returns', PurchaseReturn::class]]),
            'inventory' => $this->metrics([['opening_stocks', OpeningStock::class], ['inventory_documents', InventoryDocument::class], ['inventory_movements', InventoryTransaction::class], ['reservations', InventoryReservation::class]]),
            'production', 'quality' => $this->metrics([['production_orders', ProductionOrder::class], ['production_runs', ProductionRun::class], ['machines', ProductionMachine::class], ['molds', ProductionMold::class], ['incoming_qc', GoodsReceiptInspection::class]]),
            'finance' => $this->metrics([['cashboxes', Cashbox::class], ['bank_accounts', BankAccount::class], ['cheques', Cheque::class], ['customer_receipts', CustomerReceipt::class]]),
            'fixed_assets', 'maintenance' => $this->metrics([['assets', FixedAsset::class], ['depreciation_runs', FixedAssetDepreciationRun::class], ['asset_disposals', FixedAssetDisposal::class], ['machines', ProductionMachine::class]]),
            'costing' => $this->metrics([['cost_centers', CostCenter::class], ['journal_entries', JournalEntry::class], ['products', Product::class]]),
            'hr' => $this->metrics([['employees', HrEmployee::class], ['branches', Branch::class]]),
            'reports' => $this->metrics([['journal_entries', JournalEntry::class], ['inventory_movements', InventoryTransaction::class], ['sales_orders', SalesOrder::class], ['purchase_orders', PurchaseOrder::class], ['production_orders', ProductionOrder::class]]),
            'tools' => $this->metrics([['archive_files', ArchiveFile::class], ['companies', Company::class]]),
            default => $this->metrics([['accounts', Account::class], ['journal_entries', JournalEntry::class]]),
        };
    }

    /**
     * @param  list<array{0: string, 1: class-string<Model>}>  $definitions
     * @return list<array{label: string, model: class-string<Model>}>
     */
    private function metrics(array $definitions): array
    {
        return array_map(
            fn (array $definition): array => ['label' => $definition[0], 'model' => $definition[1]],
            $definitions,
        );
    }

    /**
     * @return list<array{label: string, route: string, permission: string|null}>
     */
    private function workflowDefinitions(string $module): array
    {
        return match ($module) {
            'sales' => $this->workflows([
                ['quotations', 'admin.sales.quotations.index', 'quotations.view'],
                ['sales_orders', 'admin.sales.sales-orders.index', 'sales_orders.view'],
                ['deliveries', 'admin.sales.delivery-notes.index', 'sales_deliveries.view'],
                ['sales_invoices', 'admin.sales.sales-invoices.index', 'customer_invoices.view'],
                ['collections', 'admin.sales.customer-receipts.index', 'customer_receipts.view'],
                ['sales_returns', 'admin.sales.sales-returns.index', 'sales_returns.view'],
                ['customer_statements', 'admin.accounting.reports.customer-statement', 'reports.customer_statement.view'],
            ]),
            'purchases' => $this->workflows([
                ['requisitions', 'admin.purchases.purchase-requisitions.index', 'purchases.purchase_requisitions.view'],
                ['rfqs', 'admin.purchases.request-for-quotations.index', 'purchases.request_for_quotations.view'],
                ['supplier_quotes', 'admin.purchases.supplier-quotation-entry.index', 'purchases.supplier_quotation_entry.view'],
                ['comparison', 'admin.purchases.supplier-quotation-comparison.index', 'purchases.supplier_quotation_comparison.view'],
                ['supplier_selection', 'admin.purchases.supplier-selection.index', 'purchases.supplier_selection.view'],
                ['purchase_orders', 'admin.purchases.purchase-orders.index', 'purchase_orders.view'],
                ['goods_receipts', 'admin.purchases.goods-receipt-notes.index', 'purchases.goods_receipt_notes.view'],
                ['incoming_qc', 'admin.purchases.goods-receipt-inspection.index', 'purchases.goods_receipt_inspection.view'],
                ['purchase_invoices', 'admin.purchases.purchase-invoices.index', 'purchase_invoices.view'],
                ['supplier_payments', 'admin.purchases.supplier-payments.index', 'supplier_payments.view'],
                ['supplier_statements', 'admin.accounting.reports.supplier-statement', 'reports.supplier_statement.view'],
            ]),
            'inventory' => $this->workflows([
                ['opening_stocks', 'admin.inventory.opening-stocks.index', 'inventory.opening_stocks.view'],
                ['inventory_documents', 'admin.inventory.documents.index', 'inventory.documents.view'],
                ['stock_counts', 'admin.inventory.stock-counts.index', 'inventory.stock_counts.view'],
                ['inventory_reports', 'admin.inventory.reports.index', 'inventory.reports.operational'],
            ]),
            'production', 'quality' => $this->workflows([
                ['production_orders', 'admin.production.work-orders.index', 'production.orders.view'],
                ['production_runs', 'admin.production.runs.index', 'production.runs.view'],
                ['production_resources', 'admin.production.resources.index', 'production.resources.view'],
                ['production_reports', 'admin.production.reports.index', 'production.reports.operational'],
            ]),
            'fixed_assets', 'maintenance' => $this->workflows([
                ['asset_register', 'admin.fixed-assets.assets.index', 'fixed_assets.view'],
                ['asset_accounting', 'admin.fixed-assets.accounting.index', 'fixed_assets.accounting.view'],
                ['depreciation', 'admin.fixed-assets.depreciation.index', 'fixed_assets.depreciation.view'],
                ['asset_reports', 'admin.fixed-assets.reports.index', 'fixed_assets.reports'],
            ]),
            'finance', 'costing' => $this->workflows([
                ['accounts', 'admin.accounting.accounts.index', 'accounts.view'],
                ['journal_entries', 'admin.accounting.journal-entries.index', 'journal_entries.view'],
                ['ledger', 'admin.accounting.reports.account-ledger', 'reports.account_ledger.view'],
                ['cashboxes', 'admin.finance.cashboxes.index', 'cashboxes.view'],
                ['bank_accounts', 'admin.finance.bank-accounts.index', 'bank_accounts.view'],
                ['cheques', 'admin.finance.cheques.index', 'cheques.view'],
            ]),
            default => [],
        };
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string|null}>  $definitions
     * @return list<array{label: string, route: string, permission: string|null}>
     */
    private function workflows(array $definitions): array
    {
        return array_map(
            fn (array $definition): array => [
                'label' => $definition[0],
                'route' => $definition[1],
                'permission' => $definition[2],
            ],
            $definitions,
        );
    }
}
