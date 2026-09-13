<?php

namespace Modules\Maintenance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;

class StoreMaintenanceExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('maintenance.expenses.create');
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
            'currency_id' => ['required', 'integer'],
            'payment_channel' => ['required', Rule::in(['cashbox', 'bank'])],
            'cashbox_id' => ['nullable', 'integer', 'required_if:payment_channel,cashbox'],
            'bank_account_id' => ['nullable', 'integer', 'required_if:payment_channel,bank'],
            'expense_account_id' => ['nullable', 'integer', 'required_if:payment_channel,cashbox'],
            'reason' => ['required', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
