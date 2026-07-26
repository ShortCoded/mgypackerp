<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class BulkDeleteScreenDataVisibilityRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('screen_data_visibility_rules.delete');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();

        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'string',
                'distinct',
                Rule::exists('screen_data_visibility_rules', 'doc_num')
                    ->where(fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at')),
            ],
        ];
    }
}
