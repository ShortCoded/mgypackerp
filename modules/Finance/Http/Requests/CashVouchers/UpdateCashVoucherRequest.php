<?php

namespace Modules\Finance\Http\Requests\CashVouchers;

use Modules\Finance\Models\CashVoucher;

class UpdateCashVoucherRequest extends StoreCashVoucherRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentRecord(): ?CashVoucher
    {
        $docNum = $this->route('cashVoucher');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return CashVoucher::query()
            ->where('company_id', $this->input('company_id'))
            ->where('voucher_type', $this->voucherType())
            ->where('doc_num', $docNum)
            ->first();
    }
}
