<?php

namespace Modules\Finance\Http\Requests\CashVouchers;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Finance\Models\CashVoucher;

class CancelCashVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.cancel');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cancel_reason' => $this->filled('cancel_reason') ? trim((string) $this->input('cancel_reason')) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'cancel_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'cancel_reason' => __($this->translationKey().'.attributes.cancel_reason'),
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

    private function translationKey(): string
    {
        return CashVoucher::translationKeyForType($this->voucherType());
    }
}
