<?php

namespace Modules\Auth\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;
use Modules\Auth\Models\Role;
use Modules\Core\Services\DocumentNumberService;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('users.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $targetUser = $this->route('user');

        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            '_roles_present' => ['nullable', 'boolean'],
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($targetUser?->id)->withoutTrashed(),
            ],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($targetUser?->id)->withoutTrashed(),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'phone')->ignore($targetUser?->id)->withoutTrashed(),
            ],
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'blocked'])],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'notes' => ['nullable', 'string'],
        ];

        if ($this->canControlDocumentNumber()) {
            $rules['doc_number'] = [
                'nullable',
                'regex:/^\d+$/',
                Rule::unique('users', 'doc_number')->ignore($targetUser?->id)->withoutTrashed(),
            ];
        }

        if ($this->canManageRoles()) {
            $rules['roles'] = ['nullable', 'array'];
            $rules['roles.*'] = ['required', 'string', 'distinct'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $targetUser = $this->route('user');

            if (! $this->canControlDocumentNumber()
                || ! $targetUser instanceof User
                || $validator->errors()->has('doc_number')
                || ! $this->hasFilledDocumentNumber()
            ) {
                return;
            }

            $docNumber = (int) $this->input('doc_number');
            $docNum = app(DocumentNumberService::class)->format('users', $docNumber);

            if (User::query()->where('doc_num', $docNum)->whereKeyNot($targetUser->getKey())->exists()) {
                $validator->errors()->add('doc_number', __('users.validation.doc_number_unique'));
            }
        });

        $validator->after(function (Validator $validator): void {
            if (! $this->canManageRoles() || $validator->errors()->has('roles')) {
                return;
            }

            $roleDocNums = $this->roleDocNums();

            if ($roleDocNums === []) {
                return;
            }

            $existingCount = Role::query()
                ->whereIn('doc_num', $roleDocNums)
                ->count();

            if ($existingCount !== count($roleDocNums)) {
                $validator->errors()->add('roles', __('users.validation.roles_invalid'));
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

        if (! $this->canManageRoles()) {
            unset($data['roles']);
        } elseif (array_key_exists('roles', $data) || $this->boolean('_roles_present')) {
            $data['roles'] = $this->roleDocNums();
        }

        unset($data['_roles_present']);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_number' => __('common.fields.document_number'),
            'name' => __('common.fields.name'),
            'username' => __('common.fields.username'),
            'email' => __('common.fields.email'),
            'phone' => __('common.fields.phone'),
            'status' => __('common.fields.status'),
            'password' => __('users.password'),
            'notes' => __('common.fields.notes'),
            'roles' => __('common.fields.roles'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc_number.regex' => __('users.validation.doc_number_numeric'),
            'doc_number.unique' => __('users.validation.doc_number_unique'),
            'username.unique' => __('users.validation.username_unique'),
            'email.unique' => __('users.validation.email_unique'),
            'phone.unique' => __('users.validation.phone_unique'),
            'roles.array' => __('users.validation.roles_invalid'),
            'roles.*.distinct' => __('users.validation.roles_invalid'),
        ];
    }

    private function canControlDocumentNumber(): bool
    {
        return (bool) $this->user()?->can('users.document_number.control');
    }

    private function hasFilledDocumentNumber(): bool
    {
        $value = $this->input('doc_number');

        return $value !== null && $value !== '';
    }

    private function canManageRoles(): bool
    {
        return (bool) $this->user()?->can('users.roles.manage');
    }

    /**
     * @return list<string>
     */
    private function roleDocNums(): array
    {
        return collect($this->input('roles', []))
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->unique()
            ->values()
            ->all();
    }
}
