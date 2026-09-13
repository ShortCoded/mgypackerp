<?php

namespace Modules\Sales\Http\Requests;

class UpdatePriceListRequest extends StorePriceListRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('price_lists.edit');
    }
}
