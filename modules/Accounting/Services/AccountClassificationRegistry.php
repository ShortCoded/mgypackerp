<?php

namespace Modules\Accounting\Services;

use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\Services\DocumentNumberService;

class AccountClassificationRegistry
{
    public function __construct(
        private readonly DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        $canonicalLabels = $this->canonicalLabels();

        return array_map(function (array $definition) use ($canonicalLabels): array {
            [$name, $nameEn] = $canonicalLabels[$definition['code']]
                ?? throw new DomainException(__('Canonical labels are missing for [:code].', ['code' => $definition['code']]));

            return [
                ...$definition,
                'name' => $name,
                'name_en' => $nameEn,
            ];
        }, $this->previousDefinitions());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $code): ?array
    {
        foreach ($this->definitions() as $definition) {
            if ($definition['code'] === $code) {
                return $definition;
            }
        }

        return null;
    }

    public function activeRecord(string $code): ?AccountClassification
    {
        return AccountClassification::query()
            ->where('code', $code)
            ->where('status', 'active')
            ->first();
    }

    /** @return list<string> */
    public function originalCodes(): array
    {
        return array_column($this->originalDefinitions(), 'code');
    }

    /** @return list<string> */
    public function addedCodes(): array
    {
        return array_column($this->addedDefinitions(), 'code');
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        return $this->buildPlan($this->canonicalRecords());
    }

