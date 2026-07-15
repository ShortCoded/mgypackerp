<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionIdentifier;

class UpdateProductionIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('production.identifiers.edit')
            && $this->currentIdentifier() instanceof ProductionIdentifier;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'parent_doc_num' => $this->filled('parent_doc_num') ? trim((string) $this->input('parent_doc_num')) : null,
            'is_group' => $this->boolean('is_group'),
        ]);
    }

    public function rules(): array
    {
        $companyId = $this->companyId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'parent_doc_num' => [
                'nullable',
                'string',
                Rule::exists('production_identifiers', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->where('is_group', true)
                        ->whereNull('deleted_at')),
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
            $identifier = $this->currentIdentifier();

            if ($identifier instanceof ProductionIdentifier && $this->input('parent_doc_num') === $identifier->doc_num) {
                $validator->errors()->add('parent_doc_num', __('production_identifiers.messages.self_parent'));
            }

            $parent = $this->filled('parent_doc_num')
                ? ProductionIdentifier::query()->forCompany($companyId)->where('doc_num', $this->input('parent_doc_num'))->first()
                : null;

            if ($identifier instanceof ProductionIdentifier && $parent instanceof ProductionIdentifier && $this->wouldCreateCycle($identifier, $parent)) {
                $validator->errors()->add('parent_doc_num', __('production_identifiers.messages.parent_cycle'));
            }

            $this->validateParent($validator, $parent);
        });
    }

    public function attributes(): array
    {
        return (new StoreProductionIdentifierRequest)->attributes();
    }

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function currentIdentifier(): ?ProductionIdentifier
    {
        $docNum = $this->route('identifier');

        if (! is_string($docNum) || trim($docNum) === '') {
            return null;
        }

        return ProductionIdentifier::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $docNum)
            ->first();
    }

    private function wouldCreateCycle(ProductionIdentifier $identifier, ProductionIdentifier $parent): bool
    {
        $parentId = $parent->getKey();

        while ($parentId !== null) {
            if ((int) $parentId === (int) $identifier->getKey()) {
                return true;
            }

            $parentId = ProductionIdentifier::query()
                ->forCompany((int) $identifier->company_id)
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return false;
    }

    private function validateParent(Validator $validator, ?ProductionIdentifier $parent): void
    {
        if (! $this->filled('parent_doc_num') || ! $parent instanceof ProductionIdentifier) {
            return;
        }

        if ($parent->status !== 'active') {
            $validator->errors()->add('parent_doc_num', __('production_identifiers.messages.parent_must_be_active'));
        }

        if (! $parent->is_group) {
            $validator->errors()->add('parent_doc_num', __('production_identifiers.messages.parent_must_be_group'));
        }
    }
}
