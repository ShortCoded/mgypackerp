<?php

namespace Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\Services\OperatingCompanyContextService;

class UpdateCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cost_centers.edit');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cost_center_code' => trim((string) $this->input('cost_center_code')),
            'name' => trim((string) $this->input('name')),
            'name_en' => $this->filled('name_en') ? trim((string) $this->input('name_en')) : null,
            'parent_doc_num' => $this->filled('parent_doc_num') ? trim((string) $this->input('parent_doc_num')) : null,
            'default_account_doc_num' => $this->filled('default_account_doc_num') ? trim((string) $this->input('default_account_doc_num')) : null,
            'is_group' => $this->boolean('is_group'),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->companyId();
        $costCenter = $this->currentCostCenter();

        return [
            'doc_number' => [
                'nullable',
                'integer',
                'min:1',
                Rule::unique('cost_centers', 'doc_number')
                    ->ignore($costCenter?->getKey())
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
            'default_account_doc_num' => [
                'nullable',
                'string',
                Rule::exists('accounts', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where(function ($query) use ($costCenter): void {
                            $query->where(function ($query): void {
                                Account::applyDirectPostingEligibility($query);
                            });

                            if ($costCenter?->default_account_id !== null) {
                                $query->orWhere('id', $costCenter->default_account_id);
                            }
                        })),
            ],
            'is_group' => ['boolean'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $companyId = $this->companyId();
            $costCenter = $this->currentCostCenter();

            if ($this->filled('cost_center_code') && CostCenter::query()->forCompany($companyId)->where('cost_center_code', $this->input('cost_center_code'))->whereKeyNot($costCenter?->getKey())->exists()) {
                $validator->errors()->add('cost_center_code', __('cost_centers.messages.code_used'));
            }

            if ($costCenter instanceof CostCenter && $this->input('parent_doc_num') === $costCenter->doc_num) {
                $validator->errors()->add('parent_doc_num', __('cost_centers.messages.self_parent'));
            }

            $parent = $this->filled('parent_doc_num')
                ? CostCenter::query()->forCompany($companyId)->where('doc_num', $this->input('parent_doc_num'))->first()
                : null;

            if ($costCenter instanceof CostCenter && $parent instanceof CostCenter && $this->wouldCreateCycle($costCenter, $parent)) {
                $validator->errors()->add('parent_doc_num', __('cost_centers.messages.parent_cycle'));
            }

            $this->validateParent($validator, $parent);
        });
    }

    public function attributes(): array
    {
        return (new StoreCostCenterRequest)->attributes();
    }

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function currentCostCenter(): ?CostCenter
    {
        $docNum = $this->route('costCenter');

        if (! is_string($docNum) || trim($docNum) === '') {
            return null;
        }

        return CostCenter::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }

    private function wouldCreateCycle(CostCenter $costCenter, CostCenter $parent): bool
    {
        $parentId = $parent->getKey();

        while ($parentId !== null) {
            if ((int) $parentId === (int) $costCenter->getKey()) {
                return true;
            }

            $parentId = CostCenter::query()
                ->forCompany((int) $costCenter->company_id)
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }

    private function validateParent(Validator $validator, ?CostCenter $parent): void
    {
        if (! $this->filled('parent_doc_num') || ! $parent instanceof CostCenter) {
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
