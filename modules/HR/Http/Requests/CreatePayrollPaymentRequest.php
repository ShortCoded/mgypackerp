<?php

namespace Modules\HR\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\OperatingCompanyContextService;

class CreatePayrollPaymentRequest extends FormRequest
{
    use NormalizesNumericInput;

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
            'payslip_id' => [
                'required',
                'integer',
                Rule::exists('hr_payslips', 'id')->where('company_id', $companyId),
            ],
            'cashbox_doc_num' => [
                'required',
                'string',
                Rule::exists('cashboxes', 'doc_num')->where('company_id', $companyId)->where('status', 'active')->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.9999', 'regex:/^(?:\d{1,14}|\d{0,14}\.\d{1,4})$/D'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'idempotency_key' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['amount']);

        if (! $this->filled('payslip_id')) {
            $payrollRunId = (int) $this->route('payrollRun');
            $payslipIds = DB::table('hr_payslips')->where('payroll_run_id', $payrollRunId)->limit(2)->pluck('id');
            if ($payslipIds->count() === 1) {
                $this->merge(['payslip_id' => $payslipIds->first()]);
            }
        }
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'payslip_id' => __('hr_payroll.labels.employee'),
            'cashbox_doc_num' => __('hr_payroll.labels.cashbox'),
            'amount' => __('hr_payroll.labels.amount'),
            'payment_date' => __('hr_payroll.labels.payment_date'),
            'reference' => __('hr_payroll.labels.reference'),
        ];
    }
}
