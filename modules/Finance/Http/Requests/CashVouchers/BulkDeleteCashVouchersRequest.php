<?php

namespace Modules\Finance\Http\Requests\CashVouchers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Services\OperatingContextService;
use Modules\Finance\Models\CashVoucher;

class BulkDeleteCashVouchersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.delete');
    }

    public function rules(): array
    {
        $companyId = app(OperatingContextService::class)->snapshot($this)['company_id'];

        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'required',
                'string',
                Rule::exists('cash_vouchers', 'doc_num')
                    ->where(fn ($query) => $query
                        ->where('company_id', $companyId)
                        ->where('voucher_type', $this->voucherType())
                        ->whereNull('deleted_at')),
            ],
        ];
    }

    private function voucherType(): string
    {
        $routeName = (string) $this->route()?->getName();

        return str_contains($routeName, 'cash-payment-vouchers')
            ? CashVoucher::TypePayment
            : CashVoucher::TypeReceipt;
    }

    private function permissionPrefix(): string
    {
        return CashVoucher::permissionPrefixForType($this->voucherType());
    }
}
