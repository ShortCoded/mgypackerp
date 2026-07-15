<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectStructureDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('project_structures.document_number_settings.update');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:20'],
            'padding' => ['required', 'integer', 'min:0', 'max:10'],
        ];
    }
}
