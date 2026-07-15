<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\TaskBoard;

class UpdateTaskBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('task_boards.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'submit_action' => ['nullable', 'string', Rule::in(['save', 'save_view', 'save_edit', 'save_back', 'save_new'])],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'requires_password' => ['nullable', 'boolean'],
            'display_theme' => ['nullable', 'string', Rule::in(TaskBoard::DisplayThemes)],
            'access_code' => [
                Rule::requiredIf(fn (): bool => $this->requiresNewAccessCode()),
                'nullable',
                'string',
                'max:100',
            ],
            'clear_access_code' => ['nullable', 'boolean'],
            'user_doc_nums' => ['nullable', 'array'],
            'user_doc_nums.*' => [
                'string',
                Rule::exists('users', 'doc_num')
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'role_doc_nums' => ['nullable', 'array'],
            'role_doc_nums.*' => [
                'string',
                Rule::exists('roles', 'doc_num')
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'description', 'access_code'] as $field) {
            if ($this->has($field)) {
                $value = trim((string) $this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }

        if ($this->has('display_theme')) {
            $this->merge([
                'display_theme' => trim((string) $this->input('display_theme')),
            ]);
        }

        foreach (['is_active'] as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }

        foreach (['is_public', 'requires_password', 'clear_access_code'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }

        $this->merge([
            'user_doc_nums' => $this->normalizedDocNums('user_doc_nums'),
            'role_doc_nums' => $this->normalizedDocNums('role_doc_nums'),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('task_boards.attributes.name'),
            'description' => __('task_boards.attributes.description'),
            'is_active' => __('task_boards.attributes.is_active'),
            'is_public' => __('task_boards.attributes.is_public'),
            'requires_password' => __('task_boards.attributes.requires_password'),
            'display_theme' => __('task_boards.attributes.display_theme'),
            'access_code' => __('task_boards.attributes.access_code'),
            'user_doc_nums' => __('task_boards.attributes.users'),
            'role_doc_nums' => __('task_boards.attributes.user_groups'),
        ];
    }

    private function requiresNewAccessCode(): bool
    {
        if (! $this->boolean('requires_password')) {
            return false;
        }

        if ($this->boolean('clear_access_code')) {
            return false;
        }

        if ($this->filled('access_code')) {
            return false;
        }

        $docNum = $this->route('taskBoard');
        $docNum = $docNum instanceof TaskBoard ? $docNum->doc_num : trim((string) $docNum);

        if ($docNum === '') {
            return true;
        }

        return ! TaskBoard::query()
            ->where('doc_num', $docNum)
            ->whereNotNull('public_password_hash')
            ->exists();
    }

    /**
     * @return list<string>
     */
    private function normalizedDocNums(string $key): array
    {
        $values = $this->input($key, []);

        if (! is_array($values)) {
            return [];
        }

        return collect($values)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }
}
