<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Database\Seeders\BaselineCostCentersSeeder;
use Modules\Accounting\Database\Seeders\DefaultChartOfAccountsSeeder;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Accounting\Services\JournalEntryService;
use Modules\Auth\Database\Seeders\PermissionSeeder;
use Modules\Core\Database\Seeders\CurrencySeeder;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\BranchStore;
use Modules\Core\Models\Company;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\BankAccount;
use Modules\Finance\Models\Cashbox;
use Modules\Finance\Models\CashboxCurrency;
use Modules\Finance\Services\ChequeService;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\FixedAssetDisposal;
use Modules\FixedAssets\Services\FixedAssetDepreciationService;
use Modules\FixedAssets\Services\FixedAssetLifecycleService;
use Modules\FixedAssets\Services\FixedAssetService;
use Modules\Inventory\Models\InventoryTransaction;
use Modules\Inventory\Models\OpeningStock;
use Modules\Inventory\Models\WarehouseLocation;
use Modules\Inventory\Services\OpeningStockPricingService;
use Modules\Inventory\Services\OpeningStockService;
use Modules\Purchases\Models\PurchaseInvoice;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\Supplier;
use Modules\Purchases\Models\SupplierPaymentContext;
use Modules\Purchases\Services\ProcurementReceivingService;
use Modules\Purchases\Services\ProcurementSettlementService;
use Modules\Purchases\Services\ProcurementSourcingService;
use Modules\Purchases\Services\PurchaseInvoiceCalculationService;
use Modules\Purchases\Services\PurchaseInvoiceService;
use Modules\Purchases\Services\PurchaseOrderService;
use Modules\Purchases\Services\SupplierService;

class FixedAssetsProcurementClientDemoSeeder extends Seeder
{
    public const Marker = 'client_demo_fa_proc_v1';

    public const GoldenAssetSerial = 'CD-ASSET-GOLDEN-120K';

    public const GoldenRequisitionNote = '[client_demo_fa_proc_v1] Golden 10,000 kg split-award procurement cycle.';

    public const DraftPurchaseOrderNote = '[client_demo_fa_proc_v1] Draft purchase order intentionally available for manual continuation.';

    public const DraftInvoiceNote = '[client_demo_fa_proc_v1] Draft supplier invoice intentionally available for manual continuation.';

