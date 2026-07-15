<?php

namespace Modules\Finance\Http\Requests\Cashboxes;

use Modules\Finance\Models\Cashbox;

class UpdateCashboxRequest extends StoreCashboxRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('cashboxes.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentCashbox(): ?Cashbox
    {
        $cashbox = $this->route('cashbox');

        return $cashbox instanceof Cashbox ? $cashbox : null;
    }
}
