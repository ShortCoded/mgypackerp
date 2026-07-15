<?php

namespace Modules\Finance\Http\Requests\FundTransfers;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteFundTransfersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fund_transfers.delete');
    }

    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => ['required', 'string'],
        ];
    }
}
