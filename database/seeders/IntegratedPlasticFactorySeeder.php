<?php

namespace Database\Seeders;

use App\Models\User;
use DomainException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\FundTransfer;
use Modules\Finance\Models\OpeningBalance;
use Modules\Finance\Services\BankAccountChartAccountService;
use Modules\Finance\Services\BankAccountService;
use Modules\Finance\Services\CashboxService;
use Modules\Finance\Services\ChequeService;
use Modules\Finance\Services\FundTransferService;
use Modules\Finance\Services\OpeningBalanceApprovalService;
use Modules\Finance\Services\OpeningBalanceService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetCategoryMapping;
use Modules\FixedAssets\Models\FixedAssetDepreciation;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\Inventory\Models\InventoryAccountingMapping;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\InventoryAccountingMappingService;
use Modules\Inventory\Services\InventoryGlReconciliationService;
use Modules\Inventory\Services\InventoryMovementService;
use Modules\Inventory\Services\InventoryPositionReconciliationService;
use Modules\Inventory\Services\OpeningStockPricingService;
use Modules\Inventory\Services\OpeningStockService;
use Modules\Inventory\Services\StockCountService;
use Modules\Production\Models\ProductionMachine;
use Modules\Production\Models\ProductionMold;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionRun;
use Modules\Production\Models\ProductionShift;
use Modules\Production\Models\QualityInspectionType;
use Modules\Production\Services\ProductionCycleService;
use Modules\Production\Services\SalesProductionDemandService;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseOrderChangeRequest;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\PurchaseReturn;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\SupplierService;
use Modules\Sales\Models\Customer;
use Modules\Sales\Models\CustomerCommercialAgreement;
use Modules\Sales\Models\CustomerReceipt;
use Modules\Sales\Models\Quotation;
use Modules\Sales\Models\QuotationPaymentMilestone;
use Modules\Sales\Models\SalesReturn;
use Modules\Sales\Services\CustomerInvoiceService;
use Modules\Sales\Services\CustomerReceiptService;
use Modules\Sales\Services\CustomerService;
use Modules\Sales\Services\QuotationService;
use Modules\Sales\Services\SalesFulfillmentService;
use Modules\Sales\Services\SalesOrderService;
use Modules\Sales\Services\SalesReturnService;

class IntegratedPlasticFactorySeeder extends Seeder
{
    public const Marker = 'RuntimeDemoDataSeeder';

    public const CompanyName = 'Mgy Plast Manufacturing - Runtime Demo';

