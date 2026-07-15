<?php

namespace Modules\Auth\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('profile.edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $userId = $user instanceof User ? $user->getKey() : null;

        $rules = [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view'])],
            'roles' => ['prohibited'],
            'roles.*' => ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'username')->ignore($userId)->withoutTrashed(),
            ],
            'email' => [
                'nullable',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId)->withoutTrashed(),
            ],
            'phone' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('users', 'phone')->ignore($userId)->withoutTrashed(),
            ],
        ];

        if (Schema::hasColumn('users', 'notes')) {
            $rules['notes'] = ['nullable', 'string'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('common.fields.name'),
            'username' => __('common.fields.username'),
            'email' => __('common.fields.email'),
            'phone' => __('common.fields.phone'),
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
            'username.unique' => __('profile.validation.username_unique'),
            'email.unique' => __('profile.validation.email_unique'),
            'phone.unique' => __('profile.validation.phone_unique'),
        ];
    }
}
