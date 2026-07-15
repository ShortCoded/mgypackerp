<?php

namespace Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Production\Models\ProductionIdentifier;

class StoreProductionIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->filled('clone_source_token') ? 'production.identifiers.clone' : 'production.identifiers.create');
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
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateParent($validator);
        });
    }

    public function attributes(): array
    {
        return [
            'name' => __('production_identifiers.attributes.name'),
            'parent_doc_num' => __('production_identifiers.attributes.parent'),
            'is_group' => __('production_identifiers.attributes.is_group'),
            'status' => __('production_identifiers.attributes.status'),
            'notes' => __('production_identifiers.attributes.notes'),
        ];
    }

    protected function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function validateParent(Validator $validator): void
    {
        if (! $this->filled('parent_doc_num')) {
            return;
        }

        $parent = ProductionIdentifier::query()
            ->forCompany($this->companyId())
            ->where('doc_num', $this->input('parent_doc_num'))
            ->first();

        if (! $parent instanceof ProductionIdentifier) {
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
