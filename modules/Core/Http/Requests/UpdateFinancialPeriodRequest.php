<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\FinancialPeriod;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\DocumentNumberService;
use Modules\Core\Services\OperatingCompanyContextService;

class UpdateFinancialPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('financial_periods.edit')
            && $this->financialPeriodRecord() instanceof FinancialPeriod;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $financialPeriod = $this->financialPeriodRecord();
        $companyId = $this->companyId();

        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('financial_periods', 'name')
                    ->where('company_id', $companyId)
                    ->withoutTrashed()
                    ->ignore($financialPeriod?->getKey()),
            ],
            'from_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('financial_periods.validation.from_date_invalid'));
                }
            }],
            'to_date' => ['required', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! app(DateFormatService::class)->isValidDate(is_string($value) ? $value : null)) {
                    $fail(__('financial_periods.validation.to_date_invalid'));
                }
            }],
            'is_closed' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('financial_periods', 'doc_number')
                    ->where('company_id', $companyId)
                    ->ignore($financialPeriod?->getKey())
                    ->withoutTrashed(),
            ];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) $this->input('name')),
            ]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $financialPeriod = $this->financialPeriodRecord();
            $dates = app(DateFormatService::class);
            $fromDate = $dates->parseDate(is_string($this->input('from_date')) ? $this->input('from_date') : null);
            $toDate = $dates->parseDate(is_string($this->input('to_date')) ? $this->input('to_date') : null);

            if ($fromDate !== null && $toDate !== null && $toDate->lt($fromDate)) {
                $validator->errors()->add('to_date', __('financial_periods.validation.to_date_after_or_equal'));
            }

            if ($financialPeriod instanceof FinancialPeriod
                && $this->boolean('is_closed') !== (bool) $financialPeriod->is_closed) {
                $validator->errors()->add('is_closed', __('financial_periods.messages.status_requires_workflow'));
            }

            if ($financialPeriod instanceof FinancialPeriod
                && $fromDate !== null
                && $toDate !== null
                && $toDate->gte($fromDate)
                && ! $validator->errors()->has('from_date')
                && ! $validator->errors()->has('to_date')
            ) {
                $this->addOverlapErrors($validator, $fromDate->toDateString(), $toDate->toDateString(), $financialPeriod);
            }

            if (! $this->canControlDocumentNumber()
                || ! $financialPeriod instanceof FinancialPeriod
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format('financial_periods', $docNumber);

            if (FinancialPeriod::query()
                ->forCompany($this->companyId())
                ->where('doc_num', $docNum)
                ->whereKeyNot($financialPeriod->getKey())
                ->exists()) {
                $validator->errors()->add('doc_number', __('financial_periods.validation.doc_number_unique'));
            }
        });
    }

    /**
     * @param  string|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        if (! $this->canControlDocumentNumber()) {
            unset($data['doc_number']);
        }

        if (array_key_exists('doc_number', $data) && ($data['doc_number'] === null || $data['doc_number'] === '')) {
            unset($data['doc_number']);
        } elseif (array_key_exists('doc_number', $data)) {
            $data['doc_number'] = (int) $data['doc_number'];
        }

        if (array_key_exists('is_closed', $data) && $data['is_closed'] !== null && $data['is_closed'] !== '') {
            $data['is_closed'] = $this->boolean('is_closed');
        }

        $dates = app(DateFormatService::class);

        foreach (['from_date', 'to_date'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $dates->normalizeForStorage((string) $data[$field]);
            }
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('financial_periods.attributes');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.regex' => __('financial_periods.validation.doc_number_numeric'),
            'doc_number.unique' => __('financial_periods.validation.doc_number_unique'),
            'name.unique' => __('financial_periods.validation.name_unique'),
            'to_date.after_or_equal' => __('financial_periods.validation.to_date_after_or_equal'),
        ];
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('financial_periods.document_number.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    private function addOverlapErrors(Validator $validator, string $fromDate, string $toDate, FinancialPeriod $financialPeriod): void
    {
        $overlappingPeriods = FinancialPeriod::query()
            ->forCompany($this->companyId())
            ->whereKeyNot($financialPeriod->getKey())
            ->whereDate('from_date', '<=', $toDate)
            ->whereDate('to_date', '>=', $fromDate)
            ->get(['from_date', 'to_date']);

        if ($overlappingPeriods->isEmpty()) {
            return;
        }

        $fromDateInsideExisting = $overlappingPeriods->contains(
            fn (FinancialPeriod $period): bool => $period->from_date !== null
                && $period->to_date !== null
                && $fromDate >= $period->from_date->toDateString()
                && $fromDate <= $period->to_date->toDateString()
        );
        $toDateInsideExisting = $overlappingPeriods->contains(
            fn (FinancialPeriod $period): bool => $period->from_date !== null
                && $period->to_date !== null
                && $toDate >= $period->from_date->toDateString()
                && $toDate <= $period->to_date->toDateString()
        );

        if ($fromDateInsideExisting) {
            $validator->errors()->add('from_date', __('financial_periods.validation.date_inside_existing'));
        }

        if ($toDateInsideExisting) {
            $validator->errors()->add('to_date', __('financial_periods.validation.date_inside_existing'));
        }

        if (! $fromDateInsideExisting && ! $toDateInsideExisting) {
            $validator->errors()->add('from_date', __('financial_periods.validation.date_range_overlap'));
            $validator->errors()->add('to_date', __('financial_periods.validation.date_range_overlap'));
        }
    }

    private function financialPeriodRecord(): ?FinancialPeriod
    {
        $record = $this->route('financialPeriod');

        if ($record instanceof FinancialPeriod) {
            return (int) $record->company_id === $this->companyId() ? $record : null;
        }

        $docNum = trim((string) $record);

        if ($docNum === '') {
            return null;
        }

        return FinancialPeriod::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }
}
