<?php

namespace Modules\Sales\Http\Requests;

use Modules\Sales\Models\Quotation;

class UpdateQuotationRequest extends StoreQuotationRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('quotations.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentQuotation(): ?Quotation
    {
        $quotation = $this->route('quotation');

        return $quotation instanceof Quotation ? $quotation : null;
    }
}
