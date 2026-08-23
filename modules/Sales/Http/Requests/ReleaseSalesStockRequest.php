<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseSalesStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('sales_orders.reserve');
    }

    public function rules(): array
    {
        return [
            'reservation_public_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
