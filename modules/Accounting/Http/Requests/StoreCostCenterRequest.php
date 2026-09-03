<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Services\OperatingCompanyContextService;

class StoreCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'cost_centers.clone' : 'cost_centers.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cost_center_code' => trim((string) $this->input('cost_center_code')),
            'name' => trim((string) $this->input('name')),
            'name_en' => $this->filled('name_en') ? trim((string) $this->input('name_en')) : null,
            'parent_doc_num' => $this->filled('parent_doc_num') ? trim((string) $this->input('parent_doc_num')) : null,
            'linked_account_doc_nums' => $this->normalizedLinkedAccountDocNums(),
            'is_group' => $this->boolean('is_group'),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->companyId();

        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('cost_centers', 'doc_number')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
            'cost_center_code' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'parent_doc_num' => [
                'nullable',
                'string',
                Rule::exists('cost_centers', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->where('is_group', true)
                        ->whereNull('deleted_at')),
            ],
            'linked_account_doc_nums' => ['nullable', 'array'],
            'linked_account_doc_nums.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at')),
            ],
            'is_group' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('cost_center_code') && CostCenter::query()->forCompany($this->companyId())->where('cost_center_code', $this->input('cost_center_code'))->exists()) {
                $validator->errors()->add('cost_center_code', __('cost_centers.messages.code_used'));
            }

            $this->validateParent($validator);
        });
    }

    public function attributes(): array
    {
        return [
            'doc_number' => __('cost_centers.attributes.doc_number'),
            'cost_center_code' => __('cost_centers.attributes.cost_center_code'),
            'name' => __('cost_centers.attributes.name'),
            'name_en' => __('cost_centers.attributes.name_en'),
            'parent_doc_num' => __('cost_centers.attributes.parent'),
            'linked_account_doc_nums' => __('cost_centers.attributes.linked_accounts'),
            'linked_account_doc_nums.*' => __('cost_centers.attributes.linked_accounts'),
            'is_group' => __('cost_centers.attributes.is_group'),
            'status' => __('cost_centers.attributes.status'),
            'notes' => __('cost_centers.attributes.notes'),
        ];
    }

    protected function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function normalizedLinkedAccountDocNums(): mixed
    {
        $docNums = $this->input('linked_account_doc_nums', []);

        if (! is_array($docNums)) {
            return $docNums;
        }

        return collect($docNums)
            ->map(fn (mixed $docNum): mixed => is_string($docNum) ? trim($docNum) : $docNum)
            ->reject(fn (mixed $docNum): bool => $docNum === '')
            ->values()
            ->all();
    }

    private function validateParent(Validator $validator): void
    {
        if (! $this->filled('parent_doc_num')) {
            return;
        }

        $parent = CostCenter::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $this->input('parent_doc_num'))
            ->first();

        if (! $parent instanceof CostCenter) {
            return;
        }

        if ($parent->status !== 'active') {
            $validator->errors()->add('parent_doc_num', __('cost_centers.messages.parent_must_be_active'));
        }

        if (! $parent->is_group) {
            $validator->errors()->add('parent_doc_num', __('cost_centers.messages.parent_must_be_group'));
        }
    }
}
