<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingCompanyContextService;

class CreatePayrollPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('hr.payroll_payment.create')
            && (bool) $this->user()?->can('cash_payment_vouchers.create');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId($this);

        return [
            'cashbox_doc_num' => [
                'required',
                'string',
                Rule::exists('cashboxes', 'doc_num')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
