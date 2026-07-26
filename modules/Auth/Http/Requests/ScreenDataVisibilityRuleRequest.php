<?php

namespace Modules\Auth\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Auth\Enums\ScreenDataVisibilityDurationUnit;
use Modules\Auth\Enums\ScreenDataVisibilityRecordScope;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ScreenDataVisibilityRegistry;

class ScreenDataVisibilityRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('screenDataVisibilityRule') instanceof ScreenDataVisibilityRule
            ? 'screen_data_visibility_rules.edit'
            : ($this->filled('clone_source_token') ? 'screen_data_visibility_rules.clone' : 'screen_data_visibility_rules.create');

        return (bool) $this->user()?->can($permission);
    }

    protected function prepareForValidation(): void
    {
        $durationValue = $this->input('duration_value');

        $this->merge([
            'user_doc_num' => trim((string) $this->input('user_doc_num')),
            'screen_key' => trim((string) $this->input('screen_key')),
            'record_scope' => trim((string) $this->input('record_scope', ScreenDataVisibilityRecordScope::OwnRecords->value)),
            'max_visible_records' => $this->nullableInteger('max_visible_records'),
            'duration_value' => $this->nullableInteger('duration_value'),
            'duration_unit' => $durationValue === null || $durationValue === '' ? null : trim((string) $this->input('duration_unit')),
            'is_active' => $this->boolean('is_active'),
            'notes' => $this->nullableString('notes'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_doc_num' => ['required', 'string', Rule::exists('users', 'doc_num')->whereNull('deleted_at')],
            'screen_key' => ['required', 'string', 'max:160'],
            'record_scope' => ['required', Rule::enum(ScreenDataVisibilityRecordScope::class)],
            'max_visible_records' => ['nullable', 'integer', 'min:1'],
            'duration_value' => ['nullable', 'integer', 'min:1'],
            'duration_unit' => ['nullable', 'required_with:duration_value', Rule::enum(ScreenDataVisibilityDurationUnit::class)],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string'],
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new', 'save_clone'])],
            'clone_source_token' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $registry = app(ScreenDataVisibilityRegistry::class);
            $screenKey = (string) $this->input('screen_key');

            if (! $registry->isSupported($screenKey)) {
                $validator->errors()->add('screen_key', __('screen_data_visibility_rules.validation.unsupported_screen'));

                return;
            }

            if (! $this->boolean('is_active')) {
                return;
            }

            $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
            $user = User::withTrashed()->where('doc_num', $this->input('user_doc_num'))->first();
            if ($companyId === null || ! $user) {
                return;
            }

            $current = $this->route('screenDataVisibilityRule');
            $duplicate = ScreenDataVisibilityRule::query()
                ->forCompany($companyId)
                ->where('user_id', $user->getKey())
                ->where('screen_key', $screenKey)
                ->where('is_active', true)
                ->when($current instanceof ScreenDataVisibilityRule, fn ($query) => $query->whereKeyNot($current->getKey()))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('screen_key', __('screen_data_visibility_rules.validation.active_rule_exists'));
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return __('screen_data_visibility_rules.attributes');
    }

    private function nullableInteger(string $field): ?int
    {
        $value = $this->input($field);

        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(string $field): ?string
    {
        $value = trim((string) $this->input($field));

        return $value === '' ? null : $value;
    }
}
