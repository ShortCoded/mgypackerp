<?php

namespace Modules\Finance\Http\Requests\FundTransfers;

use Modules\Finance\Models\FundTransfer;

class UpdateFundTransferRequest extends StoreFundTransferRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('fund_transfers.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentRecord(): ?FundTransfer
    {
        $docNum = $this->route('fundTransfer');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return FundTransfer::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $docNum)
            ->first();
    }
}
