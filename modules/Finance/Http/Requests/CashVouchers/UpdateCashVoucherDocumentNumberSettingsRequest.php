<?php

namespace Modules\Finance\Http\Requests\CashVouchers;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Finance\Models\CashVoucher;

class UpdateCashVoucherDocumentNumberSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.document_number_settings.update');
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:30'],
            'padding' => ['required', 'integer', 'min:1', 'max:10'],
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
