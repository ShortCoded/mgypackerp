<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Validator;
use Modules\Core\Models\ItemLookup;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;

trait ValidatesItemUnitEquivalence
{
    use NormalizesNumericInput;

    protected function prepareItemUnitEquivalenceForValidation(): void
    {
        if (! $this->isItemUnitsLookup()) {
            return;
        }

        $this->normalizeNumericInput(['equivalent_value']);

        foreach (['equivalent_value', 'equivalent_unit_doc_num'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->itemUnitBlankToNull($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemUnitEquivalenceRules(): array
    {
        if (! $this->isItemUnitsLookup()) {
            return [];
        }

        return [
            'equivalent_value' => ['nullable', 'numeric', 'gt:0', 'regex:/^(?:\d{1,12}|\d{0,12}\.\d{1,6})$/', 'required_with:equivalent_unit_doc_num'],
            'equivalent_unit_doc_num' => ['nullable', 'string', 'required_with:equivalent_value', $this->activeEquivalentUnitExistsRule()],
        ];
    }

    protected function validateEquivalentUnitIsNotSelf(Validator $validator, ?ItemLookup $record): void
    {
        if (! $this->isItemUnitsLookup()
            || ! $record instanceof ItemLookup
            || $validator->errors()->has('equivalent_unit_doc_num')
        ) {
            return;
        }

        $equivalentUnitDocNum = trim((string) $this->input('equivalent_unit_doc_num'));

        if ($equivalentUnitDocNum !== '' && $equivalentUnitDocNum === (string) $record->doc_num) {
            $validator->errors()->add('equivalent_unit_doc_num', __('item_units.validation.equivalent_unit_self'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeItemUnitEquivalenceData(array $data): array
    {
        if (! $this->isItemUnitsLookup()) {
            return $data;
        }

        $data['equivalent_value'] = $this->normalizeItemUnitDecimal($data['equivalent_value'] ?? null);
        $data['equivalent_unit_id'] = $this->equivalentUnitId($data['equivalent_unit_doc_num'] ?? null);

        unset($data['equivalent_unit_doc_num']);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    protected function itemUnitEquivalenceAttributes(): array
    {
        if (! $this->isItemUnitsLookup()) {
            return [];
        }

        return [
            'equivalent_value' => __('item_units.fields.equivalent_value'),
            'equivalent_unit_doc_num' => __('item_units.fields.equivalent_unit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function itemUnitEquivalenceMessages(): array
    {
        if (! $this->isItemUnitsLookup()) {
            return [];
        }

        return [
            'equivalent_value.required_with' => __('item_units.validation.equivalent_value_required'),
            'equivalent_value.numeric' => __('item_units.validation.equivalent_value_numeric'),
            'equivalent_value.gt' => __('item_units.validation.equivalent_value_gt_zero'),
            'equivalent_value.regex' => __('item_units.validation.equivalent_value_precision'),
            'equivalent_unit_doc_num.required_with' => __('item_units.validation.equivalent_unit_required'),
            'equivalent_unit_doc_num.exists' => __('item_units.validation.equivalent_unit_exists'),
        ];
    }

    private function isItemUnitsLookup(): bool
    {
        return $this->definition()->key === 'item_units';
    }

    private function activeEquivalentUnitExistsRule(): Exists
    {
        return Rule::exists('item_units', 'doc_num')
            ->where('company_id', $this->itemUnitCompanyId())
            ->whereNull('deleted_at')
            ->where('status', 'active');
    }

    private function equivalentUnitId(mixed $docNum): ?int
    {
        $docNum = trim((string) $docNum);

        if ($docNum === '') {
            return null;
        }

        return ItemUnit::query()
            ->forCompany($this->itemUnitCompanyId())
            ->where('doc_num', $docNum)
            ->where('status', 'active')
            ->value('id');
    }

    private function itemUnitCompanyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function normalizeItemUnitDecimal(mixed $value): ?string
    {
        return app(NumericFormatService::class)->normalizeToScale($value, 6);
    }

    private function itemUnitBlankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
