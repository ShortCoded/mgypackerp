<?php

namespace Modules\Finance\Http\Requests\Cheques;

use Modules\Finance\Models\Cheque;

class UpdateChequeRequest extends StoreChequeRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cheques.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentRecord(): ?Cheque
    {
        $docNum = $this->route('cheque');

        if (! is_string($docNum) || $docNum === '') {
            return null;
        }

        return Cheque::query()
            ->where('company_id', $this->input('company_id'))
            ->where('doc_num', $docNum)
            ->first();
    }
}
