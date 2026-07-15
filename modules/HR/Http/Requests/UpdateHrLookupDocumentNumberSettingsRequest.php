<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Services\HrLookupDefinition;
use Modules\HR\Services\HrLookupRegistry;

class UpdateHrLookupDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->definition()->permission('document_number_settings.update'));
    }

    /**
     * @return array<string, list<string>>
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
            'prefix' => __('common.document_number_settings.prefix'),
            'padding' => __('common.document_number_settings.padding'),
        ];
    }

    private function definition(): HrLookupDefinition
    {
        return app(HrLookupRegistry::class)->fromRouteName($this->route()?->getName());
    }
}
