<?php

namespace Modules\HR\Http\Requests\Foundation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;

class BulkDeleteHrFoundationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->definition()->permission('delete'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'string',
                Rule::exists($this->definition()->table, 'doc_num')
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'doc_nums' => __('hr.selected_records'),
            'doc_nums.*' => __('hr.selected_records'),
        ];
    }

    private function definition(): HrFoundationDefinition
    {
        return app(HrFoundationRegistry::class)->fromRouteName($this->route()?->getName());
    }
}
