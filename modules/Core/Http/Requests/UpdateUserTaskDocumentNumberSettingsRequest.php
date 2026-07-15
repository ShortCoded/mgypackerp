<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserTaskDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('tasks.document_number_settings.update');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'prefix' => ['nullable', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'prefix' => __('user_tasks.document_number_settings.prefix'),
            'padding' => __('user_tasks.document_number_settings.padding'),
        ];
    }
}
