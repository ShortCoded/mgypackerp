<?php

namespace Modules\Sales\Http\Requests;

use Modules\Sales\Models\Customer;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('customers.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentCustomer(): ?Customer
    {
        $customer = $this->route('customer');

        return $customer instanceof Customer ? $customer : null;
    }
}
