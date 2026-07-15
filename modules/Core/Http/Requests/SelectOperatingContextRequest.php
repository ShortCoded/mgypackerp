<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SelectOperatingContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'company_doc_num' => ['required', 'string', 'max:255'],
            'branch_doc_num' => ['required', 'string', 'max:255'],
            'financial_period_doc_num' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'company_doc_num' => __('operating_context.current_company'),
            'branch_doc_num' => __('operating_context.current_branch'),
            'financial_period_doc_num' => __('operating_context.current_financial_period'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_doc_num.required' => __('operating_context.validation.company_required'),
            'branch_doc_num.required' => __('operating_context.validation.branch_required'),
            'financial_period_doc_num.required' => __('operating_context.validation.financial_period_required'),
        ];
    }
}