    public const FactoryBranchName = '10th of Ramadan Plastic Factory - Runtime Demo';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $company = Company::query()->where('name', self::CompanyName)->firstOrFail();
        $branch = Branch::query()
            ->where('company_id', $company->getKey())
            ->where('name', self::FactoryBranchName)
            ->firstOrFail();
        $period = FinancialPeriod::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'FY 2026 - Runtime Demo')
            ->firstOrFail();
        $user = User::query()->where('email', 'demo.full@shortcoded.test')->firstOrFail();
        $previousUser = Auth::user();

        Auth::login($user);
        request()->setUserResolver(fn (): User => $user);
        $this->activateContext($company, $branch, $period);

        try {
            DB::transaction(function () use ($branch, $company, $period, $user): void {
                $resources = $this->seedInfrastructure($company, $branch, $user);
                $this->seedExpandedCatalog($company, $user);
                $partners = $this->seedBusinessPartners($company, $resources['egp']);
                $this->seedProductionResources($company, $branch, $resources['hall'], $user);

                if (! $this->salesLinkedOpeningInventoryExists($company)) {
                    $this->seedSalesLinkedOpeningInventory($branch, $resources);
                }

                if (! $this->goldenCyclesExist($company)) {
                    $this->seedOpeningInventory($branch, $resources);
                    $this->seedFixedAssets($branch, $period, $resources);
                    $this->seedOpeningGeneralLedger($period, $resources);
                    $this->seedProcurementCycle($period, $resources, $partners);
                    $this->seedProductionCycle($period, $resources);
                    $this->seedSalesCycle($period, $resources, $partners);
                    $this->seedFundTransfer($resources);
                }

                if (! $this->splitProcurementCycleExists($company)) {
                    $this->seedSplitProcurementCycle($period, $resources, $partners);
                }

                $this->repairDemoPurchaseReturnBatch($company);

                if (! $this->additionalProductionScenariosExist($company)) {
                    $this->seedAdditionalProductionScenarios($period, $resources);
                }

                $this->seedProductionLinkedRequisition($resources);

                if (! $this->additionalInventoryMovementsExist($company)) {
                    $this->seedAdditionalInventoryMovements($period, $resources);
                }

                $this->seedAdditionalAssets($branch, $period, $resources);
                $this->seedFixedAssetLifecycle($branch, $period, $resources);
                $this->seedPurchaseOrderLifecycleStates($resources, $partners);

                if (! $this->salesLinkedProductionCycleExists($company)) {
                    $this->seedSalesLinkedProductionCycle($period, $resources, $partners);
                }

                if (! $this->fullySettledCustomerCycleExists($company)) {
                    $this->seedFullySettledCustomerCycle($period, $resources, $partners);
                }

                $this->repairSalesDeliveryBatchPositions($period, $resources);
                $this->seedInventoryGlReconciliation($period, $resources);

            }, 3);
        } finally {
            if ($previousUser instanceof User) {
                Auth::login($previousUser);
                request()->setUserResolver(fn (): User => $previousUser);
            } else {
                Auth::logout();
                request()->setUserResolver(fn (): ?User => null);
            }
        }

        $this->command?->info('Integrated plastic factory business cycles are ready.');
    }

    /** @return array<string, mixed> */
    private function seedInfrastructure(Company $company, Branch $branch, User $user): array
    {
        $finishedStore = $this->store($branch, 'Finished Goods Warehouse - Runtime Demo', 1, $user);
        $rawStore = $this->store($branch, 'Raw Materials Warehouse - Runtime Demo', 2, $user);
        $quarantineStore = $this->store($branch, 'Quality Quarantine Warehouse - Runtime Demo', 3, $user);
        $packingStore = $this->store($branch, 'Packaging Materials Warehouse - Runtime Demo', 4, $user);
        $hall = BranchHall::query()->firstOrCreate(
            ['branch_id' => $branch->getKey(), 'name' => 'Injection Molding Hall - Runtime Demo'],
            ['position' => 1, 'created_by' => $user->getKey()],
        );
        BranchHall::query()->firstOrCreate(
            ['branch_id' => $branch->getKey(), 'name' => 'Packing Hall - Runtime Demo'],
            ['position' => 2, 'created_by' => $user->getKey()],
        );
        BranchHall::query()->firstOrCreate(
            ['branch_id' => $branch->getKey(), 'name' => 'Finished Goods Dispatch Hall - Runtime Demo'],
            ['position' => 3, 'created_by' => $user->getKey()],
        );

        foreach ([
            [$finishedStore, 'FG-A01', 'Finished Goods Dispatch Zone'],
            [$finishedStore, 'FG-AVAILABLE', 'Finished Goods Available Zone'],
            [$rawStore, 'RM-A01', 'Virgin Resin Storage Zone'],
            [$rawStore, 'RM-RECEIVING', 'Raw Materials Receiving Zone'],
            [$rawStore, 'RM-STAGING', 'Production Staging Zone'],
            [$quarantineStore, 'QC-A01', 'Quarantine Hold Zone'],
            [$quarantineStore, 'QC-QUARANTINE', 'Supplier Return Quarantine Zone'],
            [$quarantineStore, 'QC-DAMAGED', 'Damaged Materials Zone'],
            [$quarantineStore, 'QC-SCRAP', 'Recoverable Scrap Zone'],
            [$packingStore, 'PK-A01', 'Packaging Material Picking Zone'],
        ] as [$store, $code, $name]) {
            WarehouseLocation::query()->firstOrCreate(
                ['branch_store_id' => $store->getKey(), 'code' => $code],
                ['name' => $name, 'zone_code' => Str::before($code, '-'), 'position' => 1, 'is_active' => true, 'created_by' => $user->getKey()],
            );
        }

        $productionRoot = CostCenter::query()
            ->where('company_id', $company->getKey())
            ->where('cost_center_code', CostCenter::RootProductionCode)
            ->firstOrFail();
        $serviceRoot = CostCenter::query()
            ->where('company_id', $company->getKey())
            ->where('cost_center_code', CostCenter::RootServiceCode)
            ->firstOrFail();
        $injectionCostCenter = $this->costCenter($company, $productionRoot, '1001', 'Injection Molding', $user);
        $this->costCenter($company, $productionRoot, '1002', 'Packing and Finishing', $user);
        $this->costCenter($company, $serviceRoot, '2001', 'Administration', $user);
        $this->costCenter($company, $serviceRoot, '2002', 'Warehouse Operations', $user);
        $this->costCenter($company, $serviceRoot, '2003', 'Sales and Distribution', $user);
        $this->costCenter($company, $serviceRoot, '2004', 'Maintenance', $user);
        $this->costCenter($company, $serviceRoot, '2005', 'Procurement', $user);

        $egp = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();
        $usd = Currency::query()->firstOrNew(['company_id' => $company->getKey(), 'code' => 'USD']);
        if (! $usd->exists) {
            $usd->forceFill(app(DocumentNumberService::class)->nextForCompany('currencies', Currency::class, $company->getKey()));
        }
        $usd->forceFill([
            'name' => 'US Dollar', 'minor_unit_name' => 'Cent', 'minor_unit_factor' => 100,
            'is_main' => false, 'status' => 'active',
            'notes' => $this->note('Foreign currency for export, bank, and reporting screens.'),
        ])->save();

        $cashbox = Cashbox::query()->where('company_id', $company->getKey())->where('name', 'Factory Main Cashbox - Runtime Demo')->first();
        if (! $cashbox instanceof Cashbox) {
            $cashbox = app(CashboxService::class)->create([
                'name' => 'Factory Main Cashbox - Runtime Demo',
                'branch_doc_num' => $branch->doc_num,
                'currency_doc_nums' => [$egp->doc_num, $usd->doc_num],
                'status' => 'active',
                'notes' => $this->note('Primary cash collection and supplier-payment cashbox.'),
            ])['record'];
        }

        $bankGroup = Account::query()->where('company_id', $company->getKey())->where('name', 'Commercial Banks - Runtime Demo')->first();
        if (! $bankGroup instanceof Account) {
            $bankGroup = app(BankAccountChartAccountService::class)->createBankAccount(
                'Commercial Banks - Runtime Demo',
                $this->note('Selectable bank group for demo current accounts.'),
            );
        }
        $bankAccount = BankAccount::query()->where('company_id', $company->getKey())->where('account_number', 'MGY-EGP-2026-001')->first();
        if (! $bankAccount instanceof BankAccount) {
            $bankAccount = app(BankAccountService::class)->create([
                'bank_doc_num' => $bankGroup->doc_num,
                'currency_doc_num' => $egp->doc_num,
                'account_name' => 'Banque Misr Factory Current Account - Runtime Demo',
                'account_number' => 'MGY-EGP-2026-001',
                'iban' => 'EG380002000156789012345678901',
                'swift_code' => 'BMISEGCX',
                'owner_name' => $company->legal_name,
                'bank_branch_name' => '10th of Ramadan Industrial Zone',
                'status' => 'active',
                'notes' => $this->note('Primary EGP operating bank account.'),
            ])['record'];
        }
        if (! BankAccount::query()->where('company_id', $company->getKey())->where('account_number', 'MGY-EGP-2026-002')->exists()) {
            app(BankAccountService::class)->create([
                'bank_doc_num' => $bankGroup->doc_num,
                'currency_doc_num' => $egp->doc_num,
                'account_name' => 'Commercial International Bank Collections - Runtime Demo',
                'account_number' => 'MGY-EGP-2026-002',
                'iban' => 'EG660010000156789012345678902',
                'swift_code' => 'CIBEEGCX',
                'owner_name' => $company->legal_name,
                'bank_branch_name' => '10th of Ramadan City',
                'status' => 'active',
                'notes' => $this->note('Secondary collection and supplier-transfer bank account.'),
            ]);
        }

        app(InventoryAccountingMappingService::class)->save($company->getKey(), [
            'raw_material_inventory_account_doc_num' => $this->postingAccount($company, '1131')->doc_num,
            'packaging_inventory_account_doc_num' => $this->postingAccount($company, '1134')->doc_num,
            'semi_finished_inventory_account_doc_num' => $this->postingAccount($company, '1132')->doc_num,
            'finished_goods_inventory_account_doc_num' => $this->postingAccount($company, '1133')->doc_num,
            'wip_account_doc_num' => $this->postingAccount($company, '1132')->doc_num,
            'production_waste_account_doc_num' => $this->postingAccount($company, '551')->doc_num,
            'recoverable_scrap_inventory_account_doc_num' => $this->postingAccount($company, '1134')->doc_num,
            'warehouse_damage_loss_account_doc_num' => $this->postingAccount($company, '551')->doc_num,
            'inventory_adjustment_gain_account_doc_num' => $this->postingAccount($company, '432')->doc_num,
            'inventory_adjustment_loss_account_doc_num' => $this->postingAccount($company, '551')->doc_num,
            'production_variance_account_doc_num' => $this->postingAccount($company, '551')->doc_num,
            'quarantine_inventory_account_doc_num' => $this->postingAccount($company, '1134')->doc_num,
            'rework_inventory_account_doc_num' => $this->postingAccount($company, '1132')->doc_num,
            'grni_account_doc_num' => $this->postingAccount($company, '212')->doc_num,
            'purchase_price_variance_account_doc_num' => $this->postingAccount($company, '551')->doc_num,
            'production_cost_center_doc_num' => $injectionCostCenter->doc_num,
        ]);

        return [
            'company' => $company, 'branch' => $branch, 'user' => $user,
            'finished_store' => $finishedStore, 'raw_store' => $rawStore, 'quarantine_store' => $quarantineStore,
            'hall' => $hall, 'injection_cost_center' => $injectionCostCenter,
            'egp' => $egp, 'usd' => $usd, 'cashbox' => $cashbox, 'bank_account' => $bankAccount,
        ];
    }

    /** @return array<string, mixed> */
    private function seedBusinessPartners(Company $company, Currency $egp): array
    {
        $customers = collect([
            ['Cairo Paints & Coatings - Runtime Demo', 'Ahmed Youssef', '+20 100 800 1001', '500000', '30'],
            ['Nile Foods Processing - Runtime Demo', 'Mariam Fathy', '+20 100 800 1002', '350000', '45'],
            ['Al Shorouk Packaging Retail - Runtime Demo', 'Omar Adel', '+20 100 800 1003', '150000', '15'],
            ['Alexandria Marine Coatings - Runtime Demo', 'Salma Nabil', '+20 100 800 1004', '275000', '30'],
            ['Delta Dairy Packaging - Runtime Demo', 'Mostafa Helmy', '+20 100 800 1005', '420000', '45'],
            ['October Chemical Industries - Runtime Demo', 'Heba Said', '+20 100 800 1006', '600000', '60'],
            ['Canal Household Supplies - Runtime Demo', 'Tarek Emad', '+20 100 800 1007', '90000', '15'],
            ['Cash Counter Customer - Runtime Demo', 'Factory Cash Desk', '+20 100 800 1008', '0', '0'],
            ['Delta Lubricants Filling - Runtime Demo', 'Salma Nabil', '+20 100 800 1004', '425000', '30'],
            ['Alexandria Chemicals Export - Runtime Demo', 'Tamer Ashraf', '+20 100 800 1005', '600000', '60'],
            ['Giza Homeware Industries - Runtime Demo', 'Nouran Magdy', '+20 100 800 1009', '175000', '30'],
        ])->map(function (array $definition) use ($company, $egp): Customer {
            [$name, $contact, $mobile, $limit, $terms] = $definition;
            $customer = Customer::query()->where('company_id', $company->getKey())->where('name', $name)->first();
            if (! $customer instanceof Customer) {
                $customer = app(CustomerService::class)->create([
                    'name' => $name, 'contact_person' => $contact, 'mobile' => $mobile,
                    'email' => Str::slug($name, '.').'@example.test',
                    'tax_number' => 'CUST-'.Str::upper(Str::substr(md5($name), 0, 9)),
                    'address' => 'Greater Cairo Industrial Area, Egypt', 'status' => 'active',
                    'credit_limits' => [[
                        'currency_doc_num' => $egp->doc_num, 'credit_limit' => $limit,
                        'notes' => $this->note('Approved runtime demo credit limit.'),
                    ]],
                    'notes' => $this->note('Active plastics customer with an automatically linked receivable account.'),
                ])['record'];
            }
            CustomerCommercialAgreement::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'customer_id' => $customer->getKey(), 'currency_id' => $egp->getKey()],
                [
                    'customer_type' => CustomerCommercialAgreement::TypeCredit, 'credit_limit' => $limit,
                    'include_open_orders' => true, 'required_advance_percentage' => 0, 'required_advance_minimum' => 0,
                    'blocking_enabled' => true, 'temporary_override_allowed' => true,
                    'effective_from' => '2026-01-01', 'status' => 'active',
                    'notes' => $this->note("{$terms}-day commercial terms for manual sales testing."),
                ],
            );

            return $customer;
        });

        $suppliers = collect([
            ['Egypt Polymers Supply - Runtime Demo', 'Hossam Tarek', '+20 101 900 2001', 30],
            ['Suez Petrochem Trading - Runtime Demo', 'Dina Samir', '+20 101 900 2002', 45],
            ['Delta Carton Industries - Runtime Demo', 'Karim Essam', '+20 101 900 2003', 30],
            ['ColorChem Masterbatch Egypt - Runtime Demo', 'Rana Adel', '+20 101 900 2004', 30],
            ['Canal Labels and Adhesives - Runtime Demo', 'Yasser Kamel', '+20 101 900 2005', 45],
            ['Nile Industrial Maintenance - Runtime Demo', 'Amr Sameh', '+20 101 900 2006', 15],
            ['Cairo Logistics Services - Runtime Demo', 'Mona Fawzy', '+20 101 900 2007', 30],
            ['Cairo Masterbatch Industries - Runtime Demo', 'Rania Mostafa', '+20 101 900 2004', 30],
            ['Nile Flexible Packaging - Runtime Demo', 'Wael Sobhy', '+20 101 900 2005', 15],
            ['LabelTech Egypt - Runtime Demo', 'Heba Fawzy', '+20 101 900 2006', 30],
            ['Industrial Maintenance Solutions - Runtime Demo', 'Mahmoud Raouf', '+20 101 900 2007', 0],
            ['East Delta Transport Services - Runtime Demo', 'Yasser Gamal', '+20 101 900 2008', 45],
        ])->map(function (array $definition) use ($company, $egp): Supplier {
            [$name, $contact, $mobile, $terms] = $definition;
            $supplier = Supplier::query()->where('company_id', $company->getKey())->where('name', $name)->first();
            if (! $supplier instanceof Supplier) {
                $supplier = app(SupplierService::class)->create([
                    'name' => $name, 'contact_person' => $contact, 'mobile' => $mobile,
                    'email' => Str::slug($name, '.').'@example.test',
                    'tax_number' => 'SUP-'.Str::upper(Str::substr(md5($name), 0, 9)),
                    'payment_terms_days' => $terms, 'address' => 'Egyptian Industrial Supply Zone', 'status' => 'active',
                    'credit_limits' => [[
                        'currency_doc_num' => $egp->doc_num, 'credit_limit' => '750000',
                        'notes' => $this->note('Approved runtime demo supplier credit limit.'),
                    ]],
                    'notes' => $this->note('Approved material supplier with an automatically linked payable account.'),
                ])['record'];
            }

            return $supplier;
        });

        return [
            'primary_customer' => $customers->get(0), 'food_customer' => $customers->get(1), 'retail_customer' => $customers->get(2),
            'production_customer' => $customers->get(5),
            'settled_customer' => $customers->get(10),
            'primary_supplier' => $suppliers->get(0), 'alternate_supplier' => $suppliers->get(1), 'carton_supplier' => $suppliers->get(2),
            'masterbatch_supplier' => $suppliers->get(3), 'packaging_supplier' => $suppliers->get(4),
            'label_supplier' => $suppliers->get(9), 'maintenance_supplier' => $suppliers->get(10),
            'transport_supplier' => $suppliers->get(11),
        ];
    }

    private function seedExpandedCatalog(Company $company, User $user): void
    {
        foreach (['Gram', 'Bag', 'Roll', 'Pallet'] as $name) {
            $unit = ItemUnit::query()->where('company_id', $company->getKey())->where('name', $name)->first();
            if (! $unit instanceof ItemUnit) {
                ItemUnit::query()->create([
                    ...app(DocumentNumberService::class)->nextForCompany('item_units', ItemUnit::class, (int) $company->getKey()),
                    'company_id' => $company->getKey(),
                    'name' => $name,
                    'status' => 'active',
                    'notes' => $this->note('Plastic-factory purchasing, packing, and conversion unit.'),
                    'created_by' => $user->getKey(),
                ]);
            }
        }

        $definitions = [
            ['MGY-RM-PE-HD-001', 'HDPE Blow Molding Grade - Runtime Demo', Product::ClassificationRawMaterial, 'MGY-RM-PP-HOMO-001', 'Kilogram'],
            ['MGY-RM-MB-BLK-001', 'Black Color Masterbatch - Runtime Demo', Product::ClassificationRawMaterial, 'MGY-RM-MB-BLUE-001', 'Kilogram'],
            ['MGY-RM-MB-WHT-001', 'White Color Masterbatch - Runtime Demo', Product::ClassificationRawMaterial, 'MGY-RM-MB-BLUE-001', 'Kilogram'],
            ['MGY-RM-MB-RED-001', 'Red Color Masterbatch - Runtime Demo', Product::ClassificationRawMaterial, 'MGY-RM-MB-BLUE-001', 'Kilogram'],
            ['MGY-PK-BAG-PRINT-001', 'Printed Product Bag - Runtime Demo', Product::ClassificationPackaging, 'MGY-PK-CARTON-PAIL-24', 'Bag'],
            ['MGY-PK-LABEL-001', 'Food-Grade Product Label - Runtime Demo', Product::ClassificationPackaging, 'MGY-PK-CARTON-PAIL-24', 'Piece'],
            ['MGY-PK-TAPE-001', 'Packing Tape Roll - Runtime Demo', Product::ClassificationPackaging, 'MGY-PK-CARTON-PAIL-24', 'Roll'],
            ['MGY-PK-WRAP-001', 'Pallet Stretch Wrapper - Runtime Demo', Product::ClassificationPackaging, 'MGY-PK-CARTON-PAIL-24', 'Roll'],
            ['MGY-FG-CRATE-45L-BLK', '45L Black Industrial Crate - Runtime Demo', Product::ClassificationFinishedProduct, 'MGY-FG-PAIL-20L-BLU', 'Piece'],
            ['MGY-FG-CAP-WHT-001', 'White Tamper-Evident Cap - Runtime Demo', Product::ClassificationFinishedProduct, 'MGY-FG-PAIL-20L-BLU', 'Piece'],
            ['MGY-FG-FOOD-KIT-12', '12-Piece Food Container Pack - Runtime Demo', Product::ClassificationFinishedProduct, 'MGY-FG-FOOD-5L-CLR', 'Carton'],
            ['MGY-SVC-MAINTENANCE', 'Injection Machine Preventive Maintenance - Runtime Demo', Product::ClassificationService, 'MGY-SVC-DELIVERY', 'Piece'],
            ['MGY-SVC-QUALITY', 'External Polymer Quality Testing - Runtime Demo', Product::ClassificationService, 'MGY-SVC-DELIVERY', 'Piece'],
            ['MGY-SVC-INBOUND-FREIGHT', 'Inbound Material Freight Service - Runtime Demo', Product::ClassificationService, 'MGY-SVC-DELIVERY', 'Piece'],
            ['MGY-SVC-LABEL-SETUP', 'Label Artwork and Print Setup - Runtime Demo', Product::ClassificationService, 'MGY-SVC-DELIVERY', 'Piece'],
        ];

        foreach ($definitions as [$barcode, $name, $classification, $referenceBarcode, $unitName]) {
            $reference = $this->product($company, $referenceBarcode);
            $unit = ItemUnit::query()->where('company_id', $company->getKey())->where('name', $unitName)->firstOrFail();
            $product = Product::withTrashed()->where('company_id', $company->getKey())->where('barcode', $barcode)->first() ?? new Product;
            if (! $product->exists) {
                $product->forceFill(app(DocumentNumberService::class)->nextForCompany('products', Product::class, (int) $company->getKey()));
            }
            $product->forceFill([
                'company_id' => $company->getKey(),
                'name' => $name,
                'barcode' => $barcode,
                'item_classification' => $classification,
                'reorder_point' => $classification === Product::ClassificationService ? null : '250.0000',
                'item_unit_id' => $unit->getKey(),
                'item_size_id' => $reference->item_size_id,
                'item_color_id' => $reference->item_color_id,
                'item_model_id' => $reference->item_model_id,
                'item_origin_country_id' => $reference->item_origin_country_id,
                'item_category_id' => $reference->item_category_id,
                'item_group_id' => $reference->item_group_id,
                'cost_as_inventory' => $classification !== Product::ClassificationService,
                'is_displayable' => true,
                'status' => 'active',
                'notes' => $this->note('Expanded realistic master catalog for manual ERP workflows.'),
                'created_by' => $product->created_by ?: $user->getKey(),
                'updated_by' => $user->getKey(),
            ])->save();
            if ($product->trashed()) {
                $product->restore();
            }
        }

        $this->seedBom($company, 'MGY-FG-CRATE-45L-BLK', [
            ['MGY-RM-PP-HOMO-001', '1.80000000', ProductComponent::CalculationDirect],
            ['MGY-RM-MB-BLK-001', '0.05000000', ProductComponent::CalculationPercentage, '2.77777778'],
            ['MGY-PK-LABEL-001', '1.00000000', ProductComponent::CalculationCount],
            ['MGY-PK-WRAP-001', '0.02000000', ProductComponent::CalculationQuantity],
        ], $user);
        $this->seedBom($company, 'MGY-FG-CAP-WHT-001', [
            ['MGY-RM-PE-HD-001', '0.05500000', ProductComponent::CalculationDirect],
            ['MGY-RM-MB-WHT-001', '0.00150000', ProductComponent::CalculationPercentage, '2.72727273'],
        ], $user);
        $this->seedBom($company, 'MGY-FG-FOOD-KIT-12', [
            ['MGY-FG-FOOD-5L-CLR', '12.00000000', ProductComponent::CalculationCount],
            ['MGY-PK-BAG-PRINT-001', '1.00000000', ProductComponent::CalculationCount],
            ['MGY-PK-LABEL-001', '1.00000000', ProductComponent::CalculationCount],
        ], $user);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3?: string}> $components */
    private function seedBom(Company $company, string $productBarcode, array $components, User $user): void
    {
        $product = $this->product($company, $productBarcode);
        $referenceComponent = null;
        foreach ($components as $definition) {
            [$componentBarcode, $quantity, $method] = $definition;
            $componentProduct = $this->product($company, $componentBarcode);
            $component = ProductComponent::withTrashed()
                ->where('product_id', $product->getKey())
                ->where('component_product_id', $componentProduct->getKey())
                ->first() ?? new ProductComponent;
            $component->forceFill([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'component_product_id' => $componentProduct->getKey(),
                'unit_id' => $componentProduct->item_unit_id,
                'calculation_method' => $method,
                'quantity' => $quantity,
                'percentage' => $definition[3] ?? null,
                'reference_component_id' => $method === ProductComponent::CalculationPercentage ? $referenceComponent?->getKey() : null,
                'notes' => $this->note('Production-ready plastic product BOM component.'),
                'created_by' => $component->created_by ?: $user->getKey(),
                'updated_by' => $user->getKey(),
            ])->save();
            if ($component->trashed()) {
                $component->restore();
            }
            $referenceComponent ??= $component;
        }
    }

    private function seedProductionResources(Company $company, Branch $branch, BranchHall $hall, User $user): void
    {
        $finished = $this->product($company, 'MGY-FG-PAIL-20L-BLU');
        $machine = ProductionMachine::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'IMM-450-01'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => '450 Ton Injection Molding Machine - Runtime Demo',
                'status' => ProductionMachine::StatusAvailable,
                'notes' => $this->note('Primary injection machine for the golden production run.'), 'created_by' => $user->getKey(),
            ],
        );
        $mold = ProductionMold::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'MOLD-PAIL-20L-01'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => '20L Pail and Lid Mold - Runtime Demo', 'status' => ProductionMold::StatusAvailable,
                'notes' => $this->note('Approved mold for the primary finished product.'), 'created_by' => $user->getKey(),
            ],
        );
        $secondaryMachine = ProductionMachine::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'IMM-250-02'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => '250 Ton Injection Molding Machine - Runtime Demo',
                'status' => ProductionMachine::StatusAvailable,
                'notes' => $this->note('Medium press for food containers and closures.'), 'created_by' => $user->getKey(),
            ],
        );
        $packingMachine = ProductionMachine::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'PACK-01'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => 'Automatic Packing and Labeling Line - Runtime Demo',
                'status' => ProductionMachine::StatusAvailable,
                'notes' => $this->note('Packing line for cartons, printed bags, and labels.'), 'created_by' => $user->getKey(),
            ],
        );
        $foodMold = ProductionMold::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'MOLD-FOOD-5L-01'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => '5L Food Container Mold - Runtime Demo', 'status' => ProductionMold::StatusAvailable,
                'notes' => $this->note('Food-contact container mold for the medium press.'), 'created_by' => $user->getKey(),
            ],
        );
        $capMold = ProductionMold::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'MOLD-CAP-01'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => 'Tamper-Evident Cap Mold - Runtime Demo', 'status' => ProductionMold::StatusAvailable,
                'notes' => $this->note('Compatible closure mold with an intentionally limited press assignment.'), 'created_by' => $user->getKey(),
            ],
        );
        $crateMold = ProductionMold::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'MOLD-CRATE-45L-01'],
            [
                'branch_id' => $branch->getKey(), 'branch_hall_id' => $hall->getKey(),
                'name' => '45L Industrial Crate Mold - Runtime Demo', 'status' => ProductionMold::StatusAvailable,
                'notes' => $this->note('Dedicated crate mold for sales-linked production demand.'), 'created_by' => $user->getKey(),
            ],
        );
        $machine->molds()->syncWithoutDetaching([$mold->getKey(), $crateMold->getKey()]);
        $mold->products()->syncWithoutDetaching([$finished->getKey()]);
        $crateMold->products()->syncWithoutDetaching([$this->product($company, 'MGY-FG-CRATE-45L-BLK')->getKey()]);
        $secondaryMachine->molds()->syncWithoutDetaching([$foodMold->getKey(), $capMold->getKey()]);
        $foodMold->products()->syncWithoutDetaching([$this->product($company, 'MGY-FG-FOOD-5L-CLR')->getKey()]);
        $capMold->products()->syncWithoutDetaching([$this->product($company, 'MGY-FG-CAP-WHT-001')->getKey()]);

        unset($packingMachine);
        ProductionShift::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'branch_id' => $branch->getKey(), 'code' => 'SHIFT-A'],
            ['name' => 'Morning Shift', 'starts_at' => '07:00:00', 'ends_at' => '15:00:00', 'is_active' => true],
        );

        $inProcess = QualityInspectionType::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'IN-PROCESS-PLASTIC'],
            ['name' => 'In-Process Plastic Quality', 'is_final_production' => false, 'is_active' => true],
        );
        $final = QualityInspectionType::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'code' => 'FINAL-PLASTIC-RELEASE'],
            ['name' => 'Final Finished Goods Release', 'is_final_production' => true, 'is_active' => true],
        );
        foreach ([
            [$inProcess, 'WEIGHT', 'Part Weight Within Tolerance', 'numeric'],
            [$inProcess, 'COLOR', 'Color Match and Dispersion', 'pass_fail'],
            [$final, 'LEAK', 'Leak and Handle Load Test', 'pass_fail'],
            [$final, 'VISUAL', 'Final Visual Inspection', 'pass_fail'],
        ] as $sequence => [$type, $code, $name, $responseType]) {
            $exists = DB::table('quality_checkpoints')
                ->where('company_id', $company->getKey())
                ->where('code', $code)
                ->exists();
            if (! $exists) {
                DB::table('quality_checkpoints')->insert([
                    'public_id' => (string) Str::uuid(), 'company_id' => $company->getKey(),
                    'quality_inspection_type_id' => $type->getKey(), 'code' => $code, 'name' => $name,
                    'sequence' => $sequence + 1, 'response_type' => $responseType,
                    'is_required' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** @param array<string, mixed> $resources */
    private function seedOpeningInventory(Branch $branch, array $resources): void
    {
        $this->seedOpeningStockDocument($branch, $resources['raw_store'], $resources['egp'], [
            ['barcode' => 'MGY-RM-PP-HOMO-001', 'quantity' => '8000', 'unit_price' => '45', 'batch_lot' => 'OPEN-PP-260101'],
            ['barcode' => 'MGY-RM-MB-BLUE-001', 'quantity' => '300', 'unit_price' => '95', 'batch_lot' => 'OPEN-MB-260101'],
            ['barcode' => 'MGY-PK-CARTON-PAIL-24', 'quantity' => '2000', 'unit_price' => '12', 'batch_lot' => 'OPEN-CT-260101'],
        ], 'Raw materials and packaging opening stock.');
        $this->seedOpeningStockDocument($branch, $resources['finished_store'], $resources['egp'], [
            ['barcode' => 'MGY-FG-PAIL-20L-BLU', 'quantity' => '1000', 'unit_price' => '32', 'batch_lot' => null],
            ['barcode' => 'MGY-FG-FOOD-5L-CLR', 'quantity' => '500', 'unit_price' => '11', 'batch_lot' => null],
        ], 'Finished goods opening stock.');
    }

    /** @param array<string, mixed> $resources */
    private function seedSalesLinkedOpeningInventory(Branch $branch, array $resources): void
    {
        $this->seedOpeningStockDocument($branch, $resources['finished_store'], $resources['egp'], [
            ['barcode' => 'MGY-FG-CRATE-45L-BLK', 'quantity' => '30', 'unit_price' => '68', 'batch_lot' => 'OPEN-CRATE-260101'],
        ], 'Exact 30-piece opening stock for the sales-linked production scenario.');
        $this->seedOpeningStockDocument($branch, $resources['raw_store'], $resources['egp'], [
            ['barcode' => 'MGY-RM-MB-BLK-001', 'quantity' => '10', 'unit_price' => '102', 'batch_lot' => 'OPEN-MB-BLK-260101'],
            ['barcode' => 'MGY-PK-LABEL-001', 'quantity' => '200', 'unit_price' => '1.25', 'batch_lot' => 'OPEN-LABEL-260101'],
            ['barcode' => 'MGY-PK-WRAP-001', 'quantity' => '10', 'unit_price' => '80', 'batch_lot' => 'OPEN-WRAP-260101'],
        ], 'Components dedicated to the exact sales-linked production scenario.');
    }

    private function salesLinkedOpeningInventoryExists(Company $company): bool
    {
        return OpeningStock::query()
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', '%Exact 30-piece opening stock for the sales-linked production scenario%')
            ->exists();
    }

    /**
     * @param  list<array{barcode: string, quantity: string, unit_price: string, batch_lot: string|null}>  $definitions
     */
    private function seedOpeningStockDocument(
        Branch $branch,
        BranchStore $store,
        Currency $currency,
        array $definitions,
        string $description,
    ): void {
        $opening = app(OpeningStockService::class)->create([
            'document_date' => '2026-01-01',
            'branch_store_uuid' => $store->public_uuid,
            'notes' => $this->note($description),
            'lines' => collect($definitions)->map(function (array $definition) use ($branch): array {
                $product = Product::query()
                    ->where('company_id', $branch->company_id)
                    ->where('barcode', $definition['barcode'])
                    ->firstOrFail();

                return [
                    'product_doc_num' => $product->doc_num,
                    'quantity' => $definition['quantity'],
                    'stock_status' => InventoryTransaction::StatusAvailable,
                    'batch_lot' => $definition['batch_lot'],
                    'manufacture_date' => '2025-12-15',
                    'notes' => $this->note('Priced opening inventory layer.'),
                ];
            })->all(),
        ])['record'];
        $opening = app(OpeningStockService::class)->approve($opening);
        $prices = collect($definitions)->keyBy('barcode');

        app(OpeningStockPricingService::class)->create([
            'branch_doc_num' => $branch->doc_num,
            'opening_stock_doc_num' => $opening->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'document_date' => '2026-01-01',
            'notes' => $this->note($description.' Valued at standard opening cost.'),
            'lines' => $opening->lines->map(function ($line) use ($prices): array {
                $price = $prices->get($line->product->barcode);

                return [
                    'opening_stock_line_public_id' => $line->public_id,
                    'unit_price' => $price['unit_price'],
                    'notes' => $this->note('Opening valuation approved by finance.'),
                ];
            })->all(),
        ]);
    }

    /** @param array<string, mixed> $resources @param array<string, mixed> $partners */
    private function seedSalesCycle(FinancialPeriod $period, array $resources, array $partners): void
    {
        $pail = $this->product($resources['company'], 'MGY-FG-PAIL-20L-BLU');
        $service = $this->product($resources['company'], 'MGY-SVC-DELIVERY');
        $quotations = app(QuotationService::class);
        $quotation = $quotations->create([
            'branch_id' => $resources['branch']->getKey(), 'customer_doc_num' => $partners['primary_customer']->doc_num,
            'customer_reference' => 'CP-PO-DEMO-260705', 'quotation_type' => Quotation::TypeStandard,
            'subject' => 'Supply of 20L blue industrial pails', 'quotation_date' => '2026-07-05', 'valid_until' => '2026-12-31',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'revision_date' => '2026-07-05',
            'change_reason' => 'Initial commercial offer', 'discount_type' => null, 'discount_value' => 0,
            'terms' => '<p>Prices are ex-factory and valid through FY 2026.</p>',
            'payment_terms' => '<p>EGP 10,000 on order and balance within 30 days.</p>',
            'delivery_terms' => '<p>Delivery to the customer warehouse in Greater Cairo.</p>',
            'technical_notes' => '<p>Food-safe virgin PP with blue masterbatch and fitted lid.</p>',
            'notes' => '<p>Golden runtime quotation converted through the complete sales cycle.</p>',
            'internal_notes' => $this->note('Use finished stock first; retain production traceability.'),
            'lines' => [
                [
                    'product_doc_num' => $pail->doc_num, 'unit_doc_num' => $pail->unit->doc_num,
                    'description' => '20L blue industrial pail with lid', 'quantity' => '200', 'unit_price' => '85',
                    'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 0, 'requested_date' => '2026-09-15',
                    'warehouse_notes' => 'Pick from released finished-goods batches.', 'production_notes' => 'No special production required.',
                ],
                [
                    'product_doc_num' => $service->doc_num, 'unit_doc_num' => $service->unit->doc_num,
                    'description' => 'Factory-to-customer delivery service', 'quantity' => '1', 'unit_price' => '1500',
                    'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 0, 'requested_date' => '2026-09-15',
                ],
            ],
            'payment_milestones' => [
                ['title' => 'Order advance', 'amount' => '10000', 'due_type' => QuotationPaymentMilestone::DueOnContract],
                ['title' => 'Balance after delivery', 'amount' => '8500', 'due_type' => QuotationPaymentMilestone::DueAfterDelivery],
            ],
            'execution_schedule_lines' => [[
                'phase_name' => 'Dispatch and delivery', 'start_date' => '2026-09-14', 'end_date' => '2026-09-15',
                'duration_days' => 2, 'responsibility' => 'Warehouse and Logistics',
            ]],
        ])['record'];
        $quotation = $quotations->markSent($quotation);
        $quotation = $quotations->accept($quotation);
        $orders = app(SalesOrderService::class);
        $order = $orders->createFromQuotation($quotation, [
            'company_id' => $resources['company']->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
        ]);
        $order = $orders->approve($order);
        $goodsLine = $order->lines->firstWhere('product_id', $pail->getKey());
        $serviceLine = $order->lines->firstWhere('product_id', $service->getKey());
        $fulfillment = app(SalesFulfillmentService::class);
        $fulfillment->reserve($goodsLine, '100');
        $delivery = $fulfillment->deliver($order, [[
            'sales_order_line_id' => $goodsLine->getKey(), 'quantity' => '200',
        ]], [
            'document_date' => '2026-08-25',
            'vehicle_number' => 'EGY-TRK-2607', 'driver_name' => 'Mahmoud Hassan',
            'driver_phone' => '+20 100 333 2607', 'destination_address' => 'Cairo Paints Receiving Warehouse',
            'notes' => $this->note('Golden sales delivery note with inventory and COGS posting.'),
        ]);
        $invoice = app(CustomerInvoiceService::class)->createFromOrder($order->fresh(), [
            [
                'sales_order_line_id' => $goodsLine->getKey(),
                'delivery_line_id' => $delivery->lines->firstOrFail()->getKey(), 'quantity' => '200',
            ],
            ['sales_order_line_id' => $serviceLine->getKey(), 'quantity' => '1'],
        ], [
            ['due_date' => '2026-08-25', 'amount' => '10000'],
            ['due_date' => '2026-10-15', 'amount' => '8500'],
        ], $delivery);
        $invoice = app(CustomerInvoiceService::class)->post($invoice);
        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $resources['company']->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'customer_id' => $partners['primary_customer']->getKey(),
            'receipt_date' => '2026-08-25', 'currency_id' => $resources['egp']->getKey(), 'exchange_rate' => 1,
            'payment_method' => 'cash', 'cashbox_id' => $resources['cashbox']->getKey(), 'amount' => '10000',
            'receipt_type' => CustomerReceipt::TypeCollection,
            'notes' => $this->note('Allocated cash receipt for the first sales invoice installment.'),
        ], [[
            'customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->firstOrFail()->getKey(), 'amount' => '10000',
        ]]);
        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $resources['company']->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'customer_id' => $partners['food_customer']->getKey(),
            'receipt_date' => '2026-08-10', 'currency_id' => $resources['egp']->getKey(), 'exchange_rate' => 1,
            'payment_method' => 'cheque', 'bank_account_id' => $resources['bank_account']->getKey(),
            'reference_no' => 'NF-CHQ-260810-01', 'cheque_due_date' => '2026-09-10', 'external_bank_name' => 'National Bank of Egypt',
            'amount' => '25000', 'receipt_type' => CustomerReceipt::TypeAdvance,
            'notes' => $this->note('Unallocated customer advance represented by a received cheque.'),
        ]);

        $returns = app(SalesReturnService::class);
        $invoiceGoodsLine = $invoice->lines->firstWhere('product_id', $pail->getKey());
        $return = $returns->create($invoice, SalesReturn::ReasonManufacturingDefect, 'Three pails show handle deformation.', [[
            'customer_invoice_line_id' => $invoiceGoodsLine->getKey(), 'quantity' => '10',
        ]]);
        $returns->authorize($return);
        $return = $returns->receive($return);
        $return = $returns->inspect($return, [[
            'sales_return_line_id' => $return->lines->firstOrFail()->getKey(),
            'saleable_quantity' => '7', 'quarantine_quantity' => '3', 'rework_quantity' => 0, 'scrap_quantity' => 0,
            'notes' => 'Seven units returned to saleable stock; three retained in quarantine.',
        ]]);
        $returns->close($return);

        $food = $this->product($resources['company'], 'MGY-FG-FOOD-5L-CLR');
        $orders->create([
            'company_id' => $resources['company']->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'branch_store_id' => $resources['finished_store']->getKey(),
            'customer_id' => $partners['food_customer']->getKey(), 'currency_id' => $resources['egp']->getKey(),
            'order_date' => '2026-08-20', 'expected_delivery_date' => '2026-09-20', 'exchange_rate' => 1,
            'customer_reference' => 'NF-DRAFT-PO-260820',
            'notes' => $this->note('Draft order intentionally ready for the next manual sales workflow.'),
            'lines' => [[
                'product_id' => $food->getKey(), 'unit_id' => $food->item_unit_id,
                'description' => '5L transparent food container', 'quantity' => '300', 'unit_price' => '30',
                'discount_amount' => 0, 'tax_amount' => 0,
            ]],
            'payment_schedules' => [[
                'title' => 'Full draft order value', 'amount' => '9000', 'due_date' => '2026-09-20',
            ]],
        ]);
    }

    /** @param array<string, mixed> $resources @param array<string, mixed> $partners */
    private function seedSalesLinkedProductionCycle(FinancialPeriod $period, array $resources, array $partners): void
    {
        $company = $resources['company'];
        $crate = $this->product($company, 'MGY-FG-CRATE-45L-BLK');
        $service = $this->product($company, 'MGY-SVC-QUALITY');
        $quotations = app(QuotationService::class);
        $quotation = $quotations->create([
            'branch_id' => $resources['branch']->getKey(),
            'customer_doc_num' => $partners['production_customer']->doc_num,
            'customer_reference' => 'PF-SALES-PRODUCTION-V1',
            'quotation_type' => Quotation::TypeStandard,
            'subject' => '100 black industrial crates with linked production',
            'quotation_date' => '2026-08-21',
            'valid_until' => '2026-12-31',
            'currency_doc_num' => $resources['egp']->doc_num,
            'exchange_rate' => 1,
            'revision_date' => '2026-08-21',
            'change_reason' => 'Initial offer for an exact stock-plus-production walkthrough',
            'discount_type' => null,
            'discount_value' => 0,
            'terms' => '<p>Price includes the approved crate specification and final factory release.</p>',
            'payment_terms' => '<p>EGP 5,000 cash and EGP 8,000 bank transfer after delivery.</p>',
            'delivery_terms' => '<p>Two dispatches of 60 and 40 pieces.</p>',
            'technical_notes' => '<p>Consume 30 opening-stock pieces and manufacture the remaining 70 pieces in three traced runs.</p>',
            'notes' => '<p>Persistent exact sales-linked production scenario.</p>',
            'internal_notes' => $this->note('Exact 30 stock + 70 production, split delivery, collection, and QC return scenario.'),
            'lines' => [
                [
                    'product_doc_num' => $crate->doc_num,
                    'unit_doc_num' => $crate->unit->doc_num,
                    'description' => '45L black industrial crate',
                    'quantity' => '100',
                    'unit_price' => '120',
                    'discount_type' => null,
                    'discount_value' => 0,
                    'tax_rate' => 0,
                    'requested_date' => '2026-08-28',
                    'warehouse_notes' => 'Reserve exactly 30 pieces from opening stock.',
                    'production_notes' => 'Generate the remaining 70 pieces from this sales line.',
                ],
                [
                    'product_doc_num' => $service->doc_num,
                    'unit_doc_num' => $service->unit->doc_num,
                    'description' => 'Independent finished-batch quality release',
                    'quantity' => '1',
                    'unit_price' => '1000',
                    'discount_type' => null,
                    'discount_value' => 0,
                    'tax_rate' => 0,
                    'requested_date' => '2026-08-28',
                ],
            ],
            'payment_milestones' => [
                ['title' => 'Cash installment', 'amount' => '5000', 'due_type' => QuotationPaymentMilestone::DueOnContract],
                ['title' => 'Bank installment', 'amount' => '8000', 'due_type' => QuotationPaymentMilestone::DueAfterDelivery],
            ],
            'execution_schedule_lines' => [[
                'phase_name' => 'Production and split dispatch',
                'start_date' => '2026-08-26',
                'end_date' => '2026-08-28',
                'duration_days' => 3,
                'responsibility' => 'Production, Quality, and Dispatch',
            ]],
        ])['record'];
        $quotation = $quotations->accept($quotations->markSent($quotation));

        $orders = app(SalesOrderService::class);
        $order = $orders->approve($orders->createFromQuotation($quotation, [
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
        ]));
        $crateLine = $order->lines->firstWhere('product_id', $crate->getKey());
        $serviceLine = $order->lines->firstWhere('product_id', $service->getKey());
        $fulfillment = app(SalesFulfillmentService::class);
        $fulfillment->reserve($crateLine, '30');

        $production = app(SalesProductionDemandService::class)->create($order->fresh(), [[
            'sales_order_line_id' => $crateLine->getKey(),
            'quantity' => '70',
        ]]);
        $cycle = app(ProductionCycleService::class);
        $production = $cycle->releaseOrder($production);
        $machine = ProductionMachine::query()->where('company_id', $company->getKey())->where('code', 'IMM-450-01')->firstOrFail();
        $mold = ProductionMold::query()->where('company_id', $company->getKey())->where('code', 'MOLD-CRATE-45L-01')->firstOrFail();
        $shift = ProductionShift::query()->where('company_id', $company->getKey())->where('code', 'SHIFT-A')->firstOrFail();
        $finalType = QualityInspectionType::query()->where('company_id', $company->getKey())->where('code', 'FINAL-PLASTIC-RELEASE')->firstOrFail();
        foreach ([
            ['20', '2026-08-26 07:00:00', '2026-08-26 09:00:00', 'CRATE-260826-A'],
            ['20', '2026-08-26 09:00:00', '2026-08-26 11:00:00', 'CRATE-260826-B'],
            ['30', '2026-08-26 11:00:00', '2026-08-26 14:00:00', 'CRATE-260826-C'],
        ] as [$quantity, $startsAt, $endsAt, $batch]) {
            $run = $cycle->createRun($production->lines->firstOrFail(), [
                'planned_quantity' => $quantity,
                'planned_start_at' => $startsAt,
                'planned_end_at' => $endsAt,
                'production_shift_id' => $shift->getKey(),
                'production_machine_id' => $machine->getKey(),
                'production_mold_id' => $mold->getKey(),
                'batch_lot' => $batch,
                'notes' => $this->note('One of three completed runs for the exact 70-piece sales production demand.'),
            ]);
            $this->completeSalesProductionRun($cycle, $run, $quantity, $finalType, $resources);
        }

        $deliveryOne = $fulfillment->deliver($order->fresh(), [[
            'sales_order_line_id' => $crateLine->getKey(), 'quantity' => '60',
        ]], [
            'document_date' => '2026-08-25', 'vehicle_number' => 'EGY-CRATE-01', 'driver_name' => 'Khaled Amin',
            'destination_address' => 'October Chemical Industries Receiving Dock',
            'notes' => $this->note('First exact split delivery: 60 crates.'),
        ]);
        $deliveryTwo = $fulfillment->deliver($order->fresh(), [[
            'sales_order_line_id' => $crateLine->getKey(), 'quantity' => '40',
        ]], [
            'document_date' => '2026-08-25', 'vehicle_number' => 'EGY-CRATE-02', 'driver_name' => 'Mina Atef',
            'destination_address' => 'October Chemical Industries Receiving Dock',
            'notes' => $this->note('Second exact split delivery: 40 crates.'),
        ]);
        $invoices = app(CustomerInvoiceService::class);
        $invoiceOneLines = $deliveryOne->lines->map(fn ($deliveryLine): array => [
            'sales_order_line_id' => $crateLine->getKey(),
            'delivery_line_id' => $deliveryLine->getKey(),
            'quantity' => (string) $deliveryLine->transaction_quantity,
        ])->all();
        $invoiceOne = $invoices->createFromOrder($order->fresh(), $invoiceOneLines, [
            ['due_date' => '2026-08-28', 'amount' => '7200'],
        ], $deliveryOne);
        $invoiceOne = $invoices->post($invoiceOne);
        $invoiceTwoLines = $deliveryTwo->lines->map(fn ($deliveryLine): array => [
            'sales_order_line_id' => $crateLine->getKey(),
            'delivery_line_id' => $deliveryLine->getKey(),
            'quantity' => (string) $deliveryLine->transaction_quantity,
        ])->push([
            'sales_order_line_id' => $serviceLine->getKey(),
            'quantity' => '1',
        ])->all();
        $invoiceTwo = $invoices->createFromOrder($order->fresh(), $invoiceTwoLines, [
            ['due_date' => '2026-09-27', 'amount' => '5800'],
        ], $deliveryTwo);
        $invoiceTwo = $invoices->post($invoiceTwo);
        $invoiceOneSchedule = $invoiceOne->paymentSchedules->firstOrFail();
        $invoiceTwoSchedule = $invoiceTwo->paymentSchedules->firstOrFail();
        $receiptService = app(CustomerReceiptService::class);
        $receiptService->createAndApprove([
            'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'customer_id' => $partners['production_customer']->getKey(),
            'receipt_date' => '2026-08-25', 'currency_id' => $resources['egp']->getKey(), 'exchange_rate' => 1,
            'payment_method' => 'cash', 'cashbox_id' => $resources['cashbox']->getKey(), 'amount' => '5000',
            'receipt_type' => CustomerReceipt::TypeCollection,
            'notes' => $this->note('Cash receipt for the exact sales-linked production invoice.'),
        ], [[
            'customer_invoice_payment_schedule_id' => $invoiceOneSchedule->getKey(), 'amount' => '5000',
        ]]);
        $receiptService->createAndApprove([
            'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'customer_id' => $partners['production_customer']->getKey(),
            'receipt_date' => '2026-08-25', 'currency_id' => $resources['egp']->getKey(), 'exchange_rate' => 1,
            'payment_method' => 'bank', 'bank_account_id' => $resources['bank_account']->getKey(), 'amount' => '8000',
            'reference_no' => 'OCI-BANK-260829-01', 'receipt_type' => CustomerReceipt::TypeCollection,
            'notes' => $this->note('Bank receipt that closes the exact sales-linked production invoice.'),
        ], [
            ['customer_invoice_payment_schedule_id' => $invoiceOneSchedule->getKey(), 'amount' => '2200'],
            ['customer_invoice_payment_schedule_id' => $invoiceTwoSchedule->getKey(), 'amount' => '5800'],
        ]);

        $returns = app(SalesReturnService::class);
        $invoiceCrateLine = $invoiceOne->lines->firstWhere('product_id', $crate->getKey());
        $return = $returns->create($invoiceOne, SalesReturn::ReasonManufacturingDefect, 'Ten crates returned for exact QC disposition.', [[
            'customer_invoice_line_id' => $invoiceCrateLine->getKey(), 'quantity' => '10',
        ]]);
        $return = $returns->receive($returns->authorize($return));
        $return = $returns->inspect($return, [[
            'sales_return_line_id' => $return->lines->firstOrFail()->getKey(),
            'saleable_quantity' => '7', 'quarantine_quantity' => 0, 'rework_quantity' => '2', 'scrap_quantity' => '1',
            'notes' => 'Exact return disposition: 7 saleable, 2 rework, and 1 scrap.',
        ]]);
        $returns->close($return);
    }

    /** @param array<string, mixed> $resources */
    private function completeSalesProductionRun(
        ProductionCycleService $cycle,
        ProductionRun $run,
        string $quantity,
        QualityInspectionType $finalType,
        array $resources,
    ): void {
        $cycle->reserveRun($run, $resources['raw_store']->getKey());
        $cycle->issueMaterials($run, $resources['raw_store']->getKey());
        $unissuedRequirements = $run->requirements()
            ->with('product')
            ->where('issued_quantity', '<=', 0)
            ->get();
        if ($unissuedRequirements->isNotEmpty()) {
            throw new DomainException($unissuedRequirements
                ->map(fn ($requirement): string => sprintf(
                    '%s planned=%s issued=%s',
                    $requirement->product?->barcode ?? (string) $requirement->product_id,
                    $requirement->planned_quantity,
                    $requirement->issued_quantity,
                ))
                ->implode('; '));
        }
        $cycle->startSetup($run);
        $cycle->completeSetup($run->fresh());
        $cycle->startRun($run->fresh());
        $cycle->recordProgress($run->fresh(), [
            'good_base_quantity' => $quantity,
            'notes' => 'All planned output passed the run-level production check.',
        ]);
        $accounting = $run->fresh()->requirements->mapWithKeys(fn ($requirement): array => [
            $requirement->getKey() => [
                'consumed_quantity' => (string) $requirement->issued_quantity,
                'waste_quantity' => '0',
            ],
        ])->all();
        $cycle->accountMaterials($run->fresh(), $resources['raw_store']->getKey(), $accounting);
        $cycle->recordInspection($run->fresh(), [
            'quality_inspection_type_id' => $finalType->getKey(), 'result' => 'passed',
            'notes' => 'Final dimensional, visual, stacking, and load checks passed.',
        ]);
        $cycle->receiveFinishedGoods($run->fresh(), $resources['finished_store']->getKey(), $quantity);
        $cycle->completeRun($run->fresh());
    }

    private function salesLinkedProductionCycleExists(Company $company): bool
    {
        return Quotation::query()
            ->where('company_id', $company->getKey())
            ->where('customer_reference', 'PF-SALES-PRODUCTION-V1')
            ->exists();
    }

    /** @param array<string, mixed> $resources @param array<string, mixed> $partners */
    private function seedFullySettledCustomerCycle(FinancialPeriod $period, array $resources, array $partners): void
    {
        $company = $resources['company'];
        $service = $this->product($company, 'MGY-SVC-QUALITY');
        $quotations = app(QuotationService::class);
        $quotation = $quotations->create([
            'branch_id' => $resources['branch']->getKey(),
            'customer_doc_num' => $partners['settled_customer']->doc_num,
            'customer_reference' => 'PF-SALES-FULLY-SETTLED-V1',
            'quotation_type' => Quotation::TypeStandard,
            'subject' => 'Finished-product quality release service',
            'quotation_date' => '2026-08-22',
            'valid_until' => '2026-09-30',
            'currency_doc_num' => $resources['egp']->doc_num,
            'exchange_rate' => 1,
            'revision_date' => '2026-08-22',
            'change_reason' => 'Initial quotation for a fully settled customer statement example',
            'terms' => '<p>Service is invoiced after the signed quality-release report.</p>',
            'payment_terms' => '<p>Full cash settlement on invoice date.</p>',
            'delivery_terms' => '<p>Electronic quality-release report.</p>',
            'technical_notes' => '<p>Independent finished-product dimensional and visual inspection.</p>',
            'notes' => '<p>Persistent fully settled customer statement scenario.</p>',
            'internal_notes' => $this->note('Canonical quotation-to-order-to-invoice-to-receipt example with a zero closing balance.'),
            'lines' => [[
                'product_doc_num' => $service->doc_num,
                'unit_doc_num' => $service->unit->doc_num,
                'description' => 'Independent finished-product quality release',
                'quantity' => '1',
                'unit_price' => '2500',
                'discount_type' => null,
                'discount_value' => 0,
                'tax_rate' => 0,
                'requested_date' => '2026-08-24',
            ]],
            'payment_milestones' => [[
                'title' => 'Full cash settlement',
                'amount' => '2500',
                'due_type' => QuotationPaymentMilestone::DueOnContract,
            ]],
        ])['record'];
        $quotation = $quotations->accept($quotations->markSent($quotation));

        $orders = app(SalesOrderService::class);
        $order = $orders->approve($orders->createFromQuotation($quotation, [
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
        ]));
        $orderLine = $order->lines->firstOrFail();
        $invoices = app(CustomerInvoiceService::class);
        $invoice = $invoices->createFromOrder($order->fresh(), [[
            'sales_order_line_id' => $orderLine->getKey(),
            'quantity' => '1',
        ]], [[
            'due_date' => '2026-08-24',
            'amount' => '2500',
        ]]);
        $invoice = $invoices->post($invoice);

        app(CustomerReceiptService::class)->createAndApprove([
            'company_id' => $company->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
            'customer_id' => $partners['settled_customer']->getKey(),
            'receipt_date' => '2026-08-24',
            'currency_id' => $resources['egp']->getKey(),
            'exchange_rate' => 1,
            'payment_method' => 'cash',
            'cashbox_id' => $resources['cashbox']->getKey(),
            'amount' => '2500',
            'receipt_type' => CustomerReceipt::TypeCollection,
            'notes' => $this->note('Full cash collection for the canonical zero-balance customer statement.'),
        ], [[
            'customer_invoice_payment_schedule_id' => $invoice->paymentSchedules->firstOrFail()->getKey(),
            'amount' => '2500',
        ]]);
    }

    private function fullySettledCustomerCycleExists(Company $company): bool
    {
        return Quotation::query()
            ->where('company_id', $company->getKey())
            ->where('customer_reference', 'PF-SALES-FULLY-SETTLED-V1')
            ->exists();
    }

    /** @param array<string, mixed> $resources */
    private function repairSalesDeliveryBatchPositions(FinancialPeriod $period, array $resources): void
    {
        $product = $this->product($resources['company'], 'MGY-FG-CRATE-45L-BLK');
        app(InventoryPositionReconciliationService::class)->reconcileNegativeNullBatchPosition(
            (int) $resources['company']->getKey(),
            (int) $period->getKey(),
            (int) $resources['branch']->getKey(),
            (int) $resources['finished_store']->getKey(),
            (int) $product->getKey(),
            '2026-08-25',
            'RUNTIME-DEMO-SALES-BATCH-REPAIR',
        );
    }

    /** @param array<string, mixed> $resources */
    private function seedInventoryGlReconciliation(FinancialPeriod $period, array $resources): void
    {
        $companyId = (int) $resources['company']->getKey();
        $sourceType = 'inventory_subledger_reconciliation';
        $rows = app(InventoryGlReconciliationService::class)->reconcile($companyId, (int) $period->getKey());
        $differences = collect($rows)
            ->whereIn('key', ['raw_materials', 'wip', 'finished_goods'])
            ->filter(fn (array $row): bool => $row['status'] === 'difference')
            ->values();
        if ($differences->isEmpty()) {
            return;
        }
        if (DB::table('journal_entries')
            ->where('company_id', $companyId)
            ->where('source_type', $sourceType)
            ->where('source_id', $companyId)
            ->where('is_posted', true)
            ->exists()
        ) {
            throw new DomainException('The persisted inventory reconciliation no longer agrees with the stock subledger.');
        }

        $mapping = InventoryAccountingMapping::query()->where('company_id', $companyId)->firstOrFail();
        $controlAccounts = [
            'raw_materials' => (int) $mapping->raw_material_inventory_account_id,
            'wip' => (int) $mapping->wip_account_id,
            'finished_goods' => (int) $mapping->finished_goods_inventory_account_id,
        ];
        $lines = [];
        $signedControlAdjustment = '0.0000';
        foreach ($differences as $row) {
            $difference = (string) $row['difference'];
            $signedControlAdjustment = bcadd($signedControlAdjustment, $difference, 4);
            $lines[] = [
                'account_id' => $controlAccounts[$row['key']],
                'debit_amount' => bccomp($difference, '0', 4) > 0 ? $difference : '0.0000',
                'credit_amount' => bccomp($difference, '0', 4) < 0 ? bcmul($difference, '-1', 4) : '0.0000',
                'description' => 'Inventory subledger reconciliation: '.$row['label'],
                'branch_id' => $resources['branch']->getKey(),
                'cost_center_id' => $resources['injection_cost_center']->getKey(),
            ];
        }
        $lines[] = [
            'account_id' => bccomp($signedControlAdjustment, '0', 4) > 0
                ? (int) $mapping->inventory_adjustment_gain_account_id
                : (int) $mapping->inventory_adjustment_loss_account_id,
            'debit_amount' => bccomp($signedControlAdjustment, '0', 4) < 0
                ? bcmul($signedControlAdjustment, '-1', 4)
                : '0.0000',
            'credit_amount' => bccomp($signedControlAdjustment, '0', 4) > 0
                ? $signedControlAdjustment
                : '0.0000',
            'description' => 'Offset for the documented inventory control-account reconciliation.',
            'branch_id' => $resources['branch']->getKey(),
            'cost_center_id' => $resources['injection_cost_center']->getKey(),
        ];

        app(JournalEntryService::class)->createPostedFromSource([
            'entry_date' => '2026-08-25',
            'company_id' => $companyId,
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
            'currency_id' => $resources['egp']->getKey(),
            'exchange_rate' => '1.000000',
            'description' => 'Inventory subledger-to-GL reconciliation - Runtime Demo',
            'notes' => $this->note('Auditable control-account reconciliation after the complete seeded business cycles.'),
            'source_type' => $sourceType,
            'source_id' => $companyId,
            'source_doc_num' => 'INV-RECON-RUNTIME-DEMO',
        ], $lines);
    }

    /** @param array<string, mixed> $resources */
    private function seedProductionCycle(FinancialPeriod $period, array $resources): void
    {
        $company = $resources['company'];
        $branch = $resources['branch'];
        $finished = $this->product($company, 'MGY-FG-PAIL-20L-BLU');
        $machine = ProductionMachine::query()->where('company_id', $company->getKey())->where('code', 'IMM-450-01')->firstOrFail();
        $mold = ProductionMold::query()->where('company_id', $company->getKey())->where('code', 'MOLD-PAIL-20L-01')->firstOrFail();
        $shift = ProductionShift::query()->where('company_id', $company->getKey())->where('code', 'SHIFT-A')->firstOrFail();
        $inProcessType = QualityInspectionType::query()->where('company_id', $company->getKey())->where('code', 'IN-PROCESS-PLASTIC')->firstOrFail();
        $finalType = QualityInspectionType::query()->where('company_id', $company->getKey())->where('code', 'FINAL-PLASTIC-RELEASE')->firstOrFail();
        $cycle = app(ProductionCycleService::class);
        $order = $cycle->createMakeToStockOrder([
            'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(), 'branch_id' => $branch->getKey(),
            'production_order_date' => '2026-04-01', 'expected_start_date' => '2026-04-03', 'expected_finish_date' => '2026-04-04',
            'priority' => 'high', 'overproduction_tolerance_percent' => 0,
            'production_notes' => $this->note('Golden make-to-stock pail production order.'),
        ], [[
            'product_id' => $finished->getKey(), 'unit_id' => $finished->item_unit_id,
            'quantity' => '1000', 'description' => '20L blue industrial pail with lid',
            'production_notes' => 'Use approved blue masterbatch and perform leak testing.',
        ]]);
        $order = $cycle->releaseOrder($order);
        $run = $cycle->createRun($order->lines->firstOrFail(), [
            'planned_quantity' => '500', 'planned_start_at' => '2026-04-03 07:00:00', 'planned_end_at' => '2026-04-03 15:00:00',
            'production_shift_id' => $shift->getKey(), 'production_machine_id' => $machine->getKey(), 'production_mold_id' => $mold->getKey(),
            'batch_lot' => 'PAIL-BLU-260403-A', 'notes' => $this->note('First partial run leaves quantity available for continued manual production.'),
        ]);
        $cycle->reserveRun($run, $resources['raw_store']->getKey());
        $cycle->issueMaterials($run, $resources['raw_store']->getKey());
        $cycle->startSetup($run);
        $cycle->completeSetup($run->fresh());
        $cycle->startRun($run->fresh());
        $cycle->recordProgress($run->fresh(), [
            'good_base_quantity' => '490', 'scrap_base_quantity' => '10',
            'notes' => '490 accepted pieces; 10 startup pieces scrapped.',
        ]);
        $cycle->recordInspection($run->fresh(), [
            'quality_inspection_type_id' => $inProcessType->getKey(), 'result' => 'failed',
            'defect_code' => 'QC-WEIGHT-HIGH', 'affected_base_quantity' => '10',
            'corrective_action' => 'Reduce holding pressure, verify cooling time, and resample.',
            'notes' => $this->note('Intentional failed inspection demonstrates the quality-hold workflow.'),
        ]);
        $cycle->recordInspection($run->fresh(), [
            'quality_inspection_type_id' => $inProcessType->getKey(), 'result' => 'passed',
            'notes' => 'Corrective settings verified and resample passed.',
        ]);
        $cycle->resumeRun($run->fresh());

        $accounting = $run->requirements()->with('product')->get()->mapWithKeys(function ($requirement): array {
            $waste = $requirement->product->barcode === 'MGY-RM-PP-HOMO-001' ? '5' : '0';

            return [$requirement->getKey() => [
                'consumed_quantity' => bcsub((string) $requirement->issued_quantity, $waste, 8),
                'waste_quantity' => $waste,
            ]];
        })->all();
        $cycle->accountMaterials($run->fresh(), $resources['raw_store']->getKey(), $accounting);
        $cycle->recordInspection($run->fresh(), [
            'quality_inspection_type_id' => $finalType->getKey(), 'result' => 'passed',
            'notes' => 'Visual, fit, handle-load, and leak checks passed for the released batch.',
        ]);
        $cycle->receiveFinishedGoods($run->fresh(), $resources['finished_store']->getKey(), '490');
        $cycle->completeRun($run->fresh());
    }

    /** @param array<string, mixed> $resources */
    private function seedProductionLinkedRequisition(array $resources): void
    {
        $productionOrder = ProductionOrder::query()
            ->where('company_id', $resources['company']->getKey())
            ->where('production_notes', 'like', '%Draft packing and assembly order ready for manual continuation%')
            ->with('lines')
            ->firstOrFail();
        $productionLine = $productionOrder->lines->firstOrFail();
        $material = $this->product($resources['company'], 'MGY-RM-MB-BLK-001');

        if (PurchaseRequisition::query()
            ->where('company_id', $resources['company']->getKey())
            ->whereHas('lines', fn ($query) => $query
                ->where('source_type', 'production_order')
                ->where('source_doc_num', $productionOrder->doc_num)
                ->where('source_line_reference', $productionLine->public_id)
                ->where('product_id', $material->getKey()))
            ->exists()
        ) {
            return;
        }

        $sourcing = app(ProcurementSourcingService::class);
        $requisition = $sourcing->createRequisition([
            'request_date' => '2026-08-24',
            'required_by_date' => '2026-08-30',
            'branch_store_uuid' => $resources['raw_store']->public_uuid,
            'department' => 'Production Planning',
            'priority' => 'urgent',
            'notes' => $this->note('Approved production-origin requirement intentionally left open for RFQ continuation.'),
            'lines' => [[
                'product_doc_num' => $material->doc_num,
                'unit_doc_num' => $material->unit->doc_num,
                'requested_quantity' => '250',
                'required_date' => '2026-08-30',
                'source_type' => 'production_order',
                'source_doc_num' => $productionOrder->doc_num,
                'source_line_reference' => $productionLine->public_id,
                'specification' => 'Black masterbatch suitable for the scheduled industrial-crate production run.',
                'notes' => $this->note('Production demand lineage remains available in requirements and procurement reports.'),
            ]],
        ]);
        $sourcing->submitRequisition($requisition);
        $sourcing->approveRequisition($requisition->fresh());
    }

    /** @param array<string, mixed> $resources @param array<string, mixed> $partners */
    private function seedProcurementCycle(FinancialPeriod $period, array $resources, array $partners): void
    {
        $resin = $this->product($resources['company'], 'MGY-RM-PP-HOMO-001');
        $sourcing = app(ProcurementSourcingService::class);
        $receiving = app(ProcurementReceivingService::class);
        $settlement = app(ProcurementSettlementService::class);
        $requisition = $sourcing->createRequisition([
            'request_date' => '2026-02-02', 'required_by_date' => '2026-02-15',
            'branch_store_uuid' => $resources['raw_store']->public_uuid, 'department' => 'Production Planning', 'priority' => 'high',
            'notes' => $this->note('Golden procurement cycle for polypropylene resin replenishment.'),
            'lines' => [[
                'product_doc_num' => $resin->doc_num, 'unit_doc_num' => $resin->unit->doc_num,
                'requested_quantity' => '5000', 'required_date' => '2026-02-15', 'source_type' => 'manual',
                'specification' => 'PP homopolymer injection grade, food-contact compliant.',
                'notes' => $this->note('Replenishment to maintain minimum resin cover.'),
            ]],
        ]);
        $sourcing->submitRequisition($requisition);
        $requisition = $sourcing->approveRequisition($requisition->fresh());
        $requirement = $requisition->lines->firstOrFail();
        $rfq = $sourcing->createRequestForQuotation($requisition, [
            'issue_date' => '2026-02-03', 'quotation_due_date' => '2026-02-06', 'required_delivery_date' => '2026-02-15',
            'supplier_doc_nums' => [$partners['primary_supplier']->doc_num, $partners['alternate_supplier']->doc_num],
            'commercial_notes' => $this->note('Compare price, tax, lead time, and payment terms.'),
            'lines' => [[
                'requisition_line_public_id' => $requirement->public_id, 'quantity' => '5000',
                'notes' => 'Deliver in sealed 25kg bags with certificate of analysis.',
            ]],
        ]);
        $rfq = $sourcing->issueRequestForQuotation($rfq);
        $rfqLine = $rfq->lines->firstOrFail();
        $quotations = collect([
            [$partners['primary_supplier'], '48', 7, 'EPS-Q-260204'],
            [$partners['alternate_supplier'], '49.5', 5, 'SPT-Q-260204'],
        ])->map(function (array $offer) use ($resources, $rfq, $rfqLine, $sourcing) {
            $quotation = $sourcing->createSupplierQuotation($rfq, [
                'supplier_doc_num' => $offer[0]->doc_num, 'currency_doc_num' => $resources['egp']->doc_num,
                'quotation_date' => '2026-02-04', 'valid_until' => '2026-02-20', 'exchange_rate' => 1,
                'supplier_reference' => $offer[3], 'lead_time_days' => $offer[2], 'payment_terms' => '30 days from accepted delivery',
                'freight_amount' => 0, 'commercial_notes' => $this->note('Submitted offer in the golden sourcing comparison.'),
                'lines' => [[
                    'rfq_line_public_id' => $rfqLine->public_id, 'offered_quantity' => '5000',
                    'unit_price' => $offer[1], 'discount_amount' => 0, 'tax_rate' => 14,
                    'delivery_date' => '2026-02-14',
                ]],
            ]);

            return $sourcing->submitSupplierQuotation($quotation);
        });
        $selection = $sourcing->createSupplierSelection($rfq->fresh(), [
            'selection_date' => '2026-02-06',
            'selection_reason' => 'Lowest compliant landed price with acceptable lead time.',
            'lines' => [[
                'quotation_line_public_id' => $quotations->first()->lines->firstOrFail()->public_id,
                'selected_quantity' => '5000', 'reason' => 'Best compliant commercial offer.',
            ]],
        ]);
        $order = $sourcing->approveSelection($selection)->sole();
        $order = app(PurchaseOrderService::class)->approve($order);
        $orderLine = $order->lines->firstOrFail();
        $order = $receiving->createDeliverySchedules($order, [
            'schedules' => [[
                'purchase_order_line_public_id' => $orderLine->public_id,
                'scheduled_date' => '2026-02-14', 'scheduled_quantity' => '5000',
                'notes' => 'One truck delivery to the raw-material warehouse.',
            ]],
        ]);
        $schedule = $order->lines->firstOrFail()->deliverySchedules->firstOrFail();
        $receipt = $receiving->receive($order->fresh(), [
            'document_date' => '2026-02-14', 'supplier_delivery_note' => 'EPS-DN-260214-01', 'supplier_delivery_date' => '2026-02-14',
            'received_at' => '2026-02-14 10:30:00', 'notes' => $this->note('Full delivery received pending incoming quality inspection.'),
            'lines' => [[
                'purchase_order_line_public_id' => $orderLine->public_id,
                'delivery_schedule_public_id' => $schedule->public_id, 'delivered_quantity' => '5000',
                'supplier_lot_number' => 'EPS-PP-260212-A', 'manufacture_date' => '2026-02-12',
            ]],
        ]);
        $receiptLine = $receipt->lines->firstOrFail();
        $receiving->inspect($receipt, [
            'inspection_at' => '2026-02-14 13:00:00',
            'observations' => 'Random bags sampled for MFI, contamination, and color.',
            'lines' => [[
                'receipt_line_public_id' => $receiptLine->public_id,
                'accepted_quantity' => '4900', 'rejected_quantity' => '100',
                'disposition' => 'quarantine', 'reason' => 'Two damaged bags isolated for supplier disposition.',
                'measurements' => ['mfi' => '12.1 g/10min', 'visual_contamination' => 'passed'],
            ]],
        ]);
        $invoice = app(PurchaseInvoiceService::class)->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $partners['primary_supplier']->doc_num,
            'purchase_order_doc_num' => $order->doc_num, 'purchase_type' => 'standard',
            'invoice_date' => '2026-02-16', 'supplier_invoice_number' => 'EPS-INV-260216-77', 'supplier_invoice_date' => '2026-02-16',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'credit',
            'notes' => $this->note('Three-way-matched invoice for accepted resin quantity.'),
            'lines' => [[
                'product_doc_num' => $resin->doc_num, 'unit_doc_num' => $resin->unit->doc_num,
                'purchase_order_line_public_id' => $orderLine->public_id, 'receipt_line_public_id' => $receiptLine->public_id,
                'quantity' => '4900', 'unit_price' => '48', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 14,
            ]],
            'payment_schedules' => [
                ['due_date' => '2026-03-01', 'amount' => '100000', 'payment_source_type' => 'scheduled', 'notes' => 'First installment'],
                ['due_date' => '2026-03-18', 'amount' => '168128', 'payment_source_type' => 'scheduled', 'notes' => 'Final installment'],
            ],
        ])['record'];
        $invoice = app(PurchaseInvoiceService::class)->approve($invoice);
        $payment = $settlement->createSupplierPayment([
            'supplier_doc_num' => $partners['primary_supplier']->doc_num,
            'purchase_order_doc_num' => $order->doc_num, 'payment_method' => SupplierPaymentContext::MethodCash,
            'payment_date' => '2026-03-01', 'currency_doc_num' => $resources['egp']->doc_num,
            'exchange_rate' => 1, 'cashbox_doc_num' => $resources['cashbox']->doc_num,
            'amount' => '100000', 'reason' => 'First resin invoice installment',
            'notes' => $this->note('Approved partial supplier settlement.'),
            'allocations' => [[
                'purchase_invoice_doc_num' => $invoice->doc_num,
                'payment_schedule_public_id' => $invoice->paymentSchedules->firstOrFail()->public_id,
                'amount' => '100000',
            ]],
        ]);
        $settlement->approveSupplierPayment($payment);

        $masterbatch = $this->product($resources['company'], 'MGY-RM-MB-BLUE-001');
        $sourcing->createRequisition([
            'request_date' => '2026-08-20', 'required_by_date' => '2026-09-05',
            'branch_store_uuid' => $resources['raw_store']->public_uuid, 'department' => 'Production Planning', 'priority' => 'normal',
            'notes' => $this->note('Draft requisition intentionally left ready for the next manual workflow.'),
            'lines' => [[
                'product_doc_num' => $masterbatch->doc_num, 'unit_doc_num' => $masterbatch->unit->doc_num,
                'requested_quantity' => '600', 'source_type' => 'manual',
            ]],
        ]);
    }

    /** @param array<string, mixed> $resources @param array<string, mixed> $partners */
    private function seedSplitProcurementCycle(FinancialPeriod $period, array $resources, array $partners): void
    {
        $resin = $this->product($resources['company'], 'MGY-RM-PP-HOMO-001');
        $sourcing = app(ProcurementSourcingService::class);
        $receiving = app(ProcurementReceivingService::class);
        $settlement = app(ProcurementSettlementService::class);
        $invoices = app(PurchaseInvoiceService::class);

        $requisition = $sourcing->createRequisition([
            'request_date' => '2026-08-03', 'required_by_date' => '2026-08-17',
            'branch_store_uuid' => $resources['raw_store']->public_uuid, 'department' => 'Production Planning', 'priority' => 'urgent',
            'notes' => $this->note('Exact 10,000 kg polypropylene split-award procurement and AP cycle.'),
            'lines' => [[
                'product_doc_num' => $resin->doc_num, 'unit_doc_num' => $resin->unit->doc_num,
                'requested_quantity' => '10000', 'required_date' => '2026-08-17', 'source_type' => 'manual',
                'specification' => 'PP homopolymer injection grade; food contact compliant; 25kg sealed bags.',
                'notes' => $this->note('Required quantity must be allocated exactly 60/40 between two suppliers.'),
            ]],
        ]);
        $sourcing->submitRequisition($requisition);
        $requisition = $sourcing->approveRequisition($requisition->fresh());
        $requirement = $requisition->lines->firstOrFail();
        $rfq = $sourcing->createRequestForQuotation($requisition, [
            'issue_date' => '2026-08-04', 'quotation_due_date' => '2026-08-07', 'required_delivery_date' => '2026-08-17',
            'supplier_doc_nums' => [
                $partners['primary_supplier']->doc_num,
                $partners['alternate_supplier']->doc_num,
                $partners['masterbatch_supplier']->doc_num,
            ],
            'commercial_notes' => $this->note('Compare net price, freight, lead time, payment terms, and delivery capacity.'),
            'lines' => [[
                'requisition_line_public_id' => $requirement->public_id, 'quantity' => '10000',
                'notes' => 'Certificate of analysis and batch traceability are mandatory.',
            ]],
        ]);
        $rfq = $sourcing->issueRequestForQuotation($rfq);
        $rfqLine = $rfq->lines->firstOrFail();
        $supplierQuotations = collect([
            [$partners['primary_supplier'], '20.00', '0', 5, '40% immediate, 30% after 30 days, 30% after 60 days', 'EPS-Q-260805-10K'],
            [$partners['alternate_supplier'], '19.75', '2500', 8, '50% now and 50% by issued cheque after 45 days', 'SPT-Q-260805-10K'],
            [$partners['masterbatch_supplier'], '20.50', '0', 4, 'Net 60 days', 'CMI-Q-260805-10K'],
        ])->map(function (array $offer) use ($resources, $rfq, $rfqLine, $sourcing) {
            $quotation = $sourcing->createSupplierQuotation($rfq, [
                'supplier_doc_num' => $offer[0]->doc_num, 'currency_doc_num' => $resources['egp']->doc_num,
                'quotation_date' => '2026-08-05', 'valid_until' => '2026-08-20', 'exchange_rate' => 1,
                'supplier_reference' => $offer[5], 'lead_time_days' => $offer[3], 'payment_terms' => $offer[4],
                'freight_amount' => $offer[2],
                'commercial_notes' => $this->note('Complete commercial offer retained for quotation comparison.'),
                'lines' => [[
                    'rfq_line_public_id' => $rfqLine->public_id, 'offered_quantity' => '10000',
                    'unit_price' => $offer[1], 'discount_amount' => 0, 'tax_rate' => 14,
                    'delivery_date' => '2026-08-17',
                ]],
            ]);

            return $sourcing->submitSupplierQuotation($quotation);
        });
        $selection = $sourcing->createSupplierSelection($rfq->fresh(), [
            'selection_date' => '2026-08-07',
            'selection_reason' => '60/40 risk split: Supplier A capacity and Supplier B lower unit price.',
            'lines' => [
                [
                    'quotation_line_public_id' => $supplierQuotations->get(0)->lines->firstOrFail()->public_id,
                    'selected_quantity' => '6000', 'reason' => 'Primary award for delivery capacity and split installments.',
                ],
                [
                    'quotation_line_public_id' => $supplierQuotations->get(1)->lines->firstOrFail()->public_id,
                    'selected_quantity' => '4000', 'reason' => 'Secondary award for lower unit price and supply continuity.',
                ],
            ],
        ]);
        $orders = $sourcing->approveSelection($selection);
        $supplierAOrder = app(PurchaseOrderService::class)->approve(
            $orders->firstWhere('supplier_id', $partners['primary_supplier']->getKey()),
        );
        $supplierBOrder = app(PurchaseOrderService::class)->approve(
            $orders->firstWhere('supplier_id', $partners['alternate_supplier']->getKey()),
        );
        $supplierALine = $supplierAOrder->lines->firstOrFail();
        $supplierBLine = $supplierBOrder->lines->firstOrFail();
        $supplierAOrder = $receiving->createDeliverySchedules($supplierAOrder, [
            'schedules' => [
                [
                    'purchase_order_line_public_id' => $supplierALine->public_id,
                    'scheduled_date' => '2026-08-11', 'scheduled_quantity' => '4000',
                    'notes' => 'Supplier A truck one of two.',
                ],
                [
                    'purchase_order_line_public_id' => $supplierALine->public_id,
                    'scheduled_date' => '2026-08-15', 'scheduled_quantity' => '2000',
                    'notes' => 'Supplier A balance delivery.',
                ],
            ],
        ]);
        $supplierBOrder = $receiving->createDeliverySchedules($supplierBOrder, [
            'schedules' => [[
                'purchase_order_line_public_id' => $supplierBLine->public_id,
                'scheduled_date' => '2026-08-13', 'scheduled_quantity' => '4000',
                'notes' => 'Supplier B single truck delivery.',
            ]],
        ]);

        $supplierASchedules = $supplierAOrder->fresh()->load('lines.deliverySchedules')->lines->firstOrFail()->deliverySchedules->sortBy('scheduled_date')->values();
        $supplierBSchedule = $supplierBOrder->fresh()->load('lines.deliverySchedules')->lines->firstOrFail()->deliverySchedules->firstOrFail();
        [$supplierAReceiptOne, $supplierAReceiptOneLine] = $this->receiveAndInspectPurchase(
            $supplierAOrder,
            $supplierALine,
            $supplierASchedules->get(0),
            $receiving,
            '4000',
            '3900',
            '100',
            '2026-08-11',
            'EPS-DN-260811-01',
            'EPS-LOT-260810-A',
        );
        [$supplierAReceiptTwo, $supplierAReceiptTwoLine] = $this->receiveAndInspectPurchase(
            $supplierAOrder->fresh(),
            $supplierALine,
            $supplierASchedules->get(1),
            $receiving,
            '2000',
            '2000',
            '0',
            '2026-08-15',
            'EPS-DN-260815-02',
            'EPS-LOT-260814-B',
        );
        [$supplierBReceipt, $supplierBReceiptLine] = $this->receiveAndInspectPurchase(
            $supplierBOrder,
            $supplierBLine,
            $supplierBSchedule,
            $receiving,
            '4000',
            '4000',
            '0',
            '2026-08-13',
            'SPT-DN-260813-01',
            'SPT-LOT-260812-A',
        );

        $preInvoiceReturn = $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $supplierAOrder->doc_num,
            'return_date' => '2026-08-12', 'reason_code' => 'incoming_qc_rejection',
            'notes' => $this->note('Pre-invoice return of the complete rejected quarantine quantity.'),
            'lines' => [[
                'receipt_line_public_id' => $supplierAReceiptOneLine->public_id,
                'quantity' => '100', 'from_quarantine' => true,
                'reason' => 'Torn bags and contamination discovered during incoming QC.',
            ]],
        ]);
        $settlement->approvePurchaseReturn($preInvoiceReturn);

        $supplierAInvoice = $invoices->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $partners['primary_supplier']->doc_num,
            'purchase_order_doc_num' => $supplierAOrder->doc_num, 'purchase_type' => 'standard',
            'invoice_date' => '2026-08-16', 'supplier_invoice_number' => 'EPS-INV-260816-5900', 'supplier_invoice_date' => '2026-08-16',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'credit',
            'notes' => $this->note('Matched invoice for 5,900 accepted kilograms across two GRNs.'),
            'lines' => [
                [
                    'product_doc_num' => $resin->doc_num, 'unit_doc_num' => $resin->unit->doc_num,
                    'purchase_order_line_public_id' => $supplierALine->public_id,
                    'receipt_line_public_id' => $supplierAReceiptOneLine->public_id,
                    'quantity' => '3900', 'unit_price' => '20', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 14,
                ],
                [
                    'product_doc_num' => $resin->doc_num, 'unit_doc_num' => $resin->unit->doc_num,
                    'purchase_order_line_public_id' => $supplierALine->public_id,
                    'receipt_line_public_id' => $supplierAReceiptTwoLine->public_id,
                    'quantity' => '2000', 'unit_price' => '20', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 14,
                ],
            ],
            'payment_schedules' => [
                ['due_date' => '2026-08-16', 'amount' => '53808', 'payment_source_type' => 'scheduled', 'notes' => '40% immediate installment'],
                ['due_date' => '2026-09-15', 'amount' => '40356', 'payment_source_type' => 'scheduled', 'notes' => '30% after 30 days'],
                ['due_date' => '2026-10-15', 'amount' => '40356', 'payment_source_type' => 'scheduled', 'notes' => '30% after 60 days'],
            ],
        ])['record'];
        $supplierAInvoice = $invoices->approve($supplierAInvoice);
        $supplierBInvoice = $invoices->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $partners['alternate_supplier']->doc_num,
            'purchase_order_doc_num' => $supplierBOrder->doc_num, 'purchase_type' => 'standard',
            'invoice_date' => '2026-08-17', 'supplier_invoice_number' => 'SPT-INV-260817-4000', 'supplier_invoice_date' => '2026-08-17',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'credit',
            'notes' => $this->note('Matched and fully settled Supplier B invoice.'),
            'lines' => [[
                'product_doc_num' => $resin->doc_num, 'unit_doc_num' => $resin->unit->doc_num,
                'purchase_order_line_public_id' => $supplierBLine->public_id,
                'receipt_line_public_id' => $supplierBReceiptLine->public_id,
                'quantity' => '4000', 'unit_price' => '19.75', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 14,
            ]],
            'payment_schedules' => [
                ['due_date' => '2026-08-17', 'amount' => '45030', 'payment_source_type' => 'scheduled', 'notes' => '50% bank installment'],
                ['due_date' => '2026-10-01', 'amount' => '45030', 'payment_source_type' => 'scheduled', 'notes' => '50% outgoing cheque installment'],
            ],
        ])['record'];
        $supplierBInvoice = $invoices->approve($supplierBInvoice);

        $postInvoiceReturn = $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $supplierAOrder->doc_num,
            'purchase_invoice_doc_num' => $supplierAInvoice->doc_num,
            'return_date' => '2026-08-20', 'reason_code' => 'latent_material_defect',
            'notes' => $this->note('Post-invoice return creates the supplier debit adjustment and VAT reversal.'),
            'lines' => [[
                'receipt_line_public_id' => $supplierAReceiptOneLine->public_id,
                'quantity' => '500', 'from_quarantine' => false,
                'reason' => 'Latent contamination confirmed after production laboratory retest.',
            ]],
        ]);
        $settlement->approvePurchaseReturn($postInvoiceReturn);

        $supplierACashPayment = $settlement->createSupplierPayment([
            'supplier_doc_num' => $partners['primary_supplier']->doc_num,
            'purchase_order_doc_num' => $supplierAOrder->doc_num, 'payment_method' => SupplierPaymentContext::MethodCash,
            'payment_date' => '2026-08-18', 'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1,
            'cashbox_doc_num' => $resources['cashbox']->doc_num, 'amount' => '30000',
            'reason' => 'Partial immediate installment for split award',
            'notes' => $this->note('Canonical cash voucher and supplier allocation; balance remains due.'),
            'allocations' => [[
                'purchase_invoice_doc_num' => $supplierAInvoice->doc_num,
                'payment_schedule_public_id' => $supplierAInvoice->paymentSchedules->get(0)->public_id,
                'amount' => '30000',
            ]],
        ]);
        $settlement->approveSupplierPayment($supplierACashPayment);
        $supplierBBankPayment = $settlement->createSupplierPayment([
            'supplier_doc_num' => $partners['alternate_supplier']->doc_num,
            'purchase_order_doc_num' => $supplierBOrder->doc_num, 'payment_method' => SupplierPaymentContext::MethodBank,
            'payment_date' => '2026-08-18', 'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1,
            'bank_account_doc_num' => $resources['bank_account']->doc_num, 'amount' => '45030',
            'reason' => 'First Supplier B invoice installment by bank transfer',
            'notes' => $this->note('Canonical bank supplier payment and AP allocation.'),
            'allocations' => [[
                'purchase_invoice_doc_num' => $supplierBInvoice->doc_num,
                'payment_schedule_public_id' => $supplierBInvoice->paymentSchedules->get(0)->public_id,
                'amount' => '45030',
            ]],
        ]);
        $settlement->approveSupplierPayment($supplierBBankPayment);
        $supplierBChequePayment = $settlement->createSupplierPayment([
            'supplier_doc_num' => $partners['alternate_supplier']->doc_num,
            'purchase_order_doc_num' => $supplierBOrder->doc_num, 'payment_method' => SupplierPaymentContext::MethodCheque,
            'payment_date' => '2026-08-18', 'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1,
            'bank_account_doc_num' => $resources['bank_account']->doc_num,
            'cheque_number' => 'OCH-MGY-260818-001', 'cheque_date' => '2026-08-18', 'cheque_due_date' => '2026-10-01',
            'amount' => '45030', 'reason' => 'Final Supplier B installment by outgoing cheque',
            'notes' => $this->note('Issued, delivered, and cleared outgoing supplier cheque.'),
            'allocations' => [[
                'purchase_invoice_doc_num' => $supplierBInvoice->doc_num,
                'payment_schedule_public_id' => $supplierBInvoice->paymentSchedules->get(1)->public_id,
                'amount' => '45030',
            ]],
        ]);
        $supplierBChequePayment = $settlement->approveSupplierPayment($supplierBChequePayment);
        $chequeService = app(ChequeService::class);
        $cheque = $chequeService->markDelivered($supplierBChequePayment->cheque);
        $chequeService->markCleared($cheque);

        $advance = $settlement->createSupplierPayment([
            'supplier_doc_num' => $partners['maintenance_supplier']->doc_num,
            'payment_method' => SupplierPaymentContext::MethodCash, 'is_advance' => true,
            'payment_date' => '2026-07-20', 'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1,
            'cashbox_doc_num' => $resources['cashbox']->doc_num, 'amount' => '25000',
            'reason' => 'Advance for annual injection-machine maintenance',
            'notes' => $this->note('Supplier advance approved before the later service invoice.'),
            'allocations' => [],
        ]);
        $advance = $settlement->approveSupplierPayment($advance);
        $maintenanceService = $this->product($resources['company'], 'MGY-SVC-MAINTENANCE');
        $maintenanceInvoice = $invoices->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $partners['maintenance_supplier']->doc_num,
            'purchase_type' => 'direct', 'direct_procurement_override' => true,
            'direct_procurement_reason' => 'Approved specialist service without a warehouse receipt.',
            'invoice_date' => '2026-08-01', 'supplier_invoice_number' => 'IMS-INV-260801-AMC', 'supplier_invoice_date' => '2026-08-01',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'credit',
            'notes' => $this->note('Partially settled service invoice using a previously paid supplier advance.'),
            'lines' => [[
                'product_doc_num' => $maintenanceService->doc_num, 'unit_doc_num' => $maintenanceService->unit->doc_num,
                'quantity' => '1', 'unit_price' => '50000', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 0,
            ]],
            'payment_schedules' => [
                ['due_date' => '2026-08-01', 'amount' => '25000', 'payment_source_type' => 'scheduled', 'notes' => 'Immediate advance application'],
                ['due_date' => '2026-09-15', 'amount' => '25000', 'payment_source_type' => 'scheduled', 'notes' => 'Balance after 45 days'],
            ],
        ])['record'];
        $maintenanceInvoice = $invoices->approve($maintenanceInvoice);
        $settlement->allocatePayment($advance, [[
            'purchase_invoice_doc_num' => $maintenanceInvoice->doc_num,
            'payment_schedule_public_id' => $maintenanceInvoice->paymentSchedules->get(0)->public_id,
            'amount' => '25000',
        ]]);

        $transportService = $this->product($resources['company'], 'MGY-SVC-INBOUND-FREIGHT');
        $dueSoonInvoice = $invoices->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $partners['transport_supplier']->doc_num,
            'purchase_type' => 'direct', 'direct_procurement_override' => true,
            'direct_procurement_reason' => 'Approved transport service supported by delivery evidence.',
            'invoice_date' => '2026-08-24', 'supplier_invoice_number' => 'EDT-INV-260824-01', 'supplier_invoice_date' => '2026-08-24',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'credit',
            'notes' => $this->note('Unpaid transport invoice due within the current week.'),
            'lines' => [[
                'product_doc_num' => $transportService->doc_num, 'unit_doc_num' => $transportService->unit->doc_num,
                'quantity' => '1', 'unit_price' => '12000', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 0,
            ]],
            'payment_schedules' => [[
                'due_date' => '2026-08-28', 'amount' => '12000', 'payment_source_type' => 'scheduled', 'notes' => 'Due this week',
            ]],
        ])['record'];
        $invoices->approve($dueSoonInvoice);
        $labelSetupService = $this->product($resources['company'], 'MGY-SVC-LABEL-SETUP');
        $invoices->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $partners['label_supplier']->doc_num,
            'purchase_type' => 'direct', 'direct_procurement_override' => true,
            'direct_procurement_reason' => 'Draft label proof and setup charge awaiting approval.',
            'invoice_date' => '2026-08-25', 'supplier_invoice_number' => 'LTE-DRAFT-260825', 'supplier_invoice_date' => '2026-08-25',
            'currency_doc_num' => $resources['egp']->doc_num, 'exchange_rate' => 1, 'payment_type' => 'credit',
            'notes' => $this->note('Draft purchase invoice intentionally ready for manual approval.'),
            'lines' => [[
                'product_doc_num' => $labelSetupService->doc_num, 'unit_doc_num' => $labelSetupService->unit->doc_num,
                'quantity' => '1', 'unit_price' => '8500', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 0,
            ]],
            'payment_schedules' => [[
                'due_date' => '2026-09-24', 'amount' => '8500', 'payment_source_type' => 'scheduled', 'notes' => 'Draft 30-day term',
            ]],
        ]);

        foreach ([$supplierAReceiptOne, $supplierAReceiptTwo, $supplierBReceipt] as $receipt) {
            $receipt->forceFill(['notes' => $this->note('GRN linked to the exact 10,000 kg split-award cycle.')])->save();
        }
    }

    private function splitProcurementCycleExists(Company $company): bool
    {
        return PurchaseRequisition::query()
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', '%Exact 10,000 kg polypropylene split-award%')
            ->exists();
    }

    /** @param array<string, mixed> $resources @param array<string, mixed> $partners */
    private function seedPurchaseOrderLifecycleStates(array $resources, array $partners): void
    {
        $orders = app(PurchaseOrderService::class);
        $receiving = app(ProcurementReceivingService::class);
        $settlement = app(ProcurementSettlementService::class);
        $masterbatch = $this->product($resources['company'], 'MGY-RM-MB-BLUE-001');
        $baseData = [
            'supplier_doc_num' => $partners['masterbatch_supplier']->doc_num,
            'currency_doc_num' => $resources['egp']->doc_num,
            'branch_store_uuid' => $resources['raw_store']->public_uuid,
            'document_date' => '2026-08-21',
            'expected_delivery_date' => '2026-09-05',
            'exchange_rate' => 1,
            'purchase_type' => 'direct',
            'direct_procurement_override' => true,
            'direct_procurement_reason' => 'Manual lifecycle coverage for the persistent factory dataset.',
            'payment_terms' => 'Net 30 days',
        ];

        if (! PurchaseOrder::query()->where('company_id', $resources['company']->getKey())->where('internal_reference', 'PF-PO-DRAFT-STATE')->exists()) {
            $orders->create([
                ...$baseData,
                'internal_reference' => 'PF-PO-DRAFT-STATE',
                'notes' => $this->note('Draft Purchase Order ready for manual edit and approval testing.'),
                'lines' => [$this->purchaseOrderLifecycleLine($masterbatch, '250', '82')],
            ]);
        }

        $amendedOrder = PurchaseOrder::query()
            ->where('company_id', $resources['company']->getKey())
            ->where('internal_reference', 'PF-PO-AMENDMENT-STATE')
            ->first();
        if (! $amendedOrder instanceof PurchaseOrder) {
            $amendedOrder = $orders->create([
                ...$baseData,
                'internal_reference' => 'PF-PO-AMENDMENT-STATE',
                'notes' => $this->note('Approved unreceived Purchase Order used by the controlled amendment workflow.'),
                'lines' => [$this->purchaseOrderLifecycleLine($masterbatch, '300', '80')],
            ])['record'];
            $amendedOrder = $orders->approve($amendedOrder);
        }

        if (! PurchaseOrderChangeRequest::query()->where('purchase_order_id', $amendedOrder->getKey())->where('status', 'approved')->exists()) {
            $approvedChange = $settlement->requestPurchaseOrderChange($amendedOrder->refresh(), [
                'request_date' => '2026-08-22',
                'requested_values' => [
                    'expected_delivery_date' => '2026-09-08',
                    'payment_terms' => '25% advance; balance Net 30 days',
                    'notes' => $this->note('Approved amendment retained with before-and-after values.'),
                ],
                'reason' => 'Supplier requested a documented delivery and payment-term revision.',
            ]);
            $settlement->approvePurchaseOrderChange($approvedChange);
        }

        if (! PurchaseOrderChangeRequest::query()->where('purchase_order_id', $amendedOrder->getKey())->where('status', 'pending')->exists()) {
            $settlement->requestPurchaseOrderChange($amendedOrder->refresh(), [
                'request_date' => '2026-08-25',
                'requested_values' => ['notes' => $this->note('Pending amendment intentionally ready for manual approve/reject testing.')],
                'reason' => 'Pending review of updated supplier batch-certificate wording.',
            ]);
        }

        $partialOrder = PurchaseOrder::query()
            ->where('company_id', $resources['company']->getKey())
            ->where('internal_reference', 'PF-PO-PARTIAL-STATE')
            ->first();
        if (! $partialOrder instanceof PurchaseOrder) {
            $partialOrder = $orders->create([
                ...$baseData,
                'internal_reference' => 'PF-PO-PARTIAL-STATE',
                'notes' => $this->note('Partially received Purchase Order with an outstanding delivery and GRNI.'),
                'lines' => [$this->purchaseOrderLifecycleLine($masterbatch, '1000', '79')],
            ])['record'];
            $partialOrder = $orders->approve($partialOrder);
            $line = $partialOrder->lines->firstOrFail();
            $partialOrder = $receiving->createDeliverySchedules($partialOrder, [
                'schedules' => [[
                    'purchase_order_line_public_id' => $line->public_id,
                    'scheduled_date' => '2026-08-24',
                    'scheduled_quantity' => '400',
                    'notes' => 'First release against the 1,000 kg order.',
                ]],
            ]);
            $schedule = $partialOrder->lines->firstOrFail()->deliverySchedules->firstOrFail();
            $receipt = $receiving->receive($partialOrder->refresh(), [
                'document_date' => '2026-08-24',
                'supplier_delivery_note' => 'CMI-PARTIAL-260824',
                'supplier_delivery_date' => '2026-08-24',
                'received_at' => '2026-08-24 10:00:00',
                'notes' => $this->note('Partial 400 kg delivery; 600 kg remains open.'),
                'lines' => [[
                    'purchase_order_line_public_id' => $line->public_id,
                    'delivery_schedule_public_id' => $schedule->public_id,
                    'delivered_quantity' => '400',
                    'supplier_lot_number' => 'CMI-BLUE-260823-A',
                    'manufacture_date' => '2026-08-20',
                    'expiry_date' => '2028-08-20',
                ]],
            ]);
            $receiptLine = $receipt->lines->firstOrFail();
            $receiving->inspect($receipt, [
                'inspection_at' => '2026-08-24 14:00:00',
                'observations' => 'Color index, dispersion, and packaging passed.',
                'lines' => [[
                    'receipt_line_public_id' => $receiptLine->public_id,
                    'accepted_quantity' => '400',
                    'rejected_quantity' => '0',
                ]],
            ]);
        }

        $fullyReceivedOpenOrder = PurchaseOrder::query()
            ->where('company_id', $resources['company']->getKey())
            ->where('status', PurchaseOrder::StatusApproved)
            ->where('total_ordered_quantity', '>', 0)
            ->whereColumn('total_received_quantity', '>=', 'total_ordered_quantity')
            ->where(function ($query): void {
                $query->whereNull('internal_reference')
                    ->orWhereNotIn('internal_reference', ['PF-PO-AMENDMENT-STATE', 'PF-PO-PARTIAL-STATE']);
            })
            ->orderBy('id')
            ->first();
        if ($fullyReceivedOpenOrder instanceof PurchaseOrder) {
            $orders->close($fullyReceivedOpenOrder);
        }
    }

    /** @return array<string, mixed> */
    private function purchaseOrderLifecycleLine(Product $product, string $quantity, string $unitPrice): array
    {
        return [
            'product_doc_num' => $product->doc_num,
            'unit_doc_num' => $product->unit->doc_num,
            'ordered_quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_type' => 'fixed',
            'discount_value' => 0,
            'tax_rate' => 14,
            'required_delivery_date' => '2026-09-05',
            'specification' => 'Blue masterbatch, UV-stabilized, sealed 25 kg bags.',
        ];
    }

    private function repairDemoPurchaseReturnBatch(Company $company): void
    {
        $purchaseReturn = PurchaseReturn::query()
            ->with(['lines.receiptLine', 'purchaseOrder', 'purchaseInvoice'])
            ->where('company_id', $company->getKey())
            ->where('status', PurchaseReturn::StatusPosted)
            ->where('notes', 'like', '%Post-invoice return creates the supplier debit adjustment%')
            ->first();

        if (! $purchaseReturn instanceof PurchaseReturn) {
            return;
        }

        $movementMissingBatch = InventoryTransaction::query()
            ->where('source_type', PurchaseReturn::class)
            ->where('source_id', $purchaseReturn->getKey())
            ->whereNull('batch_lot')
            ->exists();

        if (! $movementMissingBatch) {
            return;
        }

        $line = $purchaseReturn->lines->firstOrFail();
        $receiptLine = $line->receiptLine;
        $settlement = app(ProcurementSettlementService::class);
        $settlement->reversePurchaseReturn($purchaseReturn, 'Recreated to preserve the received supplier batch dimension.');
        $replacement = $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $purchaseReturn->purchaseOrder->doc_num,
            'purchase_invoice_doc_num' => $purchaseReturn->purchaseInvoice->doc_num,
            'return_date' => $purchaseReturn->return_date->toDateString(),
            'reason_code' => $purchaseReturn->reason_code,
            'notes' => $this->note('Post-invoice return creates the supplier debit adjustment and preserves the receipt batch.'),
            'lines' => [[
                'receipt_line_public_id' => $receiptLine->public_id,
                'quantity' => (string) $line->quantity,
                'from_quarantine' => false,
                'reason' => $line->reason,
            ]],
        ]);
        $settlement->approvePurchaseReturn($replacement);
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function receiveAndInspectPurchase(
        mixed $order,
        mixed $orderLine,
        mixed $schedule,
        ProcurementReceivingService $receiving,
        string $delivered,
        string $accepted,
        string $rejected,
        string $date,
        string $deliveryNote,
        string $supplierLot,
    ): array {
        $receipt = $receiving->receive($order, [
            'document_date' => $date, 'supplier_delivery_note' => $deliveryNote, 'supplier_delivery_date' => $date,
            'received_at' => $date.' 10:00:00',
            'notes' => $this->note('Scheduled GRN received pending incoming quality inspection.'),
            'lines' => [[
                'purchase_order_line_public_id' => $orderLine->public_id,
                'delivery_schedule_public_id' => $schedule->public_id, 'delivered_quantity' => $delivered,
                'supplier_lot_number' => $supplierLot, 'manufacture_date' => $date,
            ]],
        ]);
        $receiptLine = $receipt->lines->firstOrFail();
        $receiving->inspect($receipt, [
            'inspection_at' => $date.' 14:00:00',
            'observations' => 'Incoming resin sampled for MFI, contamination, bag integrity, and certificate compliance.',
            'lines' => [[
                'receipt_line_public_id' => $receiptLine->public_id,
                'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected,
                'disposition' => bccomp($rejected, '0', 8) > 0 ? 'quarantine' : 'accepted',
                'reason' => bccomp($rejected, '0', 8) > 0 ? 'Damaged bags isolated in quarantine for supplier return.' : null,
                'measurements' => ['mfi' => '12.0 g/10min', 'visual_contamination' => 'passed', 'coa' => 'verified'],
            ]],
        ]);

        return [$receipt->fresh(), $receiptLine->fresh()];
    }

    /** @param array<string, mixed> $resources */
    private function seedAdditionalProductionScenarios(FinancialPeriod $period, array $resources): void
    {
        $company = $resources['company'];
        $finished = $this->product($company, 'MGY-FG-PAIL-20L-BLU');
        $packingProduct = $this->product($company, 'MGY-FG-CRATE-45L-BLK');
        $machine = ProductionMachine::query()->where('company_id', $company->getKey())->where('code', 'IMM-450-01')->firstOrFail();
        $mold = ProductionMold::query()->where('company_id', $company->getKey())->where('code', 'MOLD-PAIL-20L-01')->firstOrFail();
        $shift = ProductionShift::query()->where('company_id', $company->getKey())->where('code', 'SHIFT-A')->firstOrFail();
        $finalType = QualityInspectionType::query()->where('company_id', $company->getKey())->where('code', 'FINAL-PLASTIC-RELEASE')->firstOrFail();
        $cycle = app(ProductionCycleService::class);
        $order = $cycle->createMakeToStockOrder([
            'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'production_order_date' => '2026-08-25',
            'expected_start_date' => '2026-08-25', 'expected_finish_date' => '2026-08-25',
            'priority' => 'normal', 'overproduction_tolerance_percent' => 0,
            'production_notes' => $this->note('Additional-issue and material-return production scenario.'),
        ], [[
            'product_id' => $finished->getKey(), 'unit_id' => $finished->item_unit_id,
            'quantity' => '100', 'description' => '20L blue industrial pail replenishment batch',
            'production_notes' => 'Demonstrates additional issue, unused-material return, waste, QC, and finished receipt.',
        ]]);
        $order = $cycle->releaseOrder($order);
        $run = $cycle->createRun($order->lines->firstOrFail(), [
            'planned_quantity' => '100', 'planned_start_at' => '2026-08-25 07:00:00', 'planned_end_at' => '2026-08-25 11:00:00',
            'production_shift_id' => $shift->getKey(), 'production_machine_id' => $machine->getKey(),
            'production_mold_id' => $mold->getKey(), 'batch_lot' => 'PAIL-BLU-260825-B',
            'notes' => $this->note('Second completed run with full material-movement coverage.'),
        ]);
        $cycle->reserveRun($run, $resources['raw_store']->getKey());
        $cycle->issueMaterials($run, $resources['raw_store']->getKey());
        $additionalQuantities = $run->fresh()->requirements->mapWithKeys(function ($requirement): array {
            $quantity = match ($requirement->product?->barcode) {
                'MGY-RM-PP-HOMO-001' => '2',
                'MGY-RM-MB-BLUE-001' => '0.10',
                default => '0.10',
            };

            return [$requirement->getKey() => $quantity];
        })->all();
        $cycle->issueMaterials($run->fresh(), $resources['raw_store']->getKey(), $additionalQuantities, true);
        $returnQuantities = collect($additionalQuantities)->mapWithKeys(
            fn (string $quantity, int $requirementId): array => [$requirementId => bcdiv($quantity, '2', 8)],
        )->all();
        $cycle->returnMaterials($run->fresh(), $resources['raw_store']->getKey(), $returnQuantities);
        $cycle->startSetup($run->fresh());
        $cycle->completeSetup($run->fresh());
        $cycle->startRun($run->fresh());
        $cycle->recordProgress($run->fresh(), [
            'good_base_quantity' => '98', 'scrap_base_quantity' => '2',
            'notes' => 'Ninety-eight accepted pieces and two setup rejects.',
        ]);
        $accounting = $run->fresh()->requirements->mapWithKeys(function ($requirement): array {
            $available = bcsub(
                bcadd((string) $requirement->issued_quantity, (string) $requirement->additional_issued_quantity, 8),
                (string) $requirement->returned_quantity,
                8,
            );
            $waste = $requirement->product?->barcode === 'MGY-RM-PP-HOMO-001' ? '1' : '0';

            return [$requirement->getKey() => [
                'consumed_quantity' => bcsub($available, $waste, 8),
                'waste_quantity' => $waste,
            ]];
        })->all();
        $cycle->accountMaterials($run->fresh(), $resources['raw_store']->getKey(), $accounting);
        $cycle->recordInspection($run->fresh(), [
            'quality_inspection_type_id' => $finalType->getKey(), 'result' => 'passed',
            'notes' => 'Final dimensional, visual, handle-load, and leak checks passed.',
        ]);
        $cycle->receiveFinishedGoods($run->fresh(), $resources['finished_store']->getKey(), '98');
        $cycle->completeRun($run->fresh());

        $cycle->createMakeToStockOrder([
            'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(), 'production_order_date' => '2026-08-25',
            'expected_start_date' => '2026-08-27', 'expected_finish_date' => '2026-08-28',
            'priority' => 'normal', 'overproduction_tolerance_percent' => 2,
            'production_notes' => $this->note('Draft packing and assembly order ready for manual continuation.'),
        ], [[
            'product_id' => $packingProduct->getKey(), 'unit_id' => $packingProduct->item_unit_id,
            'quantity' => '100', 'description' => '45L black industrial crate packing batch',
            'production_notes' => 'Draft order retains the percentage, count, quantity, and direct BOM calculation methods.',
        ]]);
    }

    private function additionalProductionScenariosExist(Company $company): bool
    {
        return ProductionOrder::query()
            ->where('company_id', $company->getKey())
            ->where('production_notes', 'like', '%Additional-issue and material-return production scenario%')
            ->exists();
    }

    /** @param array<string, mixed> $resources */
    private function seedAdditionalInventoryMovements(FinancialPeriod $period, array $resources): void
    {
        $movements = app(InventoryMovementService::class);
        $pail = $this->product($resources['company'], 'MGY-FG-PAIL-20L-BLU');
        $resin = $this->product($resources['company'], 'MGY-RM-PP-HOMO-001');
        $packingStore = BranchStore::query()
            ->where('branch_id', $resources['branch']->getKey())
            ->where('name', 'Packaging Materials Warehouse - Runtime Demo')
            ->firstOrFail();
        $context = [
            'company_id' => $resources['company']->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
            'document_date' => '2026-08-25',
        ];

        $movements->createAndPost([
            ...$context,
            'branch_store_id' => $resources['finished_store']->getKey(),
            'destination_branch_store_id' => $resources['finished_store']->getKey(),
            'document_type' => InventoryDocument::TypeDamage,
            'source_stock_status' => InventoryTransaction::StatusAvailable,
            'destination_stock_status' => InventoryTransaction::StatusDamaged,
            'purpose' => 'Warehouse damage classification',
            'movement_reason' => 'Runtime demo damaged pails',
            'notes' => $this->note('Five pails moved from available stock to the damaged status.'),
        ], [[
            'product_id' => $pail->getKey(), 'quantity' => '5',
            'notes' => 'Forklift handling deformation discovered before dispatch.',
        ]]);
        $movements->createAndPost([
            ...$context,
            'branch_store_id' => $resources['finished_store']->getKey(),
            'document_type' => InventoryDocument::TypeScrap,
            'source_stock_status' => InventoryTransaction::StatusDamaged,
            'purpose' => 'Approved warehouse scrap disposal',
            'movement_reason' => 'Runtime demo damaged pail scrap',
            'notes' => $this->note('Two damaged pails scrapped with a canonical inventory-loss journal.'),
        ], [[
            'product_id' => $pail->getKey(), 'quantity' => '2',
            'notes' => 'Irrecoverably deformed finished goods.',
        ]]);
        $movements->createAndPost([
            ...$context,
            'branch_store_id' => $resources['raw_store']->getKey(),
            'destination_branch_store_id' => $packingStore->getKey(),
            'document_type' => InventoryDocument::TypeTransfer,
            'source_stock_status' => InventoryTransaction::StatusAvailable,
            'destination_stock_status' => InventoryTransaction::StatusAvailable,
            'purpose' => 'Internal production-staging transfer',
            'movement_reason' => 'Runtime demo inter-store transfer',
            'notes' => $this->note('Fifty kilograms transferred to packing for manual inventory tracing.'),
        ], [[
            'product_id' => $resin->getKey(), 'quantity' => '50', 'batch_lot' => 'OPEN-PP-260101',
            'notes' => 'Traceable transfer from raw-material storage to the packaging store.',
        ]]);

        $stockCounts = app(StockCountService::class);
        $count = $stockCounts->createSnapshot([
            'company_id' => $resources['company']->getKey(),
            'financial_period_id' => $period->getKey(),
            'branch_id' => $resources['branch']->getKey(),
            'branch_store_id' => $resources['finished_store']->getKey(),
            'count_date' => '2026-08-25',
            'stock_status' => InventoryTransaction::StatusAvailable,
            'product_ids' => [$pail->getKey()],
            'notes' => $this->note('Cycle count with one verified unit shortage.'),
        ]);
        $varianceRecorded = false;
        $values = $count->lines->mapWithKeys(function ($line) use (&$varianceRecorded): array {
            $physicalQuantity = (string) $line->system_quantity;
            $reason = null;

            if (! $varianceRecorded && bccomp($physicalQuantity, '1', 8) >= 0) {
                $physicalQuantity = bcsub($physicalQuantity, '1', 8);
                $reason = 'One pail missing during signed cycle count.';
                $varianceRecorded = true;
            }

            return [$line->getKey() => [
                'physical_quantity' => $physicalQuantity,
                'variance_reason' => $reason,
                'notes' => $this->note('Physical quantity recorded by the warehouse count team.'),
            ]];
        })->all();
        $stockCounts->recordCount($count, $values);
        $stockCounts->approve($count->fresh());
    }

    private function additionalInventoryMovementsExist(Company $company): bool
    {
        return InventoryDocument::query()
            ->where('company_id', $company->getKey())
            ->where('movement_reason', 'Runtime demo inter-store transfer')
            ->exists();
    }

    /** @param array<string, mixed> $resources */
    private function seedFixedAssets(Branch $branch, FinancialPeriod $period, array $resources): void
    {
        $company = $resources['company'];
        $partnerAccounts = app(BusinessPartnerAccountService::class);
        $assetGroup = Account::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Injection Molding Equipment - Runtime Demo')
            ->first();
        if (! $assetGroup instanceof Account) {
            $assetGroup = $partnerAccounts->createGroup(
                BusinessPartnerAccountService::FixedAsset,
                'Injection Molding Equipment - Runtime Demo',
                $this->note('Plastic factory productive equipment group.'),
            );
        }
        $accumulatedDepreciation = $this->childPostingAccount(
            $company,
            '122',
            '12201',
            'Injection Equipment Accumulated Depreciation',
        );
        $clearing = $this->childPostingAccount($company, '116', '11601', 'Fixed Asset Acquisition and Disposal Clearing');
        FixedAssetCategoryMapping::query()->updateOrCreate(
            ['company_id' => $company->getKey(), 'asset_group_account_id' => $assetGroup->getKey()],
            [
                'accumulated_depreciation_account_id' => $accumulatedDepreciation->getKey(),
                'depreciation_expense_account_id' => $this->postingAccount($company, '531')->getKey(),
                'disposal_gain_account_id' => $this->postingAccount($company, '432')->getKey(),
                'disposal_loss_account_id' => $this->postingAccount($company, '551')->getKey(),
                'disposal_clearing_account_id' => $clearing->getKey(),
                'created_by' => $resources['user']->getKey(),
                'updated_by' => $resources['user']->getKey(),
            ],
        );

        $machine = app(FixedAssetService::class)->create([
            'asset_date' => '2026-01-01',
            'asset_name' => '450 Ton Injection Molding Machine - Runtime Demo',
            'entry_type' => FixedAsset::EntryTypeOpeningAsset,
            'asset_group_account_doc_num' => $assetGroup->doc_num,
            'credit_account_doc_num' => $clearing->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'branch_hall_uuid' => $resources['hall']->public_uuid,
            'cost_center_doc_num' => $resources['injection_cost_center']->doc_num,
            'description' => 'Primary injection machine connected to production resource IMM-450-01.',
            'serial_number' => 'IMM450-MGY-2024-001',
            'purchase_date' => '2024-01-01',
            'acquisition_date' => '2024-01-01',
            'operation_date' => '2024-01-01',
            'status' => FixedAsset::StatusActive,
            'currency_doc_num' => $resources['egp']->doc_num,
            'exchange_rate' => 1,
            'purchase_value' => '2400000',
            'salvage_value' => '240000',
            'previous_depreciation' => '600000',
            'previous_depreciation_until_date' => '2025-12-31',
            'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
            'useful_life' => '8',
            'annual_depreciation_rate' => '12.5',
            'is_depreciable' => true,
            'location_address' => 'Injection Hall, Bay 1',
            'notes' => $this->note('Opening asset with historical depreciation and January 2026 depreciation.'),
        ])['record'];
        app(FixedAssetService::class)->create([
            'asset_date' => '2026-03-01',
            'asset_name' => 'High Pressure Air Compressor - Runtime Demo',
            'entry_type' => FixedAsset::EntryTypeNewAsset,
            'asset_group_account_doc_num' => $assetGroup->doc_num,
            'credit_account_doc_num' => $clearing->doc_num,
            'branch_doc_num' => $branch->doc_num,
            'branch_hall_uuid' => $resources['hall']->public_uuid,
            'cost_center_doc_num' => $resources['injection_cost_center']->doc_num,
            'description' => 'Draft acquisition ready for manual activation testing.',
            'serial_number' => 'COMP-MGY-DEMO-001',
            'purchase_date' => '2026-03-01',
            'acquisition_date' => '2026-03-01',
            'operation_date' => '2026-03-01',
            'status' => FixedAsset::StatusDraft,
            'currency_doc_num' => $resources['egp']->doc_num,
            'exchange_rate' => 1,
            'purchase_value' => '180000',
            'salvage_value' => '18000',
            'previous_depreciation' => 0,
            'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
            'useful_life' => '6',
            'annual_depreciation_rate' => '16.6667',
            'is_depreciable' => true,
            'location_address' => 'Utilities Room',
            'notes' => $this->note('Draft asset for acquisition workflow testing.'),
        ]);

        app(FixedAssetDepreciationService::class)->post([
            'financial_period_doc_num' => $period->doc_num,
            'posting_date' => '2026-01-31',
            'asset_doc_nums' => [$machine->doc_num],
        ]);
    }

    /** @param array<string, mixed> $resources */
    private function seedOpeningGeneralLedger(FinancialPeriod $period, array $resources): void
    {
        $company = $resources['company'];
        $asset = FixedAsset::query()
            ->where('company_id', $company->getKey())
            ->where('serial_number', 'IMM450-MGY-2024-001')
            ->firstOrFail();
        $accumulatedDepreciation = FixedAssetCategoryMapping::query()
            ->where('company_id', $company->getKey())
            ->where('asset_group_account_id', $asset->asset_group_account_id)
            ->firstOrFail()
            ->accumulatedDepreciationAccount()
            ->firstOrFail();

        $lines = [
            [$this->postingAccount($company, '1131'), 'debit', '388500', 'Raw materials opening value'],
            [$this->postingAccount($company, '1134'), 'debit', '24000', 'Packaging opening value'],
            [$this->postingAccount($company, '1133'), 'debit', '37500', 'Finished goods opening value'],
            [$resources['cashbox']->account, 'debit', '500000', 'Factory cash opening balance'],
            [$resources['bank_account']->account, 'debit', '1500000', 'Bank opening balance'],
            [$asset->account, 'debit', '2400000', 'Injection machine historical cost'],
            [$accumulatedDepreciation, 'credit', '600000', 'Accumulated depreciation through 2025'],
            [$this->postingAccount($company, '31'), 'credit', '4250000', 'Opening owner equity'],
        ];
        $opening = app(OpeningBalanceService::class)->create([
            'currency_doc_num' => $resources['egp']->doc_num,
            'document_date' => $period->from_date->toDateString(),
            'exchange_rate' => 1,
            'description' => 'Reconciled FY 2026 opening trial balance',
            'notes' => $this->note('Balances inventory, liquidity, fixed assets, accumulated depreciation, and equity.'),
            'lines' => collect($lines)->map(fn (array $line): array => [
                'account_doc_num' => $line[0]->doc_num,
                'transaction_type' => $line[1],
                'amount' => $line[2],
                'description' => $line[3],
                'branch_id' => $resources['branch']->getKey(),
                'cost_center_id' => $line[0]->is($asset->account) ? $resources['injection_cost_center']->getKey() : null,
                'bank_account_id' => $line[0]->is($resources['bank_account']->account) ? $resources['bank_account']->getKey() : null,
            ])->all(),
        ])['record'];
        app(OpeningBalanceApprovalService::class)->approve($opening);
    }

    /** @param array<string, mixed> $resources */
    private function seedAdditionalAssets(Branch $branch, FinancialPeriod $period, array $resources): void
    {
        $company = $resources['company'];
        $partnerAccounts = app(BusinessPartnerAccountService::class);
        $root = $partnerAccounts->rootAccount(BusinessPartnerAccountService::FixedAsset);
        $accumulatedDepreciation = $this->childPostingAccount($company, '122', '12201', 'Injection Equipment Accumulated Depreciation');
        $clearing = $this->childPostingAccount($company, '116', '11601', 'Fixed Asset Acquisition and Disposal Clearing');
        $groups = [];

        foreach (['Machinery', 'Vehicles and Material Handling', 'Office Equipment', 'Furniture', 'Land and Buildings'] as $name) {
            $groupName = $name.' - Runtime Demo';
            $group = Account::query()->where('company_id', $company->getKey())->where('parent_id', $root->getKey())->where('name', $groupName)->first();
            $group ??= $partnerAccounts->createGroup(BusinessPartnerAccountService::FixedAsset, $groupName, $this->note('Fixed Asset category for the integrated factory register.'));
            FixedAssetCategoryMapping::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'asset_group_account_id' => $group->getKey()],
                [
                    'accumulated_depreciation_account_id' => $accumulatedDepreciation->getKey(),
                    'depreciation_expense_account_id' => $this->postingAccount($company, '531')->getKey(),
                    'disposal_gain_account_id' => $this->postingAccount($company, '432')->getKey(),
                    'disposal_loss_account_id' => $this->postingAccount($company, '551')->getKey(),
                    'disposal_clearing_account_id' => $clearing->getKey(),
                    'created_by' => $resources['user']->getKey(),
                    'updated_by' => $resources['user']->getKey(),
                ],
            );
            $groups[$name] = $group;
        }

        $definitions = [
            ['Automatic Packing Line', 'PACK-LINE-2024-01', 'Machinery', '650000', '65000', '52000', true, '8', 'Packing Hall'],
            ['Factory Forklift 3 Ton', 'FLT-MGY-2023-01', 'Vehicles and Material Handling', '380000', '38000', '95000', true, '6', 'Finished Goods Warehouse'],
            ['Isuzu Delivery Truck', 'TRK-MGY-2022-01', 'Vehicles and Material Handling', '720000', '72000', '216000', true, '7', 'Dispatch Yard'],
            ['Quality Laboratory Equipment', 'LAB-MGY-2025-01', 'Office Equipment', '250000', '25000', '25000', true, '5', 'Quality Laboratory'],
            ['Administration Workstations', 'IT-MGY-2025-01', 'Office Equipment', '180000', '18000', '36000', true, '4', 'Main Administration'],
            ['Factory Office Furniture', 'FUR-MGY-2024-01', 'Furniture', '120000', '12000', '24000', true, '8', 'Main Administration'],
            ['Industrial Land Plot A3', 'LAND-MGY-A3-18', 'Land and Buildings', '3000000', '0', '0', false, '0', '10th of Ramadan Industrial Zone'],
            ['Backup Power Generator', 'GEN-MGY-2024-01', 'Machinery', '420000', '42000', '52500', true, '8', 'Utilities Yard'],
        ];

        foreach ($definitions as [$name, $serial, $groupName, $value, $salvage, $previousDepreciation, $depreciable, $life, $location]) {
            if (FixedAsset::query()->where('company_id', $company->getKey())->where('serial_number', $serial)->exists()) {
                continue;
            }

            app(FixedAssetService::class)->create([
                'asset_date' => $period->from_date->toDateString(),
                'asset_name' => $name.' - Runtime Demo',
                'entry_type' => FixedAsset::EntryTypeOpeningAsset,
                'asset_group_account_doc_num' => $groups[$groupName]->doc_num,
                'credit_account_doc_num' => $clearing->doc_num,
                'branch_doc_num' => $branch->doc_num,
                'branch_hall_uuid' => $resources['hall']->public_uuid,
                'cost_center_doc_num' => $resources['injection_cost_center']->doc_num,
                'description' => $this->note('Persistent factory asset for lifecycle, register, and reporting screens.'),
                'serial_number' => $serial,
                'purchase_date' => '2024-01-01',
                'acquisition_date' => '2024-01-01',
                'operation_date' => '2024-01-01',
                'status' => FixedAsset::StatusActive,
                'currency_doc_num' => $resources['egp']->doc_num,
                'exchange_rate' => 1,
                'purchase_value' => $value,
                'salvage_value' => $salvage,
                'previous_depreciation' => $previousDepreciation,
                'previous_depreciation_until_date' => $depreciable ? '2025-12-31' : null,
                'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
                'useful_life' => $life,
                'annual_depreciation_rate' => $depreciable ? '12.5' : '0',
                'is_depreciable' => $depreciable,
                'location_address' => $location,
                'notes' => $this->note('Visible persistent asset with reconciled opening attributes.'),
            ]);
        }
    }

    /** @param array<string, mixed> $resources */
    private function seedFixedAssetLifecycle(Branch $branch, FinancialPeriod $period, array $resources): void
    {
        $company = $resources['company'];
        $assetGroup = Account::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Injection Molding Equipment - Runtime Demo')
            ->firstOrFail();
        $clearing = $this->childPostingAccount($company, '116', '11601', 'Fixed Asset Acquisition and Disposal Clearing');
        $goldenAsset = FixedAsset::query()
            ->where('company_id', $company->getKey())
            ->where('serial_number', 'LIFECYCLE-MGY-2025-001')
            ->first();

        if (! $goldenAsset instanceof FixedAsset) {
            $goldenAsset = app(FixedAssetService::class)->create([
                'asset_date' => $period->from_date->toDateString(),
                'asset_name' => 'Golden Cycle Auxiliary Injection Machine - Runtime Demo',
                'entry_type' => FixedAsset::EntryTypeOpeningAsset,
                'asset_group_account_doc_num' => $assetGroup->doc_num,
                'credit_account_doc_num' => $clearing->doc_num,
                'branch_doc_num' => $branch->doc_num,
                'branch_hall_uuid' => $resources['hall']->public_uuid,
                'cost_center_doc_num' => $resources['injection_cost_center']->doc_num,
                'description' => 'Opening asset used for activation, depreciation, transfer, and sale verification.',
                'serial_number' => 'LIFECYCLE-MGY-2025-001',
                'purchase_date' => '2024-01-01',
                'acquisition_date' => '2024-01-01',
                'operation_date' => '2024-01-01',
                'status' => FixedAsset::StatusDraft,
                'currency_doc_num' => $resources['egp']->doc_num,
                'exchange_rate' => 1,
                'purchase_value' => '1200000',
                'salvage_value' => '120000',
                'previous_depreciation' => '240000',
                'previous_depreciation_until_date' => '2025-12-31',
                'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
                'useful_life' => '10',
                'annual_depreciation_rate' => '10',
                'is_depreciable' => true,
                'location_address' => 'Injection Hall, Auxiliary Bay',
                'notes' => $this->note('Golden Fixed Asset lifecycle source record.'),
            ])['record'];
        }

        $compressor = FixedAsset::query()
            ->where('company_id', $company->getKey())
            ->where('serial_number', 'COMP-MGY-DEMO-001')
            ->firstOrFail();

        if ($compressor->status === FixedAsset::StatusDraft && $compressor->entry_type !== FixedAsset::EntryTypeOpeningAsset) {
            app(FixedAssetService::class)->update($compressor, [
                'asset_date' => $period->from_date->toDateString(),
                'asset_name' => 'High Pressure Air Compressor - Runtime Demo',
                'entry_type' => FixedAsset::EntryTypeOpeningAsset,
                'asset_group_account_doc_num' => $assetGroup->doc_num,
                'credit_account_doc_num' => $clearing->doc_num,
                'branch_doc_num' => $branch->doc_num,
                'branch_hall_uuid' => $resources['hall']->public_uuid,
                'cost_center_doc_num' => $resources['injection_cost_center']->doc_num,
                'description' => 'Draft opening asset ready for manual activation testing.',
                'serial_number' => 'COMP-MGY-DEMO-001',
                'purchase_date' => '2025-03-01',
                'acquisition_date' => '2025-03-01',
                'operation_date' => '2025-03-01',
                'status' => FixedAsset::StatusDraft,
                'currency_doc_num' => $resources['egp']->doc_num,
                'exchange_rate' => 1,
                'purchase_value' => '180000',
                'salvage_value' => '18000',
                'previous_depreciation' => 0,
                'depreciation_method' => FixedAsset::DepreciationMethodStraightLine,
                'useful_life' => '6',
                'annual_depreciation_rate' => '16.6667',
                'is_depreciable' => true,
                'location_address' => 'Utilities Room',
                'notes' => $this->note('Draft opening asset for activation workflow testing.'),
            ]);
        }

        $this->seedAdditionalFixedAssetOpeningLedger($branch, $period, $resources);

        $lifecycle = app(FixedAssetLifecycleService::class);
        $depreciation = app(FixedAssetDepreciationService::class);

        if ($goldenAsset->status === FixedAsset::StatusDraft) {
            $goldenAsset = $lifecycle->activate($goldenAsset, '2026-01-02');
        }

        if (! FixedAssetDepreciation::query()
            ->where('fixed_asset_id', $goldenAsset->getKey())
            ->where('status', FixedAssetDepreciation::StatusPosted)
            ->whereDate('period_end', '2026-01-31')
            ->exists()
        ) {
            $depreciation->post([
                'financial_period_doc_num' => $period->doc_num,
                'posting_date' => '2026-01-31',
                'asset_doc_nums' => [$goldenAsset->doc_num],
            ]);
        }

        $distributionBranch = Branch::query()
            ->where('company_id', $company->getKey())
            ->where('name', '10th of Ramadan Distribution Warehouse - Runtime Demo')
            ->firstOrFail();
        $administrationCostCenter = CostCenter::query()
            ->where('company_id', $company->getKey())
            ->where('cost_center_code', '2001')
            ->where('is_group', false)
            ->firstOrFail();

        if (! $goldenAsset->movements()->where('reason', 'Runtime demo Fixed Asset golden-cycle transfer')->exists()) {
            $lifecycle->transfer($goldenAsset->refresh(), [
                'movement_date' => '2026-02-01',
                'destination_branch_doc_num' => $distributionBranch->doc_num,
                'destination_cost_center_doc_num' => $administrationCostCenter->doc_num,
                'destination_location_address' => 'Distribution Warehouse, Equipment Bay',
                'reason' => 'Runtime demo Fixed Asset golden-cycle transfer',
                'notes' => $this->note('Approved inter-branch custody and cost-center transfer.'),
            ]);
        }

        if (! FixedAssetDepreciation::query()
            ->where('fixed_asset_id', $goldenAsset->getKey())
            ->where('status', FixedAssetDepreciation::StatusPosted)
            ->whereDate('period_end', '2026-02-28')
            ->exists()
        ) {
            $depreciation->post([
                'financial_period_doc_num' => $period->doc_num,
                'posting_date' => '2026-02-28',
                'asset_doc_nums' => [$goldenAsset->doc_num],
            ]);
        }

        if (! FixedAssetDisposal::query()->where('fixed_asset_id', $goldenAsset->getKey())->exists()) {
            $lifecycle->dispose($goldenAsset->refresh(), [
                'disposal_date' => '2026-03-15',
                'disposition_type' => FixedAssetDisposal::TypeSale,
                'proceeds' => '990000',
                'proceeds_account_doc_num' => $resources['bank_account']->account->doc_num,
                'reason' => 'Sold after completion of the auxiliary line replacement program',
                'notes' => $this->note('Golden-cycle sale with a balanced gain or loss journal.'),
            ]);
        }

        $reversedWriteOffAsset = FixedAsset::query()
            ->where('company_id', $company->getKey())
            ->where('serial_number', 'GEN-MGY-2024-01')
            ->firstOrFail();
        $reversedWriteOff = FixedAssetDisposal::query()
            ->where('fixed_asset_id', $reversedWriteOffAsset->getKey())
            ->where('disposition_type', FixedAssetDisposal::TypeWriteOff)
            ->first();

        if (! $reversedWriteOff instanceof FixedAssetDisposal) {
            $reversedWriteOff = $lifecycle->dispose($reversedWriteOffAsset, [
                'disposal_date' => '2026-04-15',
                'disposition_type' => FixedAssetDisposal::TypeWriteOff,
                'proceeds' => '0',
                'reason' => 'Initial damage assessment indicated a total loss',
                'notes' => $this->note('Write-off subsequently reversed after a successful technical reassessment.'),
            ]);
        }

        if ($reversedWriteOff->status === FixedAssetDisposal::StatusPosted) {
            $lifecycle->reverseDisposal($reversedWriteOff, 'Technical reassessment confirmed that the generator is repairable.');
        }

        $writtenOffAsset = FixedAsset::query()
            ->where('company_id', $company->getKey())
            ->where('serial_number', 'IT-MGY-2025-01')
            ->firstOrFail();

        if (! FixedAssetDisposal::query()->where('fixed_asset_id', $writtenOffAsset->getKey())->exists()) {
            $lifecycle->dispose($writtenOffAsset, [
                'disposal_date' => '2026-05-15',
                'disposition_type' => FixedAssetDisposal::TypeWriteOff,
                'proceeds' => '0',
                'reason' => 'Obsolete workstations failed the economic repair assessment',
                'notes' => $this->note('Approved permanent write-off with a balanced loss journal.'),
            ]);
        }
    }

    /** @param array<string, mixed> $resources */
    private function seedAdditionalFixedAssetOpeningLedger(Branch $branch, FinancialPeriod $period, array $resources): void
    {
        $description = 'Additional Fixed Asset opening reconciliation - Runtime Demo';

        if (OpeningBalance::query()
            ->where('company_id', $resources['company']->getKey())
            ->where('financial_period_id', $period->getKey())
            ->where('description', $description)
            ->exists()
        ) {
            return;
        }

        $serialNumbers = [
            'PACK-LINE-2024-01',
            'FLT-MGY-2023-01',
            'TRK-MGY-2022-01',
            'LAB-MGY-2025-01',
            'IT-MGY-2025-01',
            'FUR-MGY-2024-01',
            'LAND-MGY-A3-18',
            'GEN-MGY-2024-01',
            'LIFECYCLE-MGY-2025-001',
            'COMP-MGY-DEMO-001',
        ];
        $assets = FixedAsset::query()
            ->where('company_id', $resources['company']->getKey())
            ->whereIn('serial_number', $serialNumbers)
            ->with('categoryMapping')
            ->orderBy('id')
            ->get();
        $lines = [];
        $grossValue = '0.0000';
        $accumulatedValue = '0.0000';

        foreach ($assets as $asset) {
            $grossValue = bcadd($grossValue, (string) $asset->base_acquisition_value, 4);
            $lines[] = [
                'account_doc_num' => $asset->account->doc_num,
                'transaction_type' => 'debit',
                'amount' => $asset->base_acquisition_value,
                'description' => 'Opening cost: '.$asset->asset_name,
                'branch_id' => $asset->branch_id,
                'cost_center_id' => $asset->cost_center_id,
            ];
        }

        $accumulatedGroups = $assets
            ->filter(fn (FixedAsset $asset): bool => bccomp((string) $asset->previous_depreciation, '0', 4) > 0)
            ->groupBy(fn (FixedAsset $asset): string => implode(':', [
                $asset->categoryMapping?->accumulated_depreciation_account_id,
                $asset->branch_id,
                $asset->cost_center_id,
            ]));

        foreach ($accumulatedGroups as $groupAssets) {
            $first = $groupAssets->firstOrFail();
            $amount = $groupAssets->reduce(
                fn (string $carry, FixedAsset $asset): string => bcadd($carry, (string) $asset->previous_depreciation, 4),
                '0.0000',
            );
            $accumulatedValue = bcadd($accumulatedValue, $amount, 4);
            $lines[] = [
                'account_doc_num' => $first->categoryMapping->accumulatedDepreciationAccount->doc_num,
                'transaction_type' => 'credit',
                'amount' => $amount,
                'description' => 'Accumulated depreciation through 2025 for additional assets',
                'branch_id' => $first->branch_id,
                'cost_center_id' => $first->cost_center_id,
            ];
        }

        $lines[] = [
            'account_doc_num' => $this->postingAccount($resources['company'], '31')->doc_num,
            'transaction_type' => 'credit',
            'amount' => bcsub($grossValue, $accumulatedValue, 4),
            'description' => 'Opening equity supporting the additional Fixed Asset register',
            'branch_id' => $branch->getKey(),
        ];

        $opening = app(OpeningBalanceService::class)->create([
            'currency_doc_num' => $resources['egp']->doc_num,
            'document_date' => $period->from_date->toDateString(),
            'exchange_rate' => 1,
            'description' => $description,
            'notes' => $this->note('Canonical opening-balance support for Fixed Asset subledger-to-GL reconciliation.'),
            'lines' => $lines,
        ])['record'];
        app(OpeningBalanceApprovalService::class)->approve($opening);
    }

    /** @param array<string, mixed> $resources */
    private function seedFundTransfer(array $resources): void
    {
        $transfers = app(FundTransferService::class);
        $transfer = $transfers->create([
            'transfer_date' => '2026-06-01',
            'source_type' => FundTransfer::HolderBankAccount,
            'source_bank_account_doc_num' => $resources['bank_account']->doc_num,
            'target_type' => FundTransfer::HolderCashbox,
            'target_cashbox_doc_num' => $resources['cashbox']->doc_num,
            'source_currency_doc_num' => $resources['egp']->doc_num,
            'target_currency_doc_num' => $resources['egp']->doc_num,
            'source_amount' => '50000', 'exchange_rate' => 1, 'target_amount' => '50000',
            'reason' => 'Factory petty-cash replenishment',
            'description' => $this->note('Approved bank-to-cash transfer for finance screen testing.'),
        ])['record'];
        $transfers->approve($transfer);
    }

    private function goldenCyclesExist(Company $company): bool
    {
        return PurchaseRequisition::query()
            ->where('company_id', $company->getKey())
            ->where('notes', 'like', '%'.self::Marker.'%')
            ->exists()
            && ProductionOrder::query()
                ->where('company_id', $company->getKey())
                ->where('production_notes', 'like', '%'.self::Marker.'%')
                ->exists()
            && Quotation::query()
                ->where('company_id', $company->getKey())
                ->where('customer_reference', 'CP-PO-DEMO-260705')
                ->exists();
    }

    private function activateContext(Company $company, Branch $branch, FinancialPeriod $period): void
    {
        if (! request()->hasSession()) {
            request()->setLaravelSession(app('session.store'));
        }

        session([
            OperatingContextService::CompanyIdKey => $company->getKey(),
            OperatingContextService::CompanyDocNumKey => $company->doc_num,
            OperatingContextService::BranchIdKey => $branch->getKey(),
            OperatingContextService::BranchDocNumKey => $branch->doc_num,
            OperatingContextService::FinancialPeriodIdKey => $period->getKey(),
            OperatingContextService::FinancialPeriodDocNumKey => $period->doc_num,
        ]);
    }

    private function store(Branch $branch, string $name, int $position, User $user): BranchStore
    {
        return BranchStore::query()->firstOrCreate(
            ['branch_id' => $branch->getKey(), 'name' => $name],
            ['position' => $position, 'created_by' => $user->getKey()],
        );
    }

    private function costCenter(
        Company $company,
        CostCenter $parent,
        string $code,
        string $name,
        User $user,
    ): CostCenter {
        $costCenter = CostCenter::query()
            ->where('company_id', $company->getKey())
            ->where('cost_center_code', $code)
            ->first();
        if ($costCenter instanceof CostCenter) {
            return $costCenter;
        }

        return CostCenter::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('cost_centers', CostCenter::class, $company->getKey()),
            'company_id' => $company->getKey(), 'parent_id' => $parent->getKey(),
            'cost_center_code' => $code, 'name' => $name, 'is_group' => false, 'status' => 'active',
            'notes' => $this->note('Operational cost center for demo reporting.'), 'created_by' => $user->getKey(),
        ]);
    }

    private function postingAccount(Company $company, string $accountCode): Account
    {
        return Account::query()
            ->where('company_id', $company->getKey())
            ->where('account_code', $accountCode)
            ->where('status', 'active')
            ->where('is_group', false)
            ->where('is_postable', true)
            ->firstOrFail();
    }

    private function childPostingAccount(
        Company $company,
        string $parentCode,
        string $accountCode,
        string $name,
    ): Account {
        $account = Account::query()
            ->where('company_id', $company->getKey())
            ->where('account_code', $accountCode)
            ->first();
        if ($account instanceof Account) {
            return $account;
        }
        $parent = Account::query()
            ->where('company_id', $company->getKey())
            ->where('account_code', $parentCode)
            ->firstOrFail();

        return Account::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
            'company_id' => $company->getKey(), 'account_code' => $accountCode,
            'name' => $name, 'name_en' => $name, 'parent_id' => $parent->getKey(), 'level' => ((int) $parent->level) + 1,
            'account_classification_id' => $parent->account_classification_id, 'account_type' => $parent->account_type,
            'statement_type' => $parent->statement_type, 'normal_balance' => $parent->normal_balance,
            'is_group' => false, 'is_postable' => true, 'is_system' => false, 'status' => 'active',
            'notes' => $this->note('Posting account created for fixed asset accounting.'), 'created_by' => auth()->id(),
        ]);
    }

    private function product(Company $company, string $barcode): Product
    {
        return Product::query()
            ->with('unit')
            ->where('company_id', $company->getKey())
            ->where('barcode', $barcode)
            ->firstOrFail();
    }

    private function note(string $description): string
    {
        return '['.self::Marker.'] '.$description;
    }
}
