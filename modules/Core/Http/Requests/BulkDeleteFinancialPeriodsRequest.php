<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class BulkDeleteFinancialPeriodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('financial_periods.delete');
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
                Rule::exists('financial_periods', 'doc_num')
                    ->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId($this))
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
            'doc_nums' => __('financial_periods.selected'),
            'doc_nums.*' => __('financial_periods.selected'),
        ];
    }
}
