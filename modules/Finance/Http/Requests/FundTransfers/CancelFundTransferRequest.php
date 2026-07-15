<?php

namespace Modules\Finance\Http\Requests\FundTransfers;

use Illuminate\Foundation\Http\FormRequest;

class CancelFundTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fund_transfers.cancel');
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
            'cancel_reason' => __('fund_transfers.attributes.cancel_reason'),
        ];
    }
}
