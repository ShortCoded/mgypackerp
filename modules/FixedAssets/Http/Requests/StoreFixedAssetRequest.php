<?php

namespace Modules\FixedAssets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Accounting\Services\BusinessPartnerAccountService;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\Branch;
use Modules\Core\Models\BranchHall;
use Modules\Core\Models\Currency;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\FilePickerService;
use Modules\Core\Services\OperatingContextService;
use Modules\FixedAssets\Models\FixedAsset;

class StoreFixedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'fixed_assets.clone' : 'fixed_assets.create');
    }

    protected function prepareForValidation(): void
    {
        $dates = app(DateFormatService::class);

        $this->merge([
            'asset_name' => trim((string) $this->input('asset_name')),
            'image_archive_file_doc_num' => $this->nullableTrim('image_archive_file_doc_num'),
            'remove_image' => $this->has('remove_image') ? $this->boolean('remove_image') : false,
            'entry_type' => $this->nullableTrim('entry_type'),
            'asset_group_account_doc_num' => $this->nullableTrim('asset_group_account_doc_num'),
            'credit_account_doc_num' => $this->nullableTrim('credit_account_doc_num'),
            'cost_center_doc_num' => $this->nullableTrim('cost_center_doc_num'),
            'branch_doc_num' => $this->nullableTrim('branch_doc_num'),
            'branch_hall_uuid' => $this->nullableTrim('branch_hall_uuid'),
            'currency_doc_num' => $this->nullableTrim('currency_doc_num'),
            'asset_date' => $dates->normalizeForStorage($this->nullableTrim('asset_date')),
            'purchase_date' => $dates->normalizeForStorage($this->nullableTrim('purchase_date')),
            'acquisition_date' => $dates->normalizeForStorage($this->nullableTrim('acquisition_date')),
            'operation_date' => $dates->normalizeForStorage($this->nullableTrim('operation_date')),
            'previous_depreciation_until_date' => $dates->normalizeForStorage($this->nullableTrim('previous_depreciation_until_date')),
            'description' => $this->nullableTrim('description'),
            'serial_number' => $this->nullableTrim('serial_number'),
            'location_address' => $this->nullableTrim('location_address'),
            'notes' => $this->nullableTrim('notes'),
            'is_depreciable' => $this->has('is_depreciable') && $this->nullableTrim('is_depreciable') !== null ? $this->boolean('is_depreciable') : null,
            'purchase_value' => $this->normalizedNumber('purchase_value'),
            'salvage_value' => $this->normalizedNumber('salvage_value') ?? '0',
            'exchange_rate' => $this->normalizedNumber('exchange_rate'),
            'previous_depreciation' => $this->normalizedNumber('previous_depreciation'),
            'depreciation_method' => $this->nullableTrim('depreciation_method'),
            'annual_depreciation_rate' => $this->normalizedNumber('annual_depreciation_rate'),
            'expected_usage_units' => $this->normalizedNumber('expected_usage_units'),
            'useful_life' => $this->normalizedNumber('useful_life'),
            'net_value' => null,
            'depreciation_start_date' => null,
        ]);

        if ($this->filled('image_archive_file_doc_num')) {
            $this->merge(['remove_image' => false]);
        }
    }

    public function rules(): array
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');

        return [
            'doc_number' => ['nullable', 'integer', 'min:1', $this->uniqueActiveRule('doc_number')],
            'entry_type' => ['required', Rule::in(FixedAsset::entryTypes())],
            'asset_date' => ['required', 'date'],
            'asset_name' => ['required', 'string', 'max:255', $this->uniqueActiveRule('asset_name')],
            'image_archive_file_doc_num' => ['nullable', 'string', 'max:255'],
            'remove_image' => ['nullable', 'boolean'],
            'asset_group_account_doc_num' => [
                'required',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'credit_account_doc_num' => [
                'required',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'cost_center_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cost_centers', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'branch_doc_num' => [
                'required',
                'string',
                Rule::exists('branches', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'branch_hall_uuid' => ['nullable', 'string'],
            'currency_doc_num' => [
                'required',
                'string',
                Rule::exists('currencies', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'description' => ['required', 'string'],
            'serial_number' => ['nullable', 'string', 'max:255', $this->uniqueActiveRule('serial_number')],
            'purchase_date' => ['required', 'date'],
            'acquisition_date' => ['nullable', 'date'],
            'operation_date' => [Rule::requiredIf($this->isDepreciable()), 'nullable', 'date'],
            'purchase_value' => ['required', 'numeric', 'gt:0'],
            'salvage_value' => ['nullable', 'numeric', 'min:0'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'previous_depreciation' => [Rule::requiredIf($this->isDepreciable() && $this->input('entry_type') === FixedAsset::EntryTypeOpeningAsset), 'nullable', 'numeric', 'min:0'],
            'previous_depreciation_until_date' => ['nullable', 'date'],
            'depreciation_method' => [Rule::requiredIf($this->isDepreciable()), 'nullable', Rule::in(FixedAsset::depreciationMethods())],
            'annual_depreciation_rate' => ['nullable', 'numeric', 'gt:0', 'max:100'],
            'expected_usage_units' => ['nullable', 'numeric', 'gt:0'],
            'useful_life' => ['nullable', 'numeric', 'gt:0'],
            'is_depreciable' => ['required', 'boolean'],
            'location_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validatePeriodDates($validator);
            $this->validateAccounts($validator);
            $this->validateCostCenter($validator);
            $this->validateBranch($validator);
            $this->validateBranchHall($validator);
            $this->validateCurrency($validator);
            $this->validateFinancialValues($validator);
            $this->validateDepreciationSetup($validator);
            $this->validateDateSequence($validator);
            $this->validateImageSelection($validator);
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('fixed_assets.attributes.doc_number'),
            'entry_type' => __('fixed_assets.attributes.entry_type'),
            'asset_date' => __('fixed_assets.attributes.asset_date'),
            'asset_name' => __('fixed_assets.attributes.asset_name'),
            'image' => __('fixed_assets.attributes.image'),
            'image_archive_file_doc_num' => __('fixed_assets.attributes.image'),
            'asset_group_account_doc_num' => __('fixed_assets.attributes.asset_group_account'),
            'credit_account_doc_num' => __('fixed_assets.attributes.credit_account'),
            'cost_center_doc_num' => __('fixed_assets.attributes.cost_center'),
            'branch_doc_num' => __('fixed_assets.attributes.branch'),
            'branch_hall_uuid' => __('fixed_assets.attributes.hall'),
            'currency_doc_num' => __('fixed_assets.attributes.currency'),
            'status' => __('fixed_assets.attributes.status'),
            'description' => __('fixed_assets.attributes.description'),
            'serial_number' => __('fixed_assets.attributes.serial_number'),
            'purchase_date' => __('fixed_assets.attributes.purchase_date'),
            'acquisition_date' => __('fixed_assets.attributes.acquisition_date'),
            'operation_date' => __('fixed_assets.attributes.operation_date'),
            'purchase_value' => __('fixed_assets.attributes.purchase_value'),
            'salvage_value' => __('fixed_assets.attributes.salvage_value'),
            'exchange_rate' => __('fixed_assets.attributes.exchange_rate'),
            'previous_depreciation' => __('fixed_assets.attributes.previous_depreciation'),
            'previous_depreciation_until_date' => __('fixed_assets.attributes.previous_depreciation_until_date'),
            'depreciation_start_date' => __('fixed_assets.attributes.depreciation_start_date'),
            'depreciation_method' => __('fixed_assets.attributes.depreciation_method'),
            'annual_depreciation_rate' => __('fixed_assets.attributes.annual_depreciation_rate'),
            'expected_usage_units' => __('fixed_assets.attributes.expected_usage_units'),
            'useful_life' => __('fixed_assets.attributes.useful_life'),
            'is_depreciable' => __('fixed_assets.attributes.is_depreciable'),
            'location_address' => __('fixed_assets.attributes.location_address'),
            'notes' => __('fixed_assets.attributes.notes'),
        ];
    }

    public function messages(): array
    {
        return [
            'doc_number.unique' => __('fixed_assets.messages.doc_number_unique'),
            'asset_name.unique' => __('fixed_assets.messages.asset_name_unique'),
            'serial_number.unique' => __('fixed_assets.messages.serial_number_unique'),
            'entry_type.required' => __('fixed_assets.messages.entry_type_required'),
            'purchase_value.required' => __('fixed_assets.messages.purchase_value_required'),
            'purchase_value.gt' => __('fixed_assets.messages.purchase_value_gt_zero'),
            'salvage_value.min' => __('fixed_assets.messages.salvage_value_negative'),
            'purchase_date.required' => __('fixed_assets.messages.purchase_date_required'),
            'operation_date.required' => __('fixed_assets.messages.operation_date_required_if_depreciable'),
            'previous_depreciation.required' => __('validation.required', ['attribute' => __('fixed_assets.attributes.previous_depreciation')]),
            'previous_depreciation.min' => __('fixed_assets.messages.previous_depreciation_negative'),
            'depreciation_method.required' => __('fixed_assets.messages.depreciation_method_required_if_depreciable'),
            'useful_life.required' => __('validation.required', ['attribute' => __('fixed_assets.attributes.useful_life')]),
            'expected_usage_units.gt' => __('fixed_assets.messages.expected_usage_units_gt_zero'),
            'description.required' => __('fixed_assets.messages.description_required'),
        ];
    }

    protected function currentFixedAsset(): ?FixedAsset
    {
        return null;
    }

    protected function uniqueActiveRule(string $column): Unique
    {
        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $rule = Rule::unique('fixed_assets', $column)
            ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'));
        $current = $this->currentFixedAsset();

        return $current ? $rule->ignore($current->getKey()) : $rule;
    }

    protected function nullableTrim(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }

    private function normalizedNumber(string $field): ?string
    {
        $value = str_replace(',', '', trim((string) $this->input($field)));

        return $value === '' ? null : $value;
    }

    private function validatePeriodDates(Validator $validator): void
    {
        $periodId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'financial_period_id');
        $period = $periodId ? FinancialPeriod::query()->find($periodId) : null;

        if (! $period instanceof FinancialPeriod) {
            $validator->errors()->add('asset_date', __('operating_context.messages.required'));

            return;
        }

        $value = $this->dateString('asset_date');

        if ($value && ($value < $period->from_date->toDateString() || $value > $period->to_date->toDateString())) {
            $validator->errors()->add('asset_date', __('fixed_assets.messages.date_outside_period'));
        }
    }

    private function validateAccounts(Validator $validator): void
    {
        $accounts = app(BusinessPartnerAccountService::class);
        $parent = $this->resolvedAssetCategory();

        if ($this->filled('asset_group_account_doc_num') && ! $parent) {
            $validator->errors()->add('asset_group_account_doc_num', __('fixed_assets.messages.asset_category_unavailable'));
        }

        if ($this->filled('asset_group_account_doc_num') && $parent && (! $parent->is_group || $parent->is_postable)) {
            $validator->errors()->add('asset_group_account_doc_num', __('fixed_assets.messages.asset_category_must_be_group'));
        }

        if ($this->filled('asset_group_account_doc_num') && $parent && $parent->is_group && ! $accounts->isSelectableGroup(BusinessPartnerAccountService::FixedAsset, $parent)) {
            $validator->errors()->add('asset_group_account_doc_num', __('fixed_assets.messages.asset_category_unavailable'));
        }

        if ($parent && $this->hasDuplicateLinkedAccount($parent)) {
            $validator->errors()->add('asset_group_account_doc_num', __('fixed_assets.messages.linked_account_duplicate'));
        }

        if ($this->filled('credit_account_doc_num')) {
            $credit = $this->accountByDocNum((string) $this->input('credit_account_doc_num'));

            if (! $credit instanceof Account || $credit->is_group || ! $credit->is_postable || $credit->status !== 'active') {
                $validator->errors()->add('credit_account_doc_num', __('fixed_assets.messages.credit_account_unavailable'));
            }
        }
    }

    private function validateCostCenter(Validator $validator): void
    {
        if (! $this->filled('cost_center_doc_num')) {
            return;
        }

        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $costCenter = CostCenter::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $this->input('cost_center_doc_num'))
            ->whereNull('deleted_at')
            ->first();

        if (! $costCenter instanceof CostCenter || $costCenter->status !== 'active' || $costCenter->is_group) {
            $validator->errors()->add('cost_center_doc_num', __('fixed_assets.messages.cost_center_unavailable'));
        }
    }

    private function validateBranch(Validator $validator): void
    {
        if (! $this->filled('branch_doc_num')) {
            return;
        }

        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $this->input('branch_doc_num'))
            ->whereNull('deleted_at')
            ->first();

        if (! $branch instanceof Branch || $branch->status !== 'active') {
            $validator->errors()->add('branch_doc_num', __('fixed_assets.messages.branch_unavailable'));
        }
    }

    private function validateBranchHall(Validator $validator): void
    {
        if (! $this->filled('branch_hall_uuid')) {
            return;
        }

        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $branch = Branch::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $this->input('branch_doc_num'))
            ->whereNull('deleted_at')
            ->first();

        if (! $branch instanceof Branch) {
            $validator->errors()->add('branch_hall_uuid', __('fixed_assets.messages.hall_branch_mismatch'));

            return;
        }

        $hallExists = BranchHall::query()
            ->where('branch_id', $branch->getKey())
            ->where('public_uuid', $this->input('branch_hall_uuid'))
            ->whereNull('deleted_at')
            ->exists();

        if (! $hallExists) {
            $validator->errors()->add('branch_hall_uuid', __('fixed_assets.messages.hall_branch_mismatch'));
        }
    }

    private function validateCurrency(Validator $validator): void
    {
        if (! $this->filled('currency_doc_num')) {
            return;
        }

        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');
        $currency = Currency::query()
            ->where('company_id', $companyId)
            ->where('doc_num', $this->input('currency_doc_num'))
            ->whereNull('deleted_at')
            ->first();

        if (! $currency instanceof Currency || $currency->status !== 'active') {
            $validator->errors()->add('currency_doc_num', __('fixed_assets.messages.currency_unavailable'));

            return;
        }

        if (! $this->filled('exchange_rate')) {
            $validator->errors()->add('exchange_rate', __('validation.required', ['attribute' => __('fixed_assets.attributes.exchange_rate')]));

            return;
        }

        if ($currency->is_main && (float) $this->input('exchange_rate') !== 1.0) {
            $validator->errors()->add('exchange_rate', __('fixed_assets.messages.main_currency_exchange_rate_must_be_one'));
        }
    }

    private function validateFinancialValues(Validator $validator): void
    {
        $purchaseValue = $this->filled('purchase_value') ? (float) $this->input('purchase_value') : null;
        $previousDepreciation = $this->filled('previous_depreciation') ? (float) $this->input('previous_depreciation') : null;
        $salvageValue = $this->filled('salvage_value') ? (float) $this->input('salvage_value') : 0.0;
        $usefulLife = $this->filled('useful_life') ? (float) $this->input('useful_life') : null;
        $annualDepreciationRate = $this->filled('annual_depreciation_rate') ? (float) $this->input('annual_depreciation_rate') : null;

        if (! $this->isDepreciable()) {
            return;
        }

        if ($this->isDepreciable() && $purchaseValue !== null && $salvageValue >= $purchaseValue) {
            $validator->errors()->add('salvage_value', __('fixed_assets.messages.salvage_value_exceeds_purchase_value'));
        }

        if ($previousDepreciation !== null && $previousDepreciation > 0 && ($purchaseValue === null || $purchaseValue <= 0)) {
            $validator->errors()->add('previous_depreciation', __('fixed_assets.messages.previous_depreciation_exceeds_depreciable_base'));
        }

        if ($previousDepreciation !== null && $purchaseValue !== null && $previousDepreciation > max(0.0, $purchaseValue - $salvageValue)) {
            $validator->errors()->add('previous_depreciation', __('fixed_assets.messages.previous_depreciation_exceeds_depreciable_base'));
            $validator->errors()->add('previous_depreciation', __('fixed_assets.messages.previous_depreciation_plus_salvage_exceeds_purchase_value'));
        }

        if ($previousDepreciation !== null && $previousDepreciation > 0 && ! $this->filled('previous_depreciation_until_date')) {
            $validator->errors()->add('previous_depreciation_until_date', __('fixed_assets.messages.previous_depreciation_until_required'));
        }

        if ($this->input('depreciation_method') === FixedAsset::DepreciationMethodStraightLine
            && $usefulLife !== null
            && $annualDepreciationRate !== null
            && $usefulLife > 0
            && $annualDepreciationRate > 0
        ) {
            $expectedRate = 100 / $usefulLife;

            if (abs($expectedRate - $annualDepreciationRate) > 0.05) {
                $validator->errors()->add('annual_depreciation_rate', __('fixed_assets.messages.depreciation_values_inconsistent'));
            }
        }
    }

    private function validateDepreciationSetup(Validator $validator): void
    {
        if (! $this->isDepreciable()) {
            return;
        }

        $method = (string) $this->input('depreciation_method');

        if (! in_array($method, FixedAsset::depreciationMethods(), true)) {
            return;
        }

        if ($method === FixedAsset::DepreciationMethodStraightLine && ! $this->filled('useful_life') && ! $this->filled('annual_depreciation_rate')) {
            $validator->errors()->add('useful_life', __('fixed_assets.messages.straight_line_life_or_rate_required'));

            return;
        }

        if ($method === FixedAsset::DepreciationMethodDecliningBalance && ! $this->filled('annual_depreciation_rate')) {
            $validator->errors()->add('annual_depreciation_rate', __('fixed_assets.messages.declining_balance_rate_required'));

            return;
        }

        if (in_array($method, [FixedAsset::DepreciationMethodDoubleDecliningBalance, FixedAsset::DepreciationMethodSumOfYearsDigits], true)
            && ! $this->filled('useful_life')
        ) {
            $validator->errors()->add('useful_life', __('fixed_assets.messages.useful_life_required_for_method'));

            return;
        }

        if ($method === FixedAsset::DepreciationMethodUnitsOfProduction && ! $this->filled('expected_usage_units')) {
            $validator->errors()->add('expected_usage_units', __('fixed_assets.messages.expected_usage_units_required'));
        }
    }

    private function validateImageSelection(Validator $validator): void
    {
        $publicId = trim((string) $this->input('image_archive_file_doc_num'));

        if ($publicId === '') {
            return;
        }

        if (! $this->user()?->can('file_manager.view')) {
            $validator->errors()->add('image', __('fixed_assets.validation.selected_file_unavailable'));

            return;
        }

        $companyId = Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id');

        if (! $companyId) {
            $validator->errors()->add('image', __('fixed_assets.validation.selected_file_unavailable'));

            return;
        }

        $files = app(FilePickerService::class);
        $file = $files->fileForCompany($publicId, (int) $companyId);

        if (! $file instanceof ArchiveFile) {
            $validator->errors()->add('image', __('fixed_assets.validation.selected_file_unavailable'));

            return;
        }

        if (! $files->isAvailableFile($file)) {
            $validator->errors()->add('image', __('fixed_assets.validation.selected_file_unavailable'));

            return;
        }

        if ($files->fileHiddenFromPicker($file)) {
            $validator->errors()->add('image', __('fixed_assets.validation.selected_file_hidden_from_picker'));

            return;
        }

        if (! $files->isImageFile($file)) {
            $validator->errors()->add('image', __('fixed_assets.validation.selected_file_not_image'));
        }
    }

    private function isDepreciable(): bool
    {
        return filter_var($this->input('is_depreciable'), FILTER_VALIDATE_BOOL);
    }

    private function resolvedAssetCategory(): ?Account
    {
        if (! $this->filled('asset_group_account_doc_num')) {
            return null;
        }

        return $this->accountByDocNum((string) $this->input('asset_group_account_doc_num'));
    }

    private function validateDateSequence(Validator $validator): void
    {
        $purchaseDate = $this->dateString('purchase_date');
        $acquisitionDate = $this->dateString('acquisition_date');
        $operationDate = $this->dateString('operation_date');
        $previousDepreciationUntilDate = $this->dateString('previous_depreciation_until_date');

        if ($purchaseDate && $acquisitionDate && $acquisitionDate < $purchaseDate) {
            $validator->errors()->add('acquisition_date', __('fixed_assets.messages.acquisition_before_purchase'));
        }

        if ($purchaseDate && $operationDate && $operationDate < $purchaseDate) {
            $validator->errors()->add('operation_date', __('fixed_assets.messages.operation_before_purchase'));
        }

        if ($acquisitionDate && $operationDate && $operationDate < $acquisitionDate) {
            $validator->errors()->add('operation_date', __('fixed_assets.messages.operation_before_acquisition'));
        }

        if ($this->isDepreciable() && $purchaseDate && $previousDepreciationUntilDate && $previousDepreciationUntilDate < $purchaseDate) {
            $validator->errors()->add('previous_depreciation_until_date', __('fixed_assets.messages.previous_depreciation_until_before_purchase'));
        }

        if ($this->isDepreciable() && $operationDate && $previousDepreciationUntilDate && $previousDepreciationUntilDate < $operationDate) {
            $validator->errors()->add('previous_depreciation_until_date', __('fixed_assets.messages.previous_depreciation_until_before_operation'));
        }
    }

    private function dateString(string $field): ?string
    {
        $value = (string) $this->input($field);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function accountByDocNum(string $docNum): ?Account
    {
        return Account::query()
            ->with('classification')
            ->where('company_id', Arr::get(app(OperatingContextService::class)->snapshot($this), 'company_id'))
            ->where('doc_num', $docNum)
            ->whereNull('deleted_at')
            ->first();
    }

    private function hasDuplicateLinkedAccount(Account $parentAccount): bool
    {
        $current = $this->currentFixedAsset();
        $currentAccountId = $current?->account_id;
        $name = app(BusinessPartnerAccountService::class)->linkedAccountName(['name' => (string) $this->input('asset_name')]);

        return Account::query()
            ->whereNull('deleted_at')
            ->where('company_id', $parentAccount->company_id)
            ->where('parent_id', $parentAccount->getKey())
            ->where('name', $name)
            ->when($currentAccountId, fn ($query) => $query->whereKeyNot($currentAccountId))
            ->exists();
    }
}
