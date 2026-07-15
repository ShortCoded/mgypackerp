<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Auth\Http\Requests\Concerns\ValidatesRoleOperatingScope;
use Modules\Auth\Models\Role;
use Modules\Auth\Services\RoleService;
use Modules\Core\Services\DocumentNumberService;

class UpdateRoleRequest extends FormRequest
{
    use ValidatesRoleOperatingScope;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('roles.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $role = $this->route('role');

        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique(config('permission.table_names.roles', 'roles'), 'name')
                    ->where('guard_name', 'web')
                    ->ignore($role?->id)
                    ->withoutTrashed(),
            ],
            'notes' => ['nullable', 'string'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'string',
                Rule::exists(config('permission.table_names.permissions', 'permissions'), 'name')
                    ->where('guard_name', 'web'),
            ],
        ];

        if ($this->canManageOperatingScope()) {
            $rules['accessible_company_doc_nums'] = ['nullable', 'array'];
            $rules['accessible_company_doc_nums.*'] = [
                'string',
                'distinct',
                Rule::exists('companies', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ];
            $rules['accessible_branch_doc_nums'] = ['nullable', 'array'];
            $rules['accessible_branch_doc_nums.*'] = [
                'string',
                'distinct',
                Rule::exists('branches', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ];
            $rules['accessible_financial_period_doc_nums'] = ['nullable', 'array'];
            $rules['accessible_financial_period_doc_nums.*'] = [
                'string',
                'distinct',
                Rule::exists('financial_periods', 'doc_num')
                    ->whereNull('deleted_at'),
            ];
        }

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique(config('permission.table_names.roles', 'roles'), 'doc_number')
                    ->ignore($role?->id)
                    ->withoutTrashed(),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $role = $this->route('role');

            if ($role instanceof Role && app(RoleService::class)->isProtectedRole($role)) {
                $validator->errors()->add('role', __('roles.messages.protected_update_blocked'));

                return;
            }

            $this->validateOperatingScope($validator);

            if (! $this->canControlDocumentNumber()
                || ! $role instanceof Role
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format('roles', $docNumber);

            if (Role::query()->where('doc_num', $docNum)->whereKeyNot($role->getKey())->exists()) {
                $validator->errors()->add('doc_number', __('roles.validation.doc_number_unique'));
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
        } elseif (! array_key_exists('doc_number', $data) || $data['doc_number'] === null || $data['doc_number'] === '') {
            unset($data['doc_number']);
        } else {
            $data['doc_number'] = (int) $data['doc_number'];
        }

        if ($this->canManageOperatingScope()) {
            $data['accessible_company_doc_nums'] = $this->normalizeCompanyDocNums($data['accessible_company_doc_nums'] ?? []);
            $data['accessible_branch_doc_nums'] = $this->normalizeDocNums($data['accessible_branch_doc_nums'] ?? []);
            $data['accessible_financial_period_doc_nums'] = $this->normalizeDocNums($data['accessible_financial_period_doc_nums'] ?? []);
        } else {
            unset($data['accessible_company_doc_nums']);
            unset($data['accessible_branch_doc_nums']);
            unset($data['accessible_financial_period_doc_nums']);
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('auth.roles.name'),
            'doc_number' => __('common.fields.doc_number'),
            'notes' => __('common.fields.notes'),
            'permissions' => __('auth.roles.permissions'),
            'permissions.*' => __('auth.roles.permissions'),
            'accessible_company_doc_nums' => __('roles.operating_scope.companies'),
            'accessible_company_doc_nums.*' => __('roles.operating_scope.companies'),
            'accessible_branch_doc_nums' => __('roles.operating_scope.branches'),
            'accessible_branch_doc_nums.*' => __('roles.operating_scope.branches'),
            'accessible_financial_period_doc_nums' => __('roles.operating_scope.financial_periods'),
            'accessible_financial_period_doc_nums.*' => __('roles.operating_scope.financial_periods'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => __('roles.validation.name_unique'),
            'doc_number.regex' => __('roles.validation.doc_number_numeric'),
            'doc_number.unique' => __('roles.validation.doc_number_unique'),
        ];
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('roles.document_number.control');
    }

    private function canManageOperatingScope(): bool
    {
        return (bool) $this->user()?->can('roles.operating_scope.manage')
            || (bool) $this->user()?->can('roles.company_access.manage');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    /**
     * @return list<string>
     */
    private function normalizeCompanyDocNums(mixed $docNums): array
    {
        return $this->normalizeDocNums($docNums);
    }

    /**
     * @return list<string>
     */
    private function normalizeDocNums(mixed $docNums): array
    {
        return collect(is_array($docNums) ? $docNums : [])
            ->filter(fn (mixed $docNum): bool => is_string($docNum) && trim($docNum) !== '')
            ->map(fn (string $docNum): string => trim($docNum))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
