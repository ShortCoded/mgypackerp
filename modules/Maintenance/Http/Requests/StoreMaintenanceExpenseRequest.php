<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Requests\Concerns\NormalizesNumericInput;
use Modules\Core\Services\OperatingContextService;

class StoreMaintenanceExpenseRequest extends FormRequest
{
    use NormalizesNumericInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeNumericInput(['amount', 'exchange_rate']);
    }

    public function authorize(): bool
    {
        $permission = $this->isMethod('PUT') || $this->isMethod('PATCH')
            ? 'maintenance.expenses.edit'
            : 'maintenance.expenses.create';

        return (bool) $this->user()?->can($permission);
    }

    public function rules(): array
    {
        $context = app(OperatingContextService::class)->snapshot($this);

        return [
            'maintenance_work_order_id' => ['required', 'integer', Rule::exists('maintenance_work_orders', 'id')->where(fn ($query) => $query
                ->where('company_id', $context['company_id'])
                ->where('financial_period_id', $context['financial_period_id'])
                ->where('branch_id', $context['branch_id'])
                ->whereNull('deleted_at'))],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where(fn ($query) => $query->where('company_id', $context['company_id'])->where('status', 'active')->whereNull('deleted_at'))],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'decimal:0,6'],
            'payment_channel' => ['required', Rule::in(['cashbox', 'bank'])],
            'cashbox_id' => ['nullable', 'integer', 'required_if:payment_channel,cashbox', Rule::exists('cashboxes', 'id')->where(fn ($query) => $query->where('company_id', $context['company_id'])->where('branch_id', $context['branch_id'])->where('status', 'active')->whereNull('deleted_at'))],
            'bank_account_id' => ['nullable', 'integer', 'required_if:payment_channel,bank', Rule::exists('bank_accounts', 'id')->where(fn ($query) => $query->where('company_id', $context['company_id'])->where('status', 'active')->whereNull('deleted_at'))],
            'expense_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where(fn ($query) => $query->where('company_id', $context['company_id'])->where('account_type', 'expense')->where('is_postable', true)->where('status', 'active')->whereNull('deleted_at'))],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
