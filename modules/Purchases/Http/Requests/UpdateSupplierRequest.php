<?php

namespace Modules\Purchases\Http\Requests;

use Modules\Purchases\Models\Supplier;

class UpdateSupplierRequest extends StoreSupplierRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('suppliers.edit');
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['clone_source_token']);

        return $rules;
    }

    protected function currentSupplier(): ?Supplier
    {
        $supplier = $this->route('supplier');

        return $supplier instanceof Supplier ? $supplier : null;
    }
}