    /**
     * @return array<string, mixed>
     */
    public function synchronize(): array
    {
        return DB::transaction(function (): array {
            $records = $this->canonicalRecords(lockForUpdate: true);
            $plan = $this->buildPlan($records);

            if (
                $plan['duplicate_codes'] !== []
                || $plan['trashed_conflict_codes'] !== []
                || $plan['structural_conflict_codes'] !== []
                || $plan['label_conflict_codes'] !== []
            ) {
                throw new DomainException(__('Account classification conflicts must be resolved before synchronization.'));
            }

            $recordsByCode = $this->preferredRecordsByCode($records);

            foreach ($this->definitions() as $definition) {
                $classification = $recordsByCode->get($definition['code']);

                if (! $classification instanceof AccountClassification) {
                    $classification = AccountClassification::query()->create([
                        ...$this->documentNumbers->next('account_classifications', AccountClassification::class),
                        ...$definition,
                    ]);
                    $recordsByCode->put($definition['code'], $classification);

                    continue;
                }

                if (! in_array($definition['code'], $plan['label_update_codes'], true)) {
                    continue;
                }

                $previousLabels = [
                    'name' => $classification->getRawOriginal('name'),
                    'name_en' => $classification->getRawOriginal('name_en'),
                ];
                $updated = DB::table($classification->getTable())
                    ->where($classification->getKeyName(), $classification->getKey())
                    ->where('name', $previousLabels['name'])
                    ->where('name_en', $previousLabels['name_en'])
                    ->update([
                        'name' => $definition['name'],
                        'name_en' => $definition['name_en'],
                    ]);

                if ($updated !== 1) {
                    throw new DomainException(__('Labels for [:code] changed during synchronization.', ['code' => $definition['code']]));
                }
            }

            return $plan;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function verification(): array
    {
        $definitions = collect($this->definitions());
        $records = $this->canonicalRecords();
        $plan = $this->buildPlan($records);

        $result = [
            'canonical_count' => $definitions->count(),
            'current_count' => AccountClassification::query()->count(),
            'current_with_trashed_count' => AccountClassification::withTrashed()->count(),
            'missing_codes' => $plan['inserted_codes'],
            'duplicate_codes' => $plan['duplicate_codes'],
            'trashed_conflict_codes' => $plan['trashed_conflict_codes'],
            'structural_conflict_codes' => $plan['structural_conflict_codes'],
            'label_update_codes' => $plan['label_update_codes'],
            'label_conflict_codes' => $plan['label_conflict_codes'],
        ];

        return [
            'valid' => collect($result)
                ->except(['canonical_count', 'current_count', 'current_with_trashed_count'])
                ->every(fn (array $codes): bool => $codes === []),
            ...$result,
        ];
    }

    /**
     * @param  Collection<int, AccountClassification>  $records
     * @return array<string, mixed>
     */
    private function buildPlan(Collection $records): array
    {
        $recordsByCode = $this->preferredRecordsByCode($records);
        $insertedCodes = [];
        $labelUpdateCodes = [];
        $labelConflictCodes = [];
        $trashedConflictCodes = [];
        $structuralConflictCodes = [];
        $unchanged = 0;

        foreach ($this->definitions() as $definition) {
            $classification = $recordsByCode->get($definition['code']);

            if (! $classification instanceof AccountClassification) {
                $insertedCodes[] = $definition['code'];

                continue;
            }

            if ($classification->trashed()) {
                $trashedConflictCodes[] = $definition['code'];
            }

            if ($this->hasStructuralConflict($classification, $definition)) {
                $structuralConflictCodes[] = $definition['code'];
            }

            $labelState = $this->labelState($classification, $definition);

            match ($labelState) {
                'update' => $labelUpdateCodes[] = $definition['code'],
                'conflict' => $labelConflictCodes[] = $definition['code'],
                default => $unchanged++,
            };
        }

        return [
            'inserted' => count($insertedCodes),
            'unchanged' => $unchanged,
            'label_updates' => count($labelUpdateCodes),
            'label_conflicts' => count($labelConflictCodes),
            'deleted' => 0,
            'inserted_codes' => $insertedCodes,
            'label_update_codes' => $labelUpdateCodes,
            'label_conflict_codes' => $labelConflictCodes,
            'trashed_conflict_codes' => $trashedConflictCodes,
            'structural_conflict_codes' => $structuralConflictCodes,
            'duplicate_codes' => $this->duplicateCodes($records),
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function labelState(AccountClassification $classification, array $definition): string
    {
        $currentLabels = [
            'name' => $classification->getRawOriginal('name'),
            'name_en' => $classification->getRawOriginal('name_en'),
        ];
        $canonicalLabels = [
            'name' => $definition['name'],
            'name_en' => $definition['name_en'],
        ];

        if ($currentLabels === $canonicalLabels) {
            return 'unchanged';
        }

        if (in_array($currentLabels, $this->previousSystemLabelPairs($definition['code']), true)) {
            return 'update';
        }

        return 'conflict';
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function hasStructuralConflict(AccountClassification $classification, array $definition): bool
    {
        foreach (['account_type', 'statement_type', 'normal_balance', 'status', 'is_system'] as $field) {
            if ((string) $classification->getRawOriginal($field) !== (string) $definition[$field]) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{name: string, name_en: string}
     */
    public function previousSystemLabels(string $code): array
    {
        return $this->previousSystemLabelPairs($code)[0];
    }

    /**
     * @return list<array{name: string, name_en: string}>
     */
    private function previousSystemLabelPairs(string $code): array
    {
        $definition = collect($this->previousDefinitions())->firstWhere('code', $code)
            ?? throw new DomainException(__('Previous system labels are missing for [:code].', ['code' => $code]));

        $labels = [[
            'name' => $definition['name'],
            'name_en' => $definition['name_en'],
        ]];

        if ($code === AccountClassification::FixedAssets) {
            array_unshift($labels, [
                'name' => 'الممتلكات والآلات والمعدات – تصنيف عام',
                'name_en' => 'Property, Plant and Equipment – General',
            ]);
        }

        return $labels;
    }

    /** @return Collection<int, AccountClassification> */
    private function canonicalRecords(bool $lockForUpdate = false): Collection
    {
        $query = AccountClassification::withTrashed()
            ->whereIn('code', array_column($this->definitions(), 'code'))
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, AccountClassification>  $records
     * @return Collection<string, AccountClassification>
     */
    private function preferredRecordsByCode(Collection $records): Collection
    {
        return $records
            ->groupBy('code')
            ->map(fn (Collection $matches): ?AccountClassification => $matches->first(fn (AccountClassification $classification): bool => ! $classification->trashed()) ?? $matches->first())
            ->filter(fn (?AccountClassification $classification): bool => $classification instanceof AccountClassification);
    }

    /**
     * @param  Collection<int, AccountClassification>  $records
     * @return list<string>
     */
    private function duplicateCodes(Collection $records): array
    {
        return $records
            ->groupBy('code')
            ->filter(fn (Collection $matches): bool => $matches->count() > 1)
            ->keys()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function previousDefinitions(): array
    {
        return [...$this->originalDefinitions(), ...$this->addedDefinitions()];
    }

    /** @return array<string, array{0: string, 1: string}> */
    private function canonicalLabels(): array
    {
        return [
            'cash' => ['النقدية بالصندوق', 'Cash on Hand'],
            'bank' => ['النقدية لدى البنوك', 'Cash at Banks'],
            'accounts_receivable' => ['الذمم التجارية المدينة', 'Trade Receivables'],
            'inventory' => ['المخزون – تصنيف عام', 'Inventories – General'],
            AccountClassification::FixedAssets => ['الأصول الثابتة', 'Fixed Assets'],
            'accumulated_depreciation' => ['مجمع إهلاك الممتلكات والآلات والمعدات', 'Accumulated Depreciation – Property, Plant and Equipment'],
            'prepaid_expenses' => ['المصروفات المدفوعة مقدمًا', 'Prepaid Expenses'],
            'accounts_payable' => ['الذمم التجارية الدائنة', 'Trade Payables'],
            'tax_payable' => ['ضرائب مستحقة – تصنيف عام', 'Taxes Payable – General'],
            'loans_payable' => ['القروض والتسهيلات الائتمانية – تصنيف عام', 'Loans and Borrowings – General'],
            'accrued_expenses' => ['المصروفات المستحقة', 'Accrued Expenses'],
            'capital' => ['رأس المال', 'Capital'],
            'retained_earnings' => ['الأرباح المحتجزة', 'Retained Earnings'],
            'sales_revenue' => ['إيرادات بيع المنتجات', 'Revenue from Sale of Goods'],
            'service_revenue' => ['إيرادات تقديم الخدمات', 'Revenue from Services'],
            'sales_returns' => ['مردودات المبيعات', 'Sales Returns'],
            'sales_discounts' => ['الخصم المسموح به على المبيعات', 'Sales Discounts Allowed'],
            'cost_of_goods_sold' => ['تكلفة المبيعات', 'Cost of Sales'],
            'salary_expense' => ['مصروف الأجور والمرتبات', 'Salaries and Wages Expense'],
            'rent_expense' => ['مصروف الإيجارات', 'Rent Expense'],
            'utilities_expense' => ['مصروف الطاقة والمرافق', 'Utilities Expense'],
            'depreciation_expense' => ['مصروف الإهلاك', 'Depreciation Expense'],
            'other_expense' => ['مصروفات أخرى', 'Other Expenses'],
            'expenses' => ['مصروفات غير مصنفة', 'Unclassified Expenses'],
            'employee_custody' => ['عهد نقدية لدى العاملين', 'Employee Cash Imprests'],
            'employee_advances' => ['سلف العاملين', 'Employee Advances'],
            'supplier_advances' => ['دفعات مقدمة للموردين', 'Advances to Suppliers'],
            'other_receivables' => ['ذمم مدينة أخرى', 'Other Receivables'],
            'recoverable_vat' => ['ضريبة القيمة المضافة على المدخلات القابلة للخصم', 'Recoverable Input VAT'],
            'withholding_tax_receivable' => ['مبالغ مخصومة تحت حساب الضريبة لدى الغير', 'Withholding Tax Receivable'],
            'security_deposits' => ['تأمينات نقدية لدى الغير', 'Refundable Deposits'],
            'cheques_receivable' => ['شيكات تحت التحصيل', 'Cheques Under Collection'],
            'cash_in_transit' => ['نقدية قيد التحويل', 'Cash in Transit'],
            'raw_material_inventory' => ['مخزون المواد الخام', 'Raw Materials Inventory'],
            'packaging_material_inventory' => ['مخزون مواد التعبئة والتغليف', 'Packaging Materials Inventory'],
            'printing_ink_inventory' => ['مخزون أحبار الطباعة', 'Printing Ink Inventory'],
            'spare_parts_inventory' => ['مخزون قطع الغيار', 'Spare Parts Inventory'],
            'operating_supplies_inventory' => ['مخزون مستلزمات التشغيل', 'Operating Supplies Inventory'],
            'work_in_process_inventory' => ['مخزون الإنتاج تحت التشغيل', 'Work in Progress Inventory'],
            'semi_finished_goods_inventory' => ['مخزون منتجات نصف مصنعة', 'Semi-finished Goods Inventory'],
            'finished_goods_inventory' => ['مخزون الإنتاج التام', 'Finished Goods Inventory'],
            'goods_in_transit_inventory' => ['مخزون البضاعة بالطريق', 'Goods in Transit Inventory'],
            'scrap_waste_inventory' => ['مخزون الخردة والمخلفات القابلة للاسترداد', 'Recoverable Scrap and Waste Inventory'],
            'inventory_allowance' => ['مخصص انخفاض قيمة المخزون', 'Allowance for Inventory Write-down'],
            'allowance_for_doubtful_accounts' => ['مخصص الخسائر الائتمانية المتوقعة', 'Allowance for Expected Credit Losses'],
            'construction_in_progress' => ['مشروعات تحت التنفيذ', 'Capital Work in Progress'],
            'machinery_equipment' => ['الآلات والمعدات', 'Machinery and Equipment'],
            'molds_tooling' => ['القوالب والعدد', 'Molds and Tooling'],
            'vehicles' => ['المركبات ووسائل النقل', 'Vehicles and Transportation Equipment'],
            'it_office_equipment' => ['أجهزة تقنية المعلومات والمعدات المكتبية', 'IT and Office Equipment'],
            'furniture_fixtures' => ['الأثاث والتجهيزات', 'Furniture and Fixtures'],
            'land' => ['الأراضي', 'Land'],
            'buildings' => ['المباني والإنشاءات', 'Buildings and Structures'],
            'electrical_equipment' => ['الأجهزة والمعدات الكهربائية', 'Electrical Equipment'],
            'goods_received_not_invoiced' => ['مستحقات بضائع مستلمة غير مفوترة', 'Goods Received Not Invoiced (GRNI)'],
            'capital_expenditure_payables' => ['ذمم دائنة لشراء أصول رأسمالية', 'Capital Expenditure Payables'],
            'customer_advances' => ['التزامات عقود – دفعات مقدمة من العملاء', 'Contract Liabilities – Customer Advances'],
            'related_party_payables' => ['أرصدة دائنة لأطراف ذات علاقة', 'Amounts Due to Related Parties'],
            'social_insurance_payable' => ['اشتراكات التأمينات الاجتماعية المستحقة', 'Social Insurance Contributions Payable'],
            'output_vat_payable' => ['ضريبة القيمة المضافة على المخرجات المستحقة', 'Output VAT Payable'],
            'withholding_tax_payable' => ['مبالغ مخصومة تحت حساب الضريبة مستحقة للمصلحة', 'Withholding Tax Payable'],
            'payroll_tax_payable' => ['ضريبة المرتبات وما في حكمها المستحقة', 'Payroll Tax Payable'],
            'corporate_income_tax_payable' => ['ضريبة دخل الشركات المستحقة', 'Corporate Income Tax Payable'],
            'payroll_payable' => ['أجور ورواتب مستحقة للعاملين', 'Salaries and Wages Payable'],
            'cheques_payable' => ['شيكات واجبة الدفع', 'Cheques Payable'],
            'provisions' => ['المخصصات – تصنيف عام', 'Provisions – General'],
            'legal_claims_provision' => ['مخصص القضايا والمطالبات', 'Provision for Legal Claims'],
            'bonus_provision' => ['مخصص مكافآت العاملين', 'Employee Bonus Provision'],
            'leave_provision' => ['مخصص إجازات العاملين', 'Employee Leave Provision'],
            'current_loans_payable' => ['قروض وتسهيلات ائتمانية متداولة', 'Current Loans and Borrowings'],
            'noncurrent_loans_payable' => ['قروض وتسهيلات ائتمانية غير متداولة', 'Non-current Loans and Borrowings'],
            'current_lease_liabilities' => ['التزامات عقود إيجار متداولة', 'Current Lease Liabilities'],
            'noncurrent_lease_liabilities' => ['التزامات عقود إيجار غير متداولة', 'Non-current Lease Liabilities'],
            'other_current_liabilities' => ['التزامات متداولة أخرى', 'Other Current Liabilities'],
            'other_noncurrent_liabilities' => ['التزامات غير متداولة أخرى', 'Other Non-current Liabilities'],
            'current_year_result' => ['صافي ربح أو خسارة الفترة', 'Profit or Loss for the Period'],
            'legal_reserve' => ['الاحتياطي القانوني', 'Legal Reserve'],
            'general_reserve' => ['الاحتياطي العام', 'General Reserve'],
            'other_reserves' => ['احتياطيات أخرى', 'Other Reserves'],
            'other_operating_revenue' => ['إيرادات تشغيلية أخرى', 'Other Operating Revenue'],
            'other_income' => ['إيرادات غير تشغيلية أخرى', 'Other Non-operating Income'],
            'scrap_sales_revenue' => ['إيرادات بيع الخردة والمخلفات', 'Revenue from Sale of Scrap and Waste'],
            'foreign_exchange_gain' => ['أرباح فروق العملة الأجنبية', 'Foreign Exchange Gains'],
            'gain_on_asset_disposal' => ['أرباح استبعاد الممتلكات والآلات والمعدات', 'Gain on Disposal of Property, Plant and Equipment'],
            'inventory_adjustment_gain' => ['مكاسب تسويات المخزون', 'Inventory Adjustment Gains'],
            'quarantine_inventory' => ['مخزون تحت الفحص والحجر', 'Quarantine Inventory'],
            'rework_inventory' => ['مخزون إعادة التشغيل', 'Rework Inventory'],
            'purchases' => ['المشتريات – تصنيف عام', 'Purchases – General'],
            'raw_material_purchases' => ['مشتريات المواد الخام', 'Raw Materials Purchases'],
            'operating_supplies_purchases' => ['مشتريات مستلزمات التشغيل', 'Operating Supplies Purchases'],
            'other_purchases' => ['مشتريات أخرى', 'Other Purchases'],
            'purchase_returns' => ['مردودات المشتريات', 'Purchase Returns'],
            'purchase_discounts' => ['الخصم المكتسب على المشتريات', 'Purchase Discounts Received'],
            'freight_in' => ['تكاليف نقل ومناولة المشتريات', 'Inbound Freight and Handling Costs'],
            'direct_material_cost' => ['تكلفة المواد المباشرة المستهلكة', 'Direct Materials Consumed'],
            'direct_labor_cost' => ['تكلفة العمالة المباشرة', 'Direct Labor Cost'],
            'indirect_labor_cost' => ['تكلفة العمالة الصناعية غير المباشرة', 'Indirect Manufacturing Labor Cost'],
            'manufacturing_overhead' => ['التكاليف الصناعية غير المباشرة', 'Manufacturing Overhead'],
            'applied_manufacturing_overhead' => ['التكاليف الصناعية غير المباشرة المحملة على الإنتاج', 'Applied Manufacturing Overhead'],
            'factory_energy_expense' => ['تكلفة الطاقة والمرافق الصناعية', 'Factory Energy and Utilities Cost'],
            'factory_fuel_lubricants_expense' => ['تكلفة الوقود والزيوت الصناعية', 'Factory Fuel and Lubricants Cost'],
            'factory_maintenance_expense' => ['تكلفة صيانة وإصلاح معدات الإنتاج', 'Production Equipment Maintenance Cost'],
            'factory_spare_parts_expense' => ['تكلفة قطع الغيار المستهلكة في الإنتاج', 'Production Spare Parts Consumed'],
            'factory_operating_supplies_expense' => ['تكلفة مستلزمات التشغيل المستهلكة', 'Operating Supplies Consumed'],
            'factory_security_cleaning_expense' => ['تكلفة الأمن والنظافة الصناعية', 'Factory Security and Cleaning Cost'],
            'factory_transport_expense' => ['تكلفة النقل والحركة الصناعية', 'Factory Transportation Cost'],
            'factory_rent_expense' => ['تكلفة إيجار المصنع', 'Factory Rent Cost'],
            'quality_control_expense' => ['تكلفة مراقبة وضمان الجودة', 'Quality Control and Assurance Cost'],
            'warehouse_expense' => ['تكلفة تشغيل المخازن', 'Warehouse Operating Cost'],
            'production_services_expense' => ['تكلفة الخدمات الإنتاجية', 'Production Services Cost'],
            'factory_depreciation_expense' => ['إهلاك الأصول الإنتاجية', 'Depreciation of Production Assets'],
            'manufacturing_variance' => ['انحرافات تكاليف التصنيع', 'Manufacturing Cost Variances'],
            'abnormal_waste_loss' => ['خسائر الهالك غير الطبيعي', 'Abnormal Waste Losses'],
            'inventory_adjustment_loss' => ['خسائر تسويات المخزون', 'Inventory Adjustment Losses'],
            'warehouse_damage_loss' => ['خسائر تلف وهالك المخازن', 'Warehouse Damage and Scrap Loss'],
            'purchase_price_variance' => ['فروق أسعار الشراء', 'Purchase Price Variance'],
            'inventory_write_down_expense' => ['خسائر انخفاض قيمة المخزون', 'Inventory Write-down Losses'],
            'general_administrative_expense' => ['المصروفات العمومية والإدارية', 'General and Administrative Expenses'],
            'selling_marketing_expense' => ['مصروفات البيع والتسويق', 'Selling and Marketing Expenses'],
            'employee_benefits_expense' => ['مصروف منافع العاملين', 'Employee Benefits Expense'],
            'telecommunications_expense' => ['مصروف الاتصالات والإنترنت', 'Telecommunications and Internet Expense'],
            'office_supplies_expense' => ['مصروف الأدوات والمستلزمات المكتبية', 'Office Supplies Expense'],
            'vehicle_expense' => ['مصروف تشغيل وصيانة المركبات', 'Vehicle Operating and Maintenance Expense'],
            'professional_fees_expense' => ['مصروف الاستشارات والأتعاب المهنية', 'Professional Fees Expense'],
            'insurance_expense' => ['مصروف التأمين', 'Insurance Expense'],
            'government_fees_licenses_expense' => ['الرسوم الحكومية وتكاليف التراخيص', 'Government Fees and Licensing Costs'],
            'hospitality_expense' => ['مصروف الضيافة والعلاقات العامة', 'Hospitality and Public Relations Expense'],
            'advertising_expense' => ['مصروف الإعلان والدعاية', 'Advertising Expense'],
            'digital_marketing_expense' => ['مصروف التسويق الرقمي', 'Digital Marketing Expense'],
            'exhibitions_expense' => ['مصروف المعارض والفعاليات', 'Exhibitions and Events Expense'],
            'sales_delivery_expense' => ['مصروف نقل وتوصيل المبيعات', 'Sales Delivery Expense'],
            'sales_commissions_expense' => ['مصروف عمولات المبيعات', 'Sales Commissions Expense'],
            'customer_service_expense' => ['مصروف خدمة العملاء', 'Customer Service Expense'],
            'finance_cost' => ['تكاليف التمويل', 'Finance Costs'],
            'bank_charges' => ['المصروفات والعمولات البنكية', 'Bank Charges and Commissions'],
            'foreign_exchange_loss' => ['خسائر فروق العملة الأجنبية', 'Foreign Exchange Losses'],
            'loss_on_asset_disposal' => ['خسائر استبعاد الممتلكات والآلات والمعدات', 'Loss on Disposal of Property, Plant and Equipment'],
            'bad_debt_expense' => ['مصروف الخسائر الائتمانية المتوقعة', 'Expected Credit Loss Expense'],
            'income_tax_expense' => ['مصروف ضريبة الدخل', 'Income Tax Expense'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function originalDefinitions(): array
    {
        return [
            ...$this->rows([
                ['cash', 'نقدية', 'Cash'],
                ['bank', 'بنك', 'Bank'],
                ['accounts_receivable', 'عملاء / ذمم مدينة', 'Accounts Receivable'],
                ['inventory', 'مخزون', 'Inventory'],
                [AccountClassification::FixedAssets, 'أصول ثابتة', 'Fixed Assets'],
                ['accumulated_depreciation', 'مجمع الإهلاك', 'Accumulated Depreciation', Account::BalanceCredit],
                ['prepaid_expenses', 'مصروفات مدفوعة مقدمًا', 'Prepaid Expenses'],
            ], Account::TypeAsset, Account::StatementFinancialPosition, Account::BalanceDebit),
            ...$this->rows([
                ['accounts_payable', 'موردين / ذمم دائنة', 'Accounts Payable'],
                ['tax_payable', 'ضرائب مستحقة', 'Tax Payable'],
                ['loans_payable', 'قروض', 'Loans Payable'],
                ['accrued_expenses', 'مصروفات مستحقة', 'Accrued Expenses'],
            ], Account::TypeLiability, Account::StatementFinancialPosition, Account::BalanceCredit),
            ...$this->rows([
                ['capital', 'رأس المال', 'Capital'],
                ['retained_earnings', 'أرباح محتجزة', 'Retained Earnings'],
            ], Account::TypeEquity, Account::StatementFinancialPosition, Account::BalanceCredit),
            ...$this->rows([
                ['sales_revenue', 'إيرادات مبيعات', 'Sales Revenue'],
                ['service_revenue', 'إيرادات خدمات', 'Service Revenue'],
                ['sales_returns', 'مردودات مبيعات', 'Sales Returns', Account::BalanceDebit],
                ['sales_discounts', 'خصومات مبيعات', 'Sales Discounts', Account::BalanceDebit],
            ], Account::TypeRevenue, Account::StatementIncomeStatement, Account::BalanceCredit),
            ...$this->rows([
                [AccountClassification::Expenses, 'مصروفات', 'Expenses'],
                ['cost_of_goods_sold', 'تكلفة المبيعات', 'Cost of Goods Sold'],
                ['salary_expense', 'مصروف رواتب', 'Salary Expense'],
                ['rent_expense', 'مصروف إيجار', 'Rent Expense'],
                ['utilities_expense', 'مصروف مرافق', 'Utilities Expense'],
                ['depreciation_expense', 'مصروف إهلاك', 'Depreciation Expense'],
                ['other_expense', 'مصروفات أخرى', 'Other Expense'],
            ], Account::TypeExpense, Account::StatementIncomeStatement, Account::BalanceDebit),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function addedDefinitions(): array
    {
        return [
            ...$this->rows([
                ['employee_custody', 'عهد العاملين', 'Employee Custody'],
                ['employee_advances', 'سلف العاملين', 'Employee Advances'],
                ['supplier_advances', 'دفعات مقدمة للموردين', 'Supplier Advances'],
                ['other_receivables', 'أرصدة مدينة أخرى', 'Other Receivables'],
                ['recoverable_vat', 'ضريبة قيمة مضافة قابلة للاسترداد', 'Recoverable VAT'],
                ['withholding_tax_receivable', 'ضرائب خصم من المنبع - مدينة', 'Withholding Tax Receivable'],
                ['security_deposits', 'تأمينات لدى الغير', 'Security Deposits'],
                ['cheques_receivable', 'شيكات تحت التحصيل', 'Cheques Receivable'],
                ['cash_in_transit', 'نقدية بالطريق', 'Cash in Transit'],
                ['raw_material_inventory', 'مخزون خامات', 'Raw Material Inventory'],
                ['packaging_material_inventory', 'مخزون مواد تعبئة وتغليف', 'Packaging Material Inventory'],
                ['printing_ink_inventory', 'مخزون أحبار الطباعة', 'Printing Ink Inventory'],
                ['spare_parts_inventory', 'مخزون قطع غيار', 'Spare Parts Inventory'],
                ['operating_supplies_inventory', 'مخزون مستلزمات تشغيل', 'Operating Supplies Inventory'],
                ['work_in_process_inventory', 'مخزون إنتاج تحت التشغيل', 'Work in Process Inventory'],
                ['semi_finished_goods_inventory', 'مخزون منتجات نصف مصنعة', 'Semi-finished Goods Inventory'],
                ['finished_goods_inventory', 'مخزون إنتاج تام', 'Finished Goods Inventory'],
                ['goods_in_transit_inventory', 'مخزون بضاعة بالطريق', 'Goods in Transit Inventory'],
                ['scrap_waste_inventory', 'مخزون هالك وخردة', 'Scrap and Waste Inventory'],
                ['quarantine_inventory', 'مخزون تحت الفحص والحجر', 'Quarantine Inventory'],
                ['rework_inventory', 'مخزون إعادة التشغيل', 'Rework Inventory'],
                ['inventory_allowance', 'مخصص انخفاض قيمة المخزون', 'Inventory Allowance', Account::BalanceCredit],
                ['allowance_for_doubtful_accounts', 'مخصص خسائر ائتمانية متوقعة', 'Allowance for Doubtful Accounts', Account::BalanceCredit],
                ['construction_in_progress', 'أصول تحت الإنشاء', 'Construction in Progress'],
                ['machinery_equipment', 'آلات ومعدات', 'Machinery and Equipment'],
                ['molds_tooling', 'قوالب واسطمبات', 'Molds and Tooling'],
                ['vehicles', 'سيارات ووسائل نقل', 'Vehicles'],
                ['it_office_equipment', 'أجهزة تقنية معلومات ومعدات مكتبية', 'IT and Office Equipment'],
                ['furniture_fixtures', 'أثاث وتجهيزات', 'Furniture and Fixtures'],
                ['land', 'أراضٍ', 'Land'],
                ['buildings', 'مبانٍ', 'Buildings'],
                ['electrical_equipment', 'أجهزة ومعدات كهربائية', 'Electrical Equipment'],
            ], Account::TypeAsset, Account::StatementFinancialPosition, Account::BalanceDebit),
            ...$this->rows([
                ['goods_received_not_invoiced', 'بضائع مستلمة غير مفوترة', 'Goods Received Not Invoiced'],
                ['capital_expenditure_payables', 'دائنو شراء أصول ثابتة', 'Capital Expenditure Payables'],
                ['customer_advances', 'دفعات مقدمة من العملاء', 'Customer Advances'],
                ['related_party_payables', 'أطراف ذات علاقة دائنة', 'Related Party Payables'],
                ['social_insurance_payable', 'تأمينات اجتماعية مستحقة', 'Social Insurance Payable'],
                ['output_vat_payable', 'ضريبة قيمة مضافة مستحقة', 'Output VAT Payable'],
                ['withholding_tax_payable', 'ضرائب خصم من المنبع - دائنة', 'Withholding Tax Payable'],
                ['payroll_tax_payable', 'ضريبة كسب عمل مستحقة', 'Payroll Tax Payable'],
                ['corporate_income_tax_payable', 'ضريبة دخل مستحقة', 'Corporate Income Tax Payable'],
                ['payroll_payable', 'أجور ورواتب مستحقة', 'Payroll Payable'],
                ['cheques_payable', 'شيكات دفع', 'Cheques Payable'],
                ['provisions', 'مخصصات', 'Provisions'],
                ['legal_claims_provision', 'مخصص قضايا ومنازعات', 'Legal Claims Provision'],
                ['bonus_provision', 'مخصص مكافآت', 'Bonus Provision'],
                ['leave_provision', 'مخصص إجازات', 'Leave Provision'],
                ['current_loans_payable', 'قروض قصيرة الأجل', 'Current Loans Payable'],
                ['noncurrent_loans_payable', 'قروض طويلة الأجل', 'Non-current Loans Payable'],
                ['current_lease_liabilities', 'التزامات إيجار متداولة', 'Current Lease Liabilities'],
                ['noncurrent_lease_liabilities', 'التزامات إيجار غير متداولة', 'Non-current Lease Liabilities'],
                ['other_current_liabilities', 'التزامات متداولة أخرى', 'Other Current Liabilities'],
                ['other_noncurrent_liabilities', 'التزامات غير متداولة أخرى', 'Other Non-current Liabilities'],
            ], Account::TypeLiability, Account::StatementFinancialPosition, Account::BalanceCredit),
            ...$this->rows([
                ['current_year_result', 'أرباح أو خسائر الفترة', 'Current Year Result'],
                ['legal_reserve', 'الاحتياطي القانوني', 'Legal Reserve'],
                ['general_reserve', 'الاحتياطي العام', 'General Reserve'],
                ['other_reserves', 'احتياطيات أخرى', 'Other Reserves'],
            ], Account::TypeEquity, Account::StatementFinancialPosition, Account::BalanceCredit),
            ...$this->rows([
                ['other_operating_revenue', 'إيرادات تشغيلية أخرى', 'Other Operating Revenue'],
                ['other_income', 'إيرادات أخرى', 'Other Income'],
                ['scrap_sales_revenue', 'إيراد بيع هالك وخردة', 'Scrap Sales Revenue'],
                ['foreign_exchange_gain', 'أرباح فروق عملة', 'Foreign Exchange Gain'],
                ['gain_on_asset_disposal', 'أرباح استبعاد أصول ثابتة', 'Gain on Asset Disposal'],
                ['inventory_adjustment_gain', 'أرباح تسويات مخزون', 'Inventory Adjustment Gain'],
            ], Account::TypeRevenue, Account::StatementIncomeStatement, Account::BalanceCredit),
            ...$this->rows([
                ['purchases', 'المشتريات', 'Purchases'],
                ['raw_material_purchases', 'مشتريات خامات', 'Raw Material Purchases'],
                ['operating_supplies_purchases', 'مشتريات مستلزمات تشغيل', 'Operating Supplies Purchases'],
                ['other_purchases', 'مشتريات أخرى', 'Other Purchases'],
                ['purchase_returns', 'مردودات مشتريات', 'Purchase Returns', Account::BalanceCredit],
                ['purchase_discounts', 'خصم مكتسب', 'Purchase Discounts', Account::BalanceCredit],
                ['freight_in', 'نقل ومصاريف مشتريات', 'Freight In'],
                ['direct_material_cost', 'تكلفة مواد مباشرة', 'Direct Material Cost'],
                ['direct_labor_cost', 'تكلفة عمالة مباشرة', 'Direct Labor Cost'],
                ['indirect_labor_cost', 'تكلفة العمالة الصناعية غير المباشرة', 'Indirect Manufacturing Labor Cost'],
                ['manufacturing_overhead', 'تكاليف صناعية غير مباشرة', 'Manufacturing Overhead'],
                ['applied_manufacturing_overhead', 'تكاليف صناعية غير مباشرة محملة', 'Applied Manufacturing Overhead', Account::BalanceCredit],
                ['factory_energy_expense', 'كهرباء وطاقة تشغيلية', 'Factory Energy Expense'],
                ['factory_fuel_lubricants_expense', 'وقود وزيوت تشغيلية', 'Factory Fuel and Lubricants Expense'],
                ['factory_maintenance_expense', 'صيانة وإصلاحات تشغيلية', 'Factory Maintenance Expense'],
                ['factory_spare_parts_expense', 'مصروف قطع غيار تشغيلية', 'Factory Spare Parts Expense'],
                ['factory_operating_supplies_expense', 'مصروف مستلزمات تشغيل', 'Factory Operating Supplies Expense'],
                ['factory_security_cleaning_expense', 'أمن ونظافة المصنع', 'Factory Security and Cleaning Expense'],
                ['factory_transport_expense', 'نقل وحركة تشغيلية', 'Factory Transport Expense'],
                ['factory_rent_expense', 'إيجارات تشغيلية', 'Factory Rent Expense'],
                ['quality_control_expense', 'مصروفات الجودة', 'Quality Control Expense'],
                ['warehouse_expense', 'مصروفات المخازن', 'Warehouse Expense'],
                ['production_services_expense', 'خدمات تشغيلية وإنتاجية', 'Production Services Expense'],
                ['factory_depreciation_expense', 'إهلاكات تشغيلية', 'Factory Depreciation Expense'],
                ['manufacturing_variance', 'انحرافات التصنيع', 'Manufacturing Variance'],
                ['abnormal_waste_loss', 'خسائر هالك غير طبيعي', 'Abnormal Waste Loss'],
                ['inventory_adjustment_loss', 'خسائر تسويات مخزون', 'Inventory Adjustment Loss'],
                ['warehouse_damage_loss', 'خسائر تلف وهالك المخازن', 'Warehouse Damage and Scrap Loss'],
                ['purchase_price_variance', 'فروق أسعار الشراء', 'Purchase Price Variance'],
                ['inventory_write_down_expense', 'مصروف انخفاض قيمة المخزون', 'Inventory Write-down Expense'],
                ['general_administrative_expense', 'مصروفات عمومية وإدارية', 'General and Administrative Expense'],
                ['selling_marketing_expense', 'مصروفات بيعية وتسويقية', 'Selling and Marketing Expense'],
                ['employee_benefits_expense', 'تأمينات ومزايا عاملين', 'Employee Benefits Expense'],
                ['telecommunications_expense', 'اتصالات وإنترنت', 'Telecommunications Expense'],
                ['office_supplies_expense', 'أدوات ومستلزمات مكتبية', 'Office Supplies Expense'],
                ['vehicle_expense', 'مصروفات سيارات', 'Vehicle Expense'],
                ['professional_fees_expense', 'استشارات وأتعاب مهنية', 'Professional Fees Expense'],
                ['insurance_expense', 'مصروفات تأمين', 'Insurance Expense'],
                ['government_fees_licenses_expense', 'رسوم حكومية وتراخيص', 'Government Fees and Licenses Expense'],
                ['hospitality_expense', 'ضيافة وعلاقات عامة', 'Hospitality Expense'],
                ['advertising_expense', 'إعلان ودعاية', 'Advertising Expense'],
                ['digital_marketing_expense', 'تسويق رقمي', 'Digital Marketing Expense'],
                ['exhibitions_expense', 'عروض ومعارض', 'Exhibitions Expense'],
                ['sales_delivery_expense', 'نقل وتوصيل مبيعات', 'Sales Delivery Expense'],
                ['sales_commissions_expense', 'عمولات مبيعات', 'Sales Commissions Expense'],
                ['customer_service_expense', 'مصروف خدمة العملاء', 'Customer Service Expense'],
                ['finance_cost', 'تكاليف تمويل', 'Finance Cost'],
                ['bank_charges', 'مصروفات وعمولات بنكية', 'Bank Charges'],
                ['foreign_exchange_loss', 'خسائر فروق عملة', 'Foreign Exchange Loss'],
                ['loss_on_asset_disposal', 'خسائر استبعاد أصول ثابتة', 'Loss on Asset Disposal'],
                ['bad_debt_expense', 'مصروف ديون مشكوك في تحصيلها', 'Bad Debt Expense'],
                ['income_tax_expense', 'مصروف ضريبة الدخل', 'Income Tax Expense'],
            ], Account::TypeExpense, Account::StatementIncomeStatement, Account::BalanceDebit),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows, string $accountType, string $statementType, string $normalBalance): array
    {
        return array_map(fn (array $row): array => [
            'code' => $row[0],
            'name' => $row[1],
            'name_en' => $row[2],
            'account_type' => $accountType,
            'statement_type' => $statementType,
            'normal_balance' => $row[3] ?? $normalBalance,
            'is_system' => true,
            'status' => 'active',
        ], $rows);
    }
}