    public const UnpaidInvoiceNote = '[client_demo_fa_proc_v1] Posted unpaid overdue service invoice for aging and due-payment demonstrations.';

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            throw new \RuntimeException('Client demo data may only be created in local, development, or testing environments.');
        }

        $this->call([
            CurrencySeeder::class,
            DefaultChartOfAccountsSeeder::class,
            BaselineCostCentersSeeder::class,
            PermissionSeeder::class,
        ]);

        $company = Company::query()->active()->orderBy('id')->firstOrFail();
        $branch = Branch::query()->where('company_id', $company->getKey())->where('status', 'active')->orderBy('id')->firstOrFail();
        $period = FinancialPeriod::query()->where('company_id', $company->getKey())->where('is_closed', false)->orderByDesc('from_date')->firstOrFail();
        $currency = Currency::query()->where('company_id', $company->getKey())->where('code', 'EGP')->firstOrFail();
        $user = User::query()->where('username', 'admin')->first()
            ?? User::query()->where('status', 'active')->orderBy('id')->firstOrFail();
        $previousUser = Auth::user();

        Auth::login($user);
        request()->setUserResolver(fn (): User => $user);
        $this->activateContext($company, $branch, $period);

        try {
            DB::transaction(function () use ($branch, $company, $currency, $period, $user): void {
                $resources = $this->seedInfrastructure($company, $branch, $period, $currency, $user);
                $this->activateContext($company, $resources['factoryBranch'], $period);
                $products = $this->seedProducts($company, $resources['kilogram'], $resources['piece']);
                $suppliers = $this->seedSuppliers($company, $currency);

                $this->seedOpeningInventory($resources['factoryBranch'], $currency, $resources['raw_store'], $products);

                if (! FixedAsset::query()->where('company_id', $company->getKey())->where('serial_number', self::GoldenAssetSerial)->exists()) {
                    $this->seedFixedAssets($branch, $period, $currency, $user, $resources);
                }

                if (! PurchaseRequisition::query()->where('company_id', $company->getKey())->where('notes', self::GoldenRequisitionNote)->exists()) {
                    $this->seedProcurement($period, $currency, $resources, $products, $suppliers);
                }

                $this->seedOpenProcurementStates($period, $currency, $resources, $products, $suppliers);
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

        $this->command?->info('Fixed Assets and Procurement client-demo data are ready.');
    }

    /** @return array<string, mixed> */
    private function seedInfrastructure(Company $company, Branch $branch, FinancialPeriod $period, Currency $currency, User $user): array
    {
        $company->forceFill(array_filter([
            'legal_name' => blank($company->legal_name) ? $company->name : null,
            'commercial_register_number' => blank($company->commercial_register_number) ? 'CD-CR-2026-001' : null,
            'vat_registration_number' => blank($company->vat_registration_number) ? 'CD-VAT-2026-001' : null,
            'phone' => blank($company->phone) ? '+20 2 5555 2026' : null,
            'email' => blank($company->email) ? 'finance@client-demo.test' : null,
            'address' => blank($company->address) ? '10th of Ramadan Industrial Zone, Cairo, Egypt' : null,
            'authorized_signatory_name' => blank($company->authorized_signatory_name) ? 'Factory General Manager' : null,
            'authorized_signatory_title' => blank($company->authorized_signatory_title) ? 'General Manager' : null,
        ], fn (mixed $value): bool => $value !== null))->save();

        $previousYear = ((int) $period->from_date->format('Y')) - 1;
        FinancialPeriod::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'name' => "FY {$previousYear} — Client Demo Closed"],
            [
                ...app(DocumentNumberService::class)->nextForCompany('financial_periods', FinancialPeriod::class, $company->getKey()),
                'from_date' => "{$previousYear}-01-01",
                'to_date' => "{$previousYear}-12-31",
                'is_closed' => true,
                'allows_opening_entries' => false,
                'notes' => $this->note('Closed comparison period for period-lock demonstrations.'),
            ],
        );

        $factoryBranch = $this->branch($company, 'Main Plastic Factory — Client Demo', Branch::TypeFactory, 70, $user);
        $adminBranch = $this->branch($company, 'Cairo Administration — Client Demo', Branch::TypeAdministrative, 71, $user);
        $factoryHall = $this->hall($factoryBranch, 'Injection & Extrusion Hall — Client Demo', 70, $user);
        $adminHall = $this->hall($adminBranch, 'Administration Hall — Client Demo', 71, $user);
        $rawStore = $this->store($factoryBranch, 'Raw Materials Store — Client Demo', 70, $user);
        $packagingStore = $this->store($factoryBranch, 'Packaging Store — Client Demo', 71, $user);
        $quarantineStore = $this->store($factoryBranch, 'QC Quarantine Store — Client Demo', 72, $user);

        foreach ([
            [$rawStore, 'CD-RM-A01', 'Raw Resin Zone'],
            [$packagingStore, 'CD-PK-A01', 'Packaging Zone'],
            [$quarantineStore, 'CD-QC-A01', 'Rejected Material Zone'],
        ] as [$store, $code, $name]) {
            WarehouseLocation::query()->firstOrCreate(
                ['branch_store_id' => $store->getKey(), 'code' => $code],
                ['name' => $name, 'zone_code' => Str::before($code, '-A'), 'position' => 70, 'is_active' => true, 'created_by' => $user->getKey()],
            );
        }

        $productionRoot = CostCenter::query()->where('company_id', $company->getKey())->where('cost_center_code', CostCenter::RootProductionCode)->firstOrFail();
        $serviceRoot = CostCenter::query()->where('company_id', $company->getKey())->where('cost_center_code', CostCenter::RootServiceCode)->firstOrFail();
        $injection = $this->costCenter($company, $productionRoot, 'CD1001', 'Injection Molding — Client Demo', $user);
        $packing = $this->costCenter($company, $productionRoot, 'CD1002', 'Packing & Quality — Client Demo', $user);
        $administration = $this->costCenter($company, $serviceRoot, 'CD2001', 'Administration — Client Demo', $user);
        $procurement = $this->costCenter($company, $serviceRoot, 'CD2002', 'Procurement — Client Demo', $user);

        $cashAccount = $this->childPostingAccount($company, '1111', '1111901', 'Client Demo Factory Cash');
        $bankAccountGl = $this->childPostingAccount($company, '1112', '1112901', 'Client Demo Procurement Bank');
        $cashbox = Cashbox::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'name' => 'Factory Cashbox — Client Demo'],
            [
                ...app(DocumentNumberService::class)->nextForCompany('cashboxes', Cashbox::class, $company->getKey()),
                'branch_id' => $factoryBranch->getKey(), 'account_id' => $cashAccount->getKey(), 'status' => 'active',
                'notes' => $this->note('Cash supplier settlement holder.'),
            ],
        );
        CashboxCurrency::query()->firstOrCreate(
            ['cashbox_id' => $cashbox->getKey(), 'currency_id' => $currency->getKey()],
            ['is_default' => true, 'status' => 'active'],
        );
        $bankParent = Account::query()->where('company_id', $company->getKey())->where('account_code', '1112')->firstOrFail();
        $bankAccount = BankAccount::query()->firstOrCreate(
            ['company_id' => $company->getKey(), 'account_number' => 'CD-EGP-2026-001'],
            [
                ...app(DocumentNumberService::class)->nextForCompany('bank_accounts', BankAccount::class, $company->getKey()),
                'bank_id' => $bankParent->getKey(), 'account_id' => $bankAccountGl->getKey(), 'currency_id' => $currency->getKey(),
                'account_name' => 'Client Demo Procurement Current Account', 'bank_branch_name' => 'Cairo Industrial Branch',
                'iban' => 'EG380002000156789012345678901', 'swift_code' => 'BMISEGCX', 'owner_name' => $company->legal_name,
                'status' => 'active', 'notes' => $this->note('Bank and cheque supplier settlement holder.'),
            ],
        );

        return compact(
            'factoryBranch', 'adminBranch', 'factoryHall', 'adminHall', 'rawStore', 'packagingStore', 'quarantineStore',
            'injection', 'packing', 'administration', 'procurement', 'cashbox', 'bankAccount',
        ) + ['raw_store' => $rawStore, 'kilogram' => $this->unit($company, 'Kilogram — Client Demo', 990101), 'piece' => $this->unit($company, 'Piece — Client Demo', 990102)];
    }

    /** @return array<string, Product> */
    private function seedProducts(Company $company, ItemUnit $kilogram, ItemUnit $piece): array
    {
        $definitions = [
            'pp' => ['CD-RM-PP-HOMO', 'PP Homopolymer Injection Grade — Client Demo', Product::ClassificationRawMaterial, $kilogram],
            'pe' => ['CD-RM-HDPE-BLOW', 'HDPE Blow Molding Grade — Client Demo', Product::ClassificationRawMaterial, $kilogram],
            'blue' => ['CD-RM-MB-BLUE', 'Blue Masterbatch — Client Demo', Product::ClassificationRawMaterial, $kilogram],
            'black' => ['CD-RM-MB-BLACK', 'Black Masterbatch — Client Demo', Product::ClassificationRawMaterial, $kilogram],
            'carton' => ['CD-PK-CARTON', 'Printed Packing Carton — Client Demo', Product::ClassificationPackaging, $piece],
            'bag' => ['CD-PK-BAG', 'Food Grade Inner Bag — Client Demo', Product::ClassificationPackaging, $piece],
            'label' => ['CD-PK-LABEL', 'Thermal Product Label — Client Demo', Product::ClassificationPackaging, $piece],
            'tape' => ['CD-PK-TAPE', 'Packing Tape Roll — Client Demo', Product::ClassificationPackaging, $piece],
            'maintenance' => ['CD-SVC-MAINT', 'Injection Machine Maintenance — Client Demo', Product::ClassificationService, $piece],
            'freight' => ['CD-SVC-FREIGHT', 'Inbound Resin Freight — Client Demo', Product::ClassificationService, $piece],
        ];
        $products = [];

        foreach ($definitions as $key => [$barcode, $name, $classification, $unit]) {
            $product = Product::query()->where('company_id', $company->getKey())->where('barcode', $barcode)->first();

            if (! $product instanceof Product) {
                $product = Product::query()->create([
                    ...app(DocumentNumberService::class)->nextForCompany('products', Product::class, $company->getKey()),
                    'company_id' => $company->getKey(),
                    'name' => $name,
                    'barcode' => $barcode,
                    'item_classification' => $classification,
                    'item_unit_id' => $unit->getKey(),
                    'reorder_point' => $classification === Product::ClassificationService ? null : '500.0000',
                    'cost_as_inventory' => $classification !== Product::ClassificationService,
                    'is_displayable' => true,
                    'status' => 'active',
                    'notes' => $this->note('Selectable procurement demo item.'),
                ]);
            }

            $products[$key] = $product->load('unit');
        }

        return $products;
    }

    /** @return array<string, Supplier> */
    private function seedSuppliers(Company $company, Currency $currency): array
    {
        $definitions = [
            'supplier_a' => ['Nile Polymer Supply — Client Demo', 'Hossam Tarek', 30],
            'supplier_b' => ['Suez Petrochem Trading — Client Demo', 'Dina Samir', 45],
            'carton' => ['Delta Carton Industries — Client Demo', 'Karim Essam', 30],
            'masterbatch' => ['Cairo Masterbatch Industries — Client Demo', 'Rania Mostafa', 30],
            'packaging' => ['Nile Flexible Packaging — Client Demo', 'Wael Sobhy', 15],
            'maintenance' => ['Industrial Maintenance Solutions — Client Demo', 'Mahmoud Raouf', 0],
            'transport' => ['East Delta Transport Services — Client Demo', 'Yasser Gamal', 45],
        ];
        $suppliers = [];

        foreach ($definitions as $key => [$name, $contact, $terms]) {
            $supplier = Supplier::query()->where('company_id', $company->getKey())->where('name', $name)->first();

            if (! $supplier instanceof Supplier) {
                $supplier = app(SupplierService::class)->create([
                    'name' => $name,
                    'contact_person' => $contact,
                    'mobile' => '+20 100 '.str_pad((string) (9000000 + count($suppliers)), 7, '0', STR_PAD_LEFT),
                    'email' => Str::slug($name, '.').'@client-demo.test',
                    'tax_number' => 'CD-SUP-'.Str::upper(Str::substr(md5($name), 0, 9)),
                    'payment_terms_days' => $terms,
                    'address' => 'Cairo Industrial Supply Zone, Egypt',
                    'status' => 'active',
                    'credit_limits' => [[
                        'currency_doc_num' => $currency->doc_num,
                        'credit_limit' => '750000',
                        'notes' => $this->note('Approved supplier limit.'),
                    ]],
                    'notes' => $this->note('Active approved plastics supplier.'),
                ])['record'];
            }

            $suppliers[$key] = $supplier;
        }

        return $suppliers;
    }

    /** @param array<string, Product> $products */
    private function seedOpeningInventory(Branch $branch, Currency $currency, BranchStore $store, array $products): void
    {
        if (OpeningStock::query()->where('company_id', $branch->company_id)->where('notes', 'like', '%'.self::Marker.'%')->exists()) {
            return;
        }

        $definitions = [
            ['product' => $products['pp'], 'quantity' => '5000', 'unit_price' => '18', 'lot' => 'CD-OPEN-PP-001'],
            ['product' => $products['pe'], 'quantity' => '2500', 'unit_price' => '22', 'lot' => 'CD-OPEN-PE-001'],
        ];
        $opening = app(OpeningStockService::class)->create([
            'document_date' => now()->startOfYear()->toDateString(),
            'branch_store_uuid' => $store->public_uuid,
            'notes' => $this->note('Priced opening raw-material inventory.'),
            'lines' => collect($definitions)->map(fn (array $definition): array => [
                'product_doc_num' => $definition['product']->doc_num,
                'quantity' => $definition['quantity'],
                'stock_status' => InventoryTransaction::StatusAvailable,
                'batch_lot' => $definition['lot'],
                'manufacture_date' => now()->subYear()->endOfYear()->subDays(10)->toDateString(),
                'notes' => $this->note('Opening inventory layer.'),
            ])->all(),
        ])['record'];
        $opening = app(OpeningStockService::class)->approve($opening);
        $prices = collect($definitions)->keyBy(fn (array $definition): string => (string) $definition['product']->getKey());

        app(OpeningStockPricingService::class)->create([
            'branch_doc_num' => $branch->doc_num,
            'opening_stock_doc_num' => $opening->doc_num,
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'document_date' => now()->startOfYear()->toDateString(),
            'notes' => $this->note('Opening inventory valuation.'),
            'lines' => $opening->lines->map(function ($line) use ($prices): array {
                $definition = $prices->get((string) $line->product_id);

                return [
                    'opening_stock_line_public_id' => $line->public_id,
                    'unit_price' => $definition['unit_price'],
                    'notes' => $this->note('Approved opening unit cost.'),
                ];
            })->all(),
        ]);
    }

    /** @param array<string, mixed> $resources */
    private function seedFixedAssets(Branch $branch, FinancialPeriod $period, Currency $currency, User $user, array $resources): void
    {
        $company = $branch->company;
        $lifecycle = app(FixedAssetLifecycleService::class);
        $groups = $this->fixedAssetGroups($company);
        $accumulatedDepreciation = $this->childPostingAccount($company, '122', '1220901', 'Client Demo Accumulated Depreciation');
        $clearing = $this->childPostingAccount($company, '116', '1160901', 'Client Demo Asset Clearing');

        foreach ($groups as $group) {
            $lifecycle->configureCategoryMapping([
                'asset_group_account_doc_num' => $group->doc_num,
                'accumulated_depreciation_account_doc_num' => $accumulatedDepreciation->doc_num,
                'depreciation_expense_account_doc_num' => $this->postingAccount($company, '531')->doc_num,
                'disposal_gain_account_doc_num' => $this->postingAccount($company, '432')->doc_num,
                'disposal_loss_account_doc_num' => $this->postingAccount($company, '551')->doc_num,
                'disposal_clearing_account_doc_num' => $clearing->doc_num,
            ]);
        }

        $year = (int) $period->from_date->format('Y');
        $service = app(FixedAssetService::class);
        $golden = $this->asset($service, $groups['machinery'], $clearing, $currency, $resources, [
            'asset_date' => "{$year}-01-01", 'asset_name' => 'Golden Injection Machine — Client Demo',
            'serial_number' => self::GoldenAssetSerial, 'purchase_value' => '120000', 'salvage_value' => '12000',
            'useful_life' => '5', 'status' => FixedAsset::StatusDraft,
            'notes' => $this->note('Golden asset: 120,000 cost, 12,000 residual, activation, two depreciation runs, transfer, and sale.'),
        ]);
        $golden = $lifecycle->activate($golden, "{$year}-01-01");
        app(FixedAssetDepreciationService::class)->post([
            'financial_period_doc_num' => $period->doc_num, 'posting_date' => "{$year}-01-31", 'asset_doc_nums' => [$golden->doc_num],
        ]);
        $lifecycle->transfer($golden->fresh(), [
            'movement_date' => "{$year}-02-01",
            'destination_branch_doc_num' => $resources['adminBranch']->doc_num,
            'destination_branch_hall_uuid' => $resources['adminHall']->public_uuid,
            'destination_cost_center_doc_num' => $resources['administration']->doc_num,
            'destination_location_address' => 'Cairo Administration, Equipment Room',
            'reason' => 'Transferred after January depreciation for administrative production support.',
            'notes' => $this->note('Golden asset dimension transfer.'),
        ]);
        app(FixedAssetDepreciationService::class)->post([
            'financial_period_doc_num' => $period->doc_num, 'posting_date' => "{$year}-02-28", 'asset_doc_nums' => [$golden->doc_num],
        ]);
        $lifecycle->dispose($golden->fresh(), [
            'disposal_date' => "{$year}-03-15", 'disposition_type' => FixedAssetDisposal::TypeSale,
            'settlement_path' => FixedAssetDisposal::SettlementDirect, 'proceeds' => '110000',
            'proceeds_account_doc_num' => $resources['bankAccount']->account->doc_num,
            'reason' => 'Sold after two posted depreciation periods.', 'notes' => $this->note('Golden asset sale.'),
        ]);

        $opening = $this->asset($service, $groups['machinery'], $clearing, $currency, $resources, [
            'asset_date' => "{$year}-01-01", 'asset_name' => 'Opening Extrusion Line — Client Demo',
            'serial_number' => 'CD-ASSET-OPENING-100K', 'entry_type' => FixedAsset::EntryTypeOpeningAsset,
            'purchase_value' => '100000', 'salvage_value' => '10000', 'previous_depreciation' => '40000',
            'previous_depreciation_until_date' => ($year - 1).'-12-31', 'useful_life' => '10', 'status' => FixedAsset::StatusActive,
            'notes' => $this->note('Opening asset: historical cost 100,000 and accumulated depreciation 40,000.'),
        ]);
        app(JournalEntryService::class)->createPostedFromSource([
            'entry_date' => "{$year}-01-01", 'company_id' => $company->getKey(), 'financial_period_id' => $period->getKey(),
            'branch_id' => $branch->getKey(), 'currency_id' => $currency->getKey(), 'exchange_rate' => '1.000000',
            'description' => 'Client demo opening fixed asset reconciliation', 'notes' => $this->note('Opening fixed asset GL bridge.'),
            'source_type' => 'client_demo_opening_fixed_asset', 'source_id' => $opening->getKey(), 'source_doc_num' => $opening->doc_num,
        ], [
            ['account_id' => $opening->account_id, 'debit_amount' => '100000', 'credit_amount' => 0, 'description' => 'Historical asset cost', 'branch_id' => $branch->getKey(), 'cost_center_id' => $resources['injection']->getKey()],
            ['account_id' => $accumulatedDepreciation->getKey(), 'debit_amount' => 0, 'credit_amount' => '40000', 'description' => 'Historical accumulated depreciation', 'branch_id' => $branch->getKey(), 'cost_center_id' => $resources['injection']->getKey()],
            ['account_id' => $this->postingAccount($company, '31')->getKey(), 'debit_amount' => 0, 'credit_amount' => '60000', 'description' => 'Opening equity', 'branch_id' => $branch->getKey()],
        ]);

        $reversed = $this->asset($service, $groups['vehicles'], $clearing, $currency, $resources, [
            'asset_date' => "{$year}-01-05", 'asset_name' => 'Forklift Write-off Reversal — Client Demo', 'serial_number' => 'CD-ASSET-WO-REV',
            'purchase_value' => '45000', 'salvage_value' => '5000', 'status' => FixedAsset::StatusActive,
        ]);
        $reversedDisposal = $lifecycle->dispose($reversed, [
            'disposal_date' => "{$year}-04-01", 'disposition_type' => FixedAssetDisposal::TypeWriteOff,
            'settlement_path' => FixedAssetDisposal::SettlementDirect, 'proceeds' => 0,
            'reason' => 'Write-off posted to demonstrate reversal.', 'notes' => $this->note('Reversible write-off example.'),
        ]);
        $lifecycle->reverseDisposal($reversedDisposal, 'Client demo reversal proves audit-safe lifecycle correction.');

        $writtenOff = $this->asset($service, $groups['it'], $clearing, $currency, $resources, [
            'asset_date' => "{$year}-01-10", 'asset_name' => 'Obsolete Server — Client Demo', 'serial_number' => 'CD-ASSET-WRITTEN-OFF',
            'purchase_value' => '30000', 'salvage_value' => '0', 'status' => FixedAsset::StatusActive,
        ]);
        $lifecycle->dispose($writtenOff, [
            'disposal_date' => "{$year}-04-02", 'disposition_type' => FixedAssetDisposal::TypeWriteOff,
            'settlement_path' => FixedAssetDisposal::SettlementDirect, 'proceeds' => 0,
            'reason' => 'Permanent obsolete equipment write-off.', 'notes' => $this->note('Persistent written-off status example.'),
        ]);

        foreach ([
            [$groups['machinery'], 'Draft Air Compressor — Client Demo', 'CD-ASSET-DRAFT', '180000', '18000', FixedAsset::StatusDraft, true],
            [$groups['machinery'], 'Fully Depreciated Mixer — Client Demo', 'CD-ASSET-FULLY-DEP', '50000', '5000', FixedAsset::StatusFullyDepreciated, true, '45000'],
            [$groups['land'], 'Factory Expansion Land — Client Demo', 'CD-ASSET-LAND', '750000', '0', FixedAsset::StatusActive, false],
            [$groups['machinery'], 'Blow Molding Machine — Client Demo', 'CD-ASSET-BLOW', '340000', '34000', FixedAsset::StatusActive, true],
            [$groups['vehicles'], 'Raw Material Forklift — Client Demo', 'CD-ASSET-FORKLIFT', '95000', '9500', FixedAsset::StatusActive, true],
            [$groups['furniture'], 'Administration Furniture — Client Demo', 'CD-ASSET-FURNITURE', '60000', '6000', FixedAsset::StatusActive, true],
            [$groups['it'], 'ERP Application Server — Client Demo', 'CD-ASSET-ERP-SERVER', '125000', '12500', FixedAsset::StatusActive, true],
            [$groups['machinery'], 'Suspended Granulator — Client Demo', 'CD-ASSET-SUSPENDED', '80000', '8000', FixedAsset::StatusSuspended, true],
            [$groups['furniture'], 'Warehouse Racking — Client Demo', 'CD-ASSET-RACKING', '140000', '14000', FixedAsset::StatusActive, true],
        ] as $definition) {
            $this->asset($service, $definition[0], $clearing, $currency, $resources, [
                'asset_date' => "{$year}-01-01", 'asset_name' => $definition[1], 'serial_number' => $definition[2],
                'purchase_value' => $definition[3], 'salvage_value' => $definition[4], 'status' => $definition[5],
                'is_depreciable' => $definition[6], 'previous_depreciation' => $definition[7] ?? 0,
                'previous_depreciation_until_date' => isset($definition[7]) ? ($year - 1).'-12-31' : null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $resources
     * @param  array<string, Product>  $products
     * @param  array<string, Supplier>  $suppliers
     */
    private function seedProcurement(FinancialPeriod $period, Currency $currency, array $resources, array $products, array $suppliers): void
    {
        $year = (int) $period->from_date->format('Y');
        $sourcing = app(ProcurementSourcingService::class);
        $receiving = app(ProcurementReceivingService::class);
        $settlement = app(ProcurementSettlementService::class);
        $requisition = $sourcing->createRequisition([
            'request_date' => "{$year}-01-05", 'required_by_date' => "{$year}-01-20",
            'branch_store_uuid' => $resources['rawStore']->public_uuid, 'department' => 'Production Planning', 'priority' => 'high',
            'notes' => self::GoldenRequisitionNote,
            'lines' => [[
                'product_doc_num' => $products['pp']->doc_num, 'unit_doc_num' => $products['pp']->unit->doc_num,
                'requested_quantity' => '10000', 'required_date' => "{$year}-01-20", 'source_type' => 'manual',
                'specification' => 'PP homopolymer injection grade in sealed 25 kg bags with certificate of analysis.',
                'notes' => $this->note('Golden 10,000 kg material requirement.'),
            ]],
        ]);
        $sourcing->submitRequisition($requisition);
        $requisition = $sourcing->approveRequisition($requisition->fresh());
        $requirement = $requisition->lines->firstOrFail();
        $rfq = $sourcing->createRequestForQuotation($requisition, [
            'issue_date' => "{$year}-01-06", 'quotation_due_date' => "{$year}-01-08", 'required_delivery_date' => "{$year}-01-20",
            'supplier_doc_nums' => [$suppliers['supplier_a']->doc_num, $suppliers['supplier_b']->doc_num],
            'commercial_notes' => $this->note('Compare split-award price, tax, lead time, and payment terms.'),
            'lines' => [['requisition_line_public_id' => $requirement->public_id, 'quantity' => '10000']],
        ]);
        $rfq = $sourcing->issueRequestForQuotation($rfq);
        $rfqLine = $rfq->lines->firstOrFail();
        $quotations = collect([
            [$suppliers['supplier_a'], '20', '100', 'CD-A-Q-001'],
            [$suppliers['supplier_b'], '25', '80', 'CD-B-Q-001'],
        ])->map(function (array $offer) use ($currency, $rfq, $rfqLine, $sourcing, $year) {
            $quotation = $sourcing->createSupplierQuotation($rfq, [
                'supplier_doc_num' => $offer[0]->doc_num, 'currency_doc_num' => $currency->doc_num,
                'quotation_date' => "{$year}-01-07", 'valid_until' => "{$year}-02-15", 'exchange_rate' => 1,
                'supplier_reference' => $offer[3], 'lead_time_days' => 5, 'payment_terms' => 'Installments after accepted delivery',
                'freight_amount' => 0, 'commercial_notes' => $this->note('Submitted offer for the split-award comparison.'),
                'lines' => [[
                    'rfq_line_public_id' => $rfqLine->public_id, 'offered_quantity' => '10000',
                    'unit_price' => $offer[1], 'discount_amount' => $offer[2], 'tax_rate' => 14,
                    'delivery_date' => "{$year}-01-20",
                ]],
            ]);

            return $sourcing->submitSupplierQuotation($quotation);
        });
        $selection = $sourcing->createSupplierSelection($rfq->fresh(), [
            'selection_date' => "{$year}-01-08", 'selection_reason' => 'Canonical 60/40 split balances price and capacity.',
            'lines' => [
                ['quotation_line_public_id' => $quotations->first()->lines->firstOrFail()->public_id, 'selected_quantity' => '6000', 'reason' => 'Lowest compliant price.'],
                ['quotation_line_public_id' => $quotations->last()->lines->firstOrFail()->public_id, 'selected_quantity' => '4000', 'reason' => 'Capacity and continuity allocation.'],
            ],
        ]);
        $orders = $sourcing->approveSelection($selection);
        $supplierAOrder = app(PurchaseOrderService::class)->approve($orders->firstWhere('supplier_id', $suppliers['supplier_a']->getKey()));
        $supplierBOrder = app(PurchaseOrderService::class)->approve($orders->firstWhere('supplier_id', $suppliers['supplier_b']->getKey()));

        $supplierAOrder = $receiving->createDeliverySchedules($supplierAOrder, [
            'schedules' => [
                ['purchase_order_line_public_id' => $supplierAOrder->lines->firstOrFail()->public_id, 'scheduled_date' => "{$year}-01-12", 'scheduled_quantity' => '4000', 'notes' => 'Truck A1'],
                ['purchase_order_line_public_id' => $supplierAOrder->lines->firstOrFail()->public_id, 'scheduled_date' => "{$year}-01-18", 'scheduled_quantity' => '2000', 'notes' => 'Truck A2'],
            ],
        ]);
        $supplierBOrder = $receiving->createDeliverySchedules($supplierBOrder, [
            'schedules' => [[
                'purchase_order_line_public_id' => $supplierBOrder->lines->firstOrFail()->public_id,
                'scheduled_date' => "{$year}-01-20", 'scheduled_quantity' => '4000', 'notes' => 'Truck B1',
            ]],
        ]);

        [$aReceiptOne, $aReceiptLineOne] = $this->receiveAndInspect(
            $receiving, $supplierAOrder, $supplierAOrder->lines->firstOrFail()->deliverySchedules->first(),
            "{$year}-01-12", 'CD-A-DN-001', '4000', '3900', '100',
        );
        [, $aReceiptLineTwo] = $this->receiveAndInspect(
            $receiving, $supplierAOrder->fresh(), $supplierAOrder->lines->firstOrFail()->deliverySchedules->last(),
            "{$year}-01-18", 'CD-A-DN-002', '2000', '2000', '0',
        );
        [, $bReceiptLine] = $this->receiveAndInspect(
            $receiving, $supplierBOrder, $supplierBOrder->lines->firstOrFail()->deliverySchedules->first(),
            "{$year}-01-20", 'CD-B-DN-001', '4000', '4000', '0',
        );

        $preInvoiceReturn = $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $supplierAOrder->doc_num, 'return_date' => "{$year}-01-21",
            'reason_code' => 'incoming_qc_rejection', 'notes' => $this->note('Pre-invoice return of all 100 rejected kg.'),
            'lines' => [[
                'receipt_line_public_id' => $aReceiptLineOne->public_id, 'quantity' => '100', 'from_quarantine' => true,
                'reason' => 'Damaged bags rejected during incoming QC.',
            ]],
        ]);
        $settlement->approvePurchaseReturn($preInvoiceReturn);

        $supplierAInvoice = $this->createAndApproveInvoice(
            $period, $currency, $supplierAOrder, $suppliers['supplier_a'], $products['pp'],
            [
                ['receipt' => $aReceiptLineOne, 'quantity' => '3900', 'unit_price' => '20', 'discount' => '60'],
                ['receipt' => $aReceiptLineTwo, 'quantity' => '2000', 'unit_price' => '20', 'discount' => '40'],
            ],
            "{$year}-01-22", 'CD-A-INV-001', [40, 30, 30],
        );
        $supplierBInvoice = $this->createAndApproveInvoice(
            $period, $currency, $supplierBOrder, $suppliers['supplier_b'], $products['pp'],
            [['receipt' => $bReceiptLine, 'quantity' => '4000', 'unit_price' => '25', 'discount' => '80']],
            "{$year}-01-23", 'CD-B-INV-001', [50, 50],
        );

        $postInvoiceReturn = $settlement->createPurchaseReturn([
            'purchase_order_doc_num' => $supplierAOrder->doc_num, 'purchase_invoice_doc_num' => $supplierAInvoice->doc_num,
            'return_date' => "{$year}-02-10", 'reason_code' => 'latent_defect',
            'notes' => $this->note('Post-invoice return and supplier debit adjustment for 500 kg.'),
            'lines' => [[
                'receipt_line_public_id' => $aReceiptLineOne->public_id, 'quantity' => '500', 'from_quarantine' => false,
                'reason' => 'Latent contamination found after acceptance.',
            ]],
        ]);
        $settlement->approvePurchaseReturn($postInvoiceReturn);

        $supplierAInvoice->refreshPaymentTotals();
        $cashPayment = $this->supplierPayment($settlement, $supplierAInvoice, $suppliers['supplier_a'], $currency, [
            'payment_method' => SupplierPaymentContext::MethodCash, 'payment_date' => "{$year}-02-15",
            'cashbox_doc_num' => $resources['cashbox']->doc_num, 'amount' => '50000',
            'reason' => 'Supplier A cash installment.',
        ]);
        $settlement->approveSupplierPayment($cashPayment);
        $supplierAInvoice->refreshPaymentTotals();
        $bankPayment = $this->supplierPayment($settlement, $supplierAInvoice, $suppliers['supplier_a'], $currency, [
            'payment_method' => SupplierPaymentContext::MethodBank, 'payment_date' => "{$year}-03-01",
            'bank_account_doc_num' => $resources['bankAccount']->doc_num, 'amount' => $supplierAInvoice->remaining_amount,
            'reason' => 'Supplier A final bank settlement.',
        ]);
        $settlement->approveSupplierPayment($bankPayment);

        $supplierBInvoice->refreshPaymentTotals();
        $chequeAmount = bcsub((string) $supplierBInvoice->remaining_amount, '40000.0000', 4);
        $chequePayment = $this->supplierPayment($settlement, $supplierBInvoice, $suppliers['supplier_b'], $currency, [
            'payment_method' => SupplierPaymentContext::MethodCheque, 'payment_date' => "{$year}-02-20",
            'bank_account_doc_num' => $resources['bankAccount']->doc_num, 'amount' => $chequeAmount,
            'cheque_number' => 'CD-CHK-B-001', 'cheque_date' => "{$year}-02-20", 'cheque_due_date' => "{$year}-03-15",
            'reason' => 'Supplier B partial issued cheque; 40,000 remains outstanding.',
        ]);
        $chequePayment = $settlement->approveSupplierPayment($chequePayment);
        app(ChequeService::class)->markCleared($chequePayment->cheque);

        $sourcing->createRequisition([
            'request_date' => now()->toDateString(), 'required_by_date' => now()->addDays(14)->toDateString(),
            'branch_store_uuid' => $resources['packagingStore']->public_uuid, 'department' => 'Packing', 'priority' => 'normal',
            'notes' => $this->note('Draft requisition intentionally available for manual browser continuation.'),
            'lines' => [[
                'product_doc_num' => $products['carton']->doc_num, 'unit_doc_num' => $products['carton']->unit->doc_num,
                'requested_quantity' => '250', 'source_type' => 'manual', 'notes' => $this->note('Draft status example.'),
            ]],
        ]);

        $this->seedServicePurchase($period, $currency, $resources, $products['maintenance'], $suppliers['maintenance'], $settlement);
    }

    /** @return array{0: mixed, 1: mixed} */
    private function receiveAndInspect(
        ProcurementReceivingService $receiving,
        PurchaseOrder $order,
        object $schedule,
        string $date,
        string $deliveryNote,
        string $delivered,
        string $accepted,
        string $rejected,
    ): array {
        $orderLine = $order->lines()->firstOrFail();
        $receipt = $receiving->receive($order->fresh(), [
            'document_date' => $date, 'supplier_delivery_note' => $deliveryNote, 'supplier_delivery_date' => $date,
            'received_at' => $date.' 10:00:00', 'notes' => $this->note('Scheduled material delivery.'),
            'lines' => [[
                'purchase_order_line_public_id' => $orderLine->public_id,
                'delivery_schedule_public_id' => $schedule->public_id,
                'delivered_quantity' => $delivered, 'supplier_lot_number' => $deliveryNote.'-LOT',
            ]],
        ]);
        $receiptLine = $receipt->lines->firstOrFail();
        $receiving->inspect($receipt, [
            'inspection_at' => $date.' 13:00:00', 'observations' => 'Incoming resin sampling and bag-condition check.',
            'lines' => [[
                'receipt_line_public_id' => $receiptLine->public_id, 'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected,
                'disposition' => (float) $rejected > 0 ? 'quarantine' : 'accepted',
                'reason' => (float) $rejected > 0 ? 'Damaged bags quarantined.' : null,
                'measurements' => ['mfi' => '12.0 g/10min', 'visual_contamination' => 'passed'],
            ]],
        ]);

        return [$receipt, $receiptLine];
    }

    /**
     * @param  list<array{receipt: object, quantity: string, unit_price: string, discount: string}>  $definitions
     * @param  list<int>  $schedulePercentages
     */
    private function createAndApproveInvoice(
        FinancialPeriod $period,
        Currency $currency,
        PurchaseOrder $order,
        Supplier $supplier,
        Product $product,
        array $definitions,
        string $date,
        string $supplierInvoiceNumber,
        array $schedulePercentages,
    ): PurchaseInvoice {
        $lines = collect($definitions)->map(fn (array $definition): array => [
            'product_doc_num' => $product->doc_num, 'unit_doc_num' => $product->unit->doc_num,
            'purchase_order_line_public_id' => $order->lines()->firstOrFail()->public_id,
            'receipt_line_public_id' => $definition['receipt']->public_id,
            'quantity' => $definition['quantity'], 'unit_price' => $definition['unit_price'],
            'discount_type' => 'fixed', 'discount_value' => $definition['discount'], 'tax_rate' => 14,
        ])->all();
        $calculation = app(PurchaseInvoiceCalculationService::class)->calculate($lines, null, 0, 0, 0);
        $totalUnits = (int) round((float) $calculation['invoice']['total_amount'] * 10000);
        $allocatedUnits = 0;
        $lastIndex = count($schedulePercentages) - 1;
        $schedules = [];

        foreach ($schedulePercentages as $index => $percentage) {
            $amountUnits = $index === $lastIndex
                ? $totalUnits - $allocatedUnits
                : (int) round($totalUnits * ($percentage / 100));
            $allocatedUnits += $amountUnits;
            $schedules[] = [
                'due_date' => now()->startOfYear()->addMonths($index)->addDays(14)->toDateString(),
                'amount' => number_format($amountUnits / 10000, 4, '.', ''),
                'payment_source_type' => PurchaseInvoice::SourceScheduled,
                'notes' => $this->note("{$percentage}% installment; first installment is overdue for aging demonstrations."),
            ];
        }

        $invoice = app(PurchaseInvoiceService::class)->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $supplier->doc_num,
            'purchase_order_doc_num' => $order->doc_num, 'purchase_type' => 'standard',
            'invoice_date' => $date, 'supplier_invoice_number' => $supplierInvoiceNumber, 'supplier_invoice_date' => $date,
            'currency_doc_num' => $currency->doc_num, 'exchange_rate' => 1, 'payment_type' => PurchaseInvoice::PaymentTypeCredit,
            'notes' => $this->note('Approved three-way-matched split-award supplier invoice.'),
            'lines' => $lines, 'payment_schedules' => $schedules,
        ])['record'];

        return app(PurchaseInvoiceService::class)->approve($invoice);
    }

    /** @param array<string, mixed> $methodData */
    private function supplierPayment(
        ProcurementSettlementService $settlement,
        PurchaseInvoice $invoice,
        Supplier $supplier,
        Currency $currency,
        array $methodData,
    ): SupplierPaymentContext {
        return $settlement->createSupplierPayment([
            'supplier_doc_num' => $supplier->doc_num, 'purchase_order_doc_num' => $invoice->purchaseOrder?->doc_num,
            'currency_doc_num' => $currency->doc_num, 'exchange_rate' => 1,
            'notes' => $this->note($methodData['reason']), ...$methodData,
            'allocations' => [['purchase_invoice_doc_num' => $invoice->doc_num, 'amount' => $methodData['amount']]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $resources
     */
    private function seedServicePurchase(
        FinancialPeriod $period,
        Currency $currency,
        array $resources,
        Product $serviceProduct,
        Supplier $supplier,
        ProcurementSettlementService $settlement,
    ): void {
        $year = (int) $period->from_date->format('Y');
        $order = app(PurchaseOrderService::class)->create([
            'supplier_doc_num' => $supplier->doc_num, 'currency_doc_num' => $currency->doc_num,
            'branch_store_uuid' => $resources['rawStore']->public_uuid, 'document_date' => "{$year}-05-01",
            'expected_delivery_date' => "{$year}-05-10", 'purchase_type' => 'service', 'exchange_rate' => 1,
            'payment_terms' => 'Due on completion', 'freight_amount' => 0,
            'internal_reference' => 'CD-SERVICE-PO-001', 'notes' => $this->note('Service purchase intentionally requires no GRN.'),
            'lines' => [[
                'product_doc_num' => $serviceProduct->doc_num, 'unit_doc_num' => $serviceProduct->unit->doc_num,
                'ordered_quantity' => '1', 'unit_price' => '15000', 'discount_type' => null, 'discount_value' => 0,
                'tax_rate' => 14, 'required_delivery_date' => "{$year}-05-10",
                'description' => 'Annual preventive maintenance visit.', 'notes' => $this->note('Non-stock service line.'),
            ]],
        ])['record'];
        $order = app(PurchaseOrderService::class)->approve($order);
        $invoice = app(PurchaseInvoiceService::class)->create([
            'financial_period_doc_num' => $period->doc_num, 'supplier_doc_num' => $supplier->doc_num,
            'purchase_order_doc_num' => $order->doc_num, 'purchase_type' => 'service',
            'invoice_date' => "{$year}-05-10", 'supplier_invoice_number' => 'CD-SERVICE-INV-001', 'supplier_invoice_date' => "{$year}-05-10",
            'currency_doc_num' => $currency->doc_num, 'exchange_rate' => 1, 'payment_type' => PurchaseInvoice::PaymentTypeCredit,
            'notes' => $this->note('Service invoice matched without a goods receipt.'),
            'lines' => [[
                'product_doc_num' => $serviceProduct->doc_num, 'unit_doc_num' => $serviceProduct->unit->doc_num,
                'purchase_order_line_public_id' => $order->lines->firstOrFail()->public_id,
                'quantity' => '1', 'unit_price' => '15000', 'discount_type' => null, 'discount_value' => 0, 'tax_rate' => 14,
            ]],
            'payment_schedules' => [[
                'due_date' => "{$year}-05-15", 'amount' => '17100', 'payment_source_type' => PurchaseInvoice::SourceScheduled,
                'notes' => $this->note('Single service installment.'),
            ]],
        ])['record'];
        $invoice = app(PurchaseInvoiceService::class)->approve($invoice);
        $payment = $this->supplierPayment($settlement, $invoice, $supplier, $currency, [
            'payment_method' => SupplierPaymentContext::MethodBank, 'payment_date' => "{$year}-05-15",
            'bank_account_doc_num' => $resources['bankAccount']->doc_num, 'amount' => $invoice->remaining_amount,
            'reason' => 'Settled service procurement invoice.',
        ]);
        $settlement->approveSupplierPayment($payment);
    }

    /**
     * @param  array<string, mixed>  $resources
     * @param  array<string, Product>  $products
     * @param  array<string, Supplier>  $suppliers
     */
    private function seedOpenProcurementStates(
        FinancialPeriod $period,
        Currency $currency,
        array $resources,
        array $products,
        array $suppliers,
    ): void {
        $year = (int) $period->from_date->format('Y');

        if (! PurchaseOrder::query()->where('company_id', $period->company_id)->where('notes', self::DraftPurchaseOrderNote)->exists()) {
            app(PurchaseOrderService::class)->create([
                'supplier_doc_num' => $suppliers['packaging']->doc_num,
                'branch_store_uuid' => $resources['packagingStore']->public_uuid,
                'currency_doc_num' => $currency->doc_num,
                'document_date' => "{$year}-08-01",
                'expected_delivery_date' => "{$year}-09-01",
                'exchange_rate' => 1,
                'direct_procurement_override' => true,
                'direct_procurement_reason' => 'Client-demo draft retained for the next manual approval action.',
                'notes' => self::DraftPurchaseOrderNote,
                'lines' => [[
                    'product_doc_num' => $products['carton']->doc_num,
                    'unit_doc_num' => $products['carton']->unit->doc_num,
                    'ordered_quantity' => '120',
                    'unit_price' => '18',
                    'tax_rate' => '14',
                    'notes' => $this->note('Draft packaging replenishment line.'),
                ]],
            ]);
        }

        if (! PurchaseInvoice::query()->where('company_id', $period->company_id)->where('notes', self::DraftInvoiceNote)->exists()) {
            app(PurchaseInvoiceService::class)->create([
                'financial_period_doc_num' => $period->doc_num,
                'supplier_doc_num' => $suppliers['maintenance']->doc_num,
                'currency_doc_num' => $currency->doc_num,
                'invoice_date' => "{$year}-08-01",
                'supplier_invoice_date' => "{$year}-08-01",
                'supplier_invoice_number' => 'CD-SVC-DRAFT-001',
                'exchange_rate' => 1,
                'payment_type' => PurchaseInvoice::PaymentTypeCredit,
                'purchase_type' => 'direct',
                'direct_procurement_override' => true,
                'direct_procurement_reason' => 'Client-demo draft service invoice awaiting review.',
                'notes' => self::DraftInvoiceNote,
                'lines' => [[
                    'product_doc_num' => $products['maintenance']->doc_num,
                    'unit_doc_num' => $products['maintenance']->unit->doc_num,
                    'quantity' => '1',
                    'unit_price' => '3000',
                    'tax_rate' => '14',
                ]],
            ]);
        }

        $unpaidInvoice = PurchaseInvoice::query()
            ->where('company_id', $period->company_id)
            ->where('notes', self::UnpaidInvoiceNote)
            ->first();

        if (! $unpaidInvoice instanceof PurchaseInvoice) {
            $unpaidInvoice = app(PurchaseInvoiceService::class)->create([
                'financial_period_doc_num' => $period->doc_num,
                'supplier_doc_num' => $suppliers['maintenance']->doc_num,
                'currency_doc_num' => $currency->doc_num,
                'invoice_date' => "{$year}-06-01",
                'supplier_invoice_date' => "{$year}-06-01",
                'supplier_invoice_number' => 'CD-SVC-UNPAID-001',
                'exchange_rate' => 1,
                'payment_type' => PurchaseInvoice::PaymentTypeCredit,
                'purchase_type' => 'direct',
                'direct_procurement_override' => true,
                'direct_procurement_reason' => 'Approved client-demo direct service invoice for AP aging.',
                'notes' => self::UnpaidInvoiceNote,
                'lines' => [[
                    'product_doc_num' => $products['maintenance']->doc_num,
                    'unit_doc_num' => $products['maintenance']->unit->doc_num,
                    'quantity' => '1',
                    'unit_price' => '7500',
                    'tax_rate' => '14',
                ]],
            ])['record'];
        }

        if ($unpaidInvoice->status === PurchaseInvoice::StatusDraft) {
            app(PurchaseInvoiceService::class)->approve($unpaidInvoice);
        }
    }

    /** @return array<string, Account> */
    private function fixedAssetGroups(Company $company): array
    {
        $definitions = [
            'machinery' => 'Machinery & Production Equipment — Client Demo',
            'vehicles' => 'Vehicles & Material Handling — Client Demo',
            'furniture' => 'Furniture & Warehouse Fixtures — Client Demo',
            'it' => 'IT & Office Equipment — Client Demo',
            'land' => 'Land — Client Demo',
        ];
        $groups = [];

        foreach ($definitions as $key => $name) {
            $group = Account::query()->where('company_id', $company->getKey())->where('name', $name)->first();
            $groups[$key] = $group instanceof Account
                ? $group
                : app(BusinessPartnerAccountService::class)->createGroup(
                    BusinessPartnerAccountService::FixedAsset,
                    $name,
                    $this->note('Mapped fixed asset category.'),
                );
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $resources
     * @param  array<string, mixed>  $overrides
     */
    private function asset(
        FixedAssetService $service,
        Account $group,
        Account $clearing,
        Currency $currency,
        array $resources,
        array $overrides,
    ): FixedAsset {
        $assetDate = (string) $overrides['asset_date'];
        $isDepreciable = (bool) ($overrides['is_depreciable'] ?? true);

        return $service->create([
            'asset_date' => $assetDate,
            'asset_name' => $overrides['asset_name'],
            'entry_type' => $overrides['entry_type'] ?? FixedAsset::EntryTypeNewAsset,
            'asset_group_account_doc_num' => $group->doc_num,
            'credit_account_doc_num' => $clearing->doc_num,
            'branch_doc_num' => $resources['factoryBranch']->doc_num,
            'branch_hall_uuid' => $resources['factoryHall']->public_uuid,
            'cost_center_doc_num' => $resources['injection']->doc_num,
            'description' => $overrides['asset_name'].' is part of the persistent Fixed Assets client demonstration.',
            'serial_number' => $overrides['serial_number'],
            'purchase_date' => $assetDate,
            'acquisition_date' => $assetDate,
            'operation_date' => $assetDate,
            'status' => $overrides['status'],
            'currency_doc_num' => $currency->doc_num,
            'exchange_rate' => 1,
            'purchase_value' => $overrides['purchase_value'],
            'salvage_value' => $overrides['salvage_value'],
            'previous_depreciation' => $overrides['previous_depreciation'] ?? 0,
            'previous_depreciation_until_date' => $overrides['previous_depreciation_until_date'] ?? null,
            'depreciation_method' => $isDepreciable ? FixedAsset::DepreciationMethodStraightLine : null,
            'useful_life' => $isDepreciable ? ($overrides['useful_life'] ?? '5') : null,
            'annual_depreciation_rate' => $isDepreciable ? ($overrides['annual_depreciation_rate'] ?? '20') : null,
            'is_depreciable' => $isDepreciable,
            'location_address' => 'Main Plastic Factory — Client Demo',
            'notes' => $overrides['notes'] ?? $this->note('Fixed asset status and report coverage example.'),
        ])['record'];
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

    private function branch(Company $company, string $name, string $type, int $position, User $user): Branch
    {
        $branch = Branch::query()->where('company_id', $company->getKey())->where('name', $name)->first();

        if ($branch instanceof Branch) {
            return $branch;
        }

        return Branch::query()->create([
            ...app(DocumentNumberService::class)->next('branches', Branch::class),
            'company_id' => $company->getKey(), 'name' => $name, 'type' => $type,
            'address' => $type === Branch::TypeFactory ? '10th of Ramadan Industrial Zone, Egypt' : 'Nasr City, Cairo, Egypt',
            'phone' => '+20 2 5555 '.str_pad((string) $position, 4, '0', STR_PAD_LEFT),
            'email' => Str::slug($name, '.').'@client-demo.test', 'contact_person' => 'Client Demo Manager',
            'status' => 'active', 'notes' => $this->note('Operational branch for demo scoping.'), 'created_by' => $user->getKey(),
        ]);
    }

    private function hall(Branch $branch, string $name, int $position, User $user): BranchHall
    {
        return BranchHall::query()->firstOrCreate(
            ['branch_id' => $branch->getKey(), 'name' => $name],
            ['position' => $position, 'created_by' => $user->getKey()],
        );
    }

    private function store(Branch $branch, string $name, int $position, User $user): BranchStore
    {
        return BranchStore::query()->firstOrCreate(
            ['branch_id' => $branch->getKey(), 'name' => $name],
            ['position' => $position, 'created_by' => $user->getKey()],
        );
    }

    private function unit(Company $company, string $name, int $fallbackNumber): ItemUnit
    {
        $unit = ItemUnit::query()->where('company_id', $company->getKey())->where('name', $name)->first();

        return $unit instanceof ItemUnit ? $unit : ItemUnit::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('item_units', ItemUnit::class, $company->getKey()),
            'company_id' => $company->getKey(), 'name' => $name, 'status' => 'active',
            'notes' => $this->note("Procurement unit {$fallbackNumber}."),
        ]);
    }

    private function costCenter(Company $company, CostCenter $parent, string $code, string $name, User $user): CostCenter
    {
        $costCenter = CostCenter::query()->where('company_id', $company->getKey())->where('cost_center_code', $code)->first();

        return $costCenter instanceof CostCenter ? $costCenter : CostCenter::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('cost_centers', CostCenter::class, $company->getKey()),
            'company_id' => $company->getKey(), 'parent_id' => $parent->getKey(), 'cost_center_code' => $code,
            'name' => $name, 'is_group' => false, 'status' => 'active',
            'notes' => $this->note('Operational reporting cost center.'), 'created_by' => $user->getKey(),
        ]);
    }

    private function postingAccount(Company $company, string $code): Account
    {
        return Account::query()->where('company_id', $company->getKey())->where('account_code', $code)
            ->where('status', 'active')->where('is_group', false)->where('is_postable', true)->firstOrFail();
    }

    private function childPostingAccount(Company $company, string $parentCode, string $code, string $name): Account
    {
        $account = Account::query()->where('company_id', $company->getKey())->where('account_code', $code)->first();

        if ($account instanceof Account) {
            return $account;
        }

        $parent = Account::query()->where('company_id', $company->getKey())->where('account_code', $parentCode)->firstOrFail();

        return Account::query()->create([
            ...app(DocumentNumberService::class)->nextForCompany('accounts', Account::class, $company->getKey()),
            'company_id' => $company->getKey(), 'account_code' => $code, 'name' => $name, 'name_en' => $name,
            'parent_id' => $parent->getKey(), 'level' => ((int) $parent->level) + 1,
            'account_classification_id' => $parent->account_classification_id, 'account_type' => $parent->account_type,
            'statement_type' => $parent->statement_type, 'normal_balance' => $parent->normal_balance,
            'is_group' => false, 'is_postable' => true, 'is_system' => false, 'status' => 'active',
            'notes' => $this->note('Posting account for client-demo accounting.'), 'created_by' => auth()->id(),
        ]);
    }

    private function note(string $description): string
    {
        return '['.self::Marker.'] '.$description;
    }
}
