<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\ValidatesProductPayload;

class StoreProductRequest extends FormRequest
{
    use ValidatesProductPayload;

    public function authorize(): bool
    {
        if ($this->filled('clone_source_token')) {
            return (bool) $this->user()?->can($this->permissionPrefix().'.clone');
        }

        return (bool) $this->user()?->can($this->permissionPrefix().'.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->productRules();
    }

    protected function prepareForValidation(): void
    {
        $this->prepareProductForValidation();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateProductClassificationContext($validator);
            $this->validateProductUnitEquivalence($validator);
            $this->validateProductImageSelection($validator);
            $this->validateProductDocumentNumber($validator);
            $this->validateProductComponents($validator);
            $this->validateRelatedFinishedProducts($validator);
        });
    }

    /**
     * @param  string|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $data = parent::validated($key, $default);

        if ($key !== null || ! is_array($data)) {
            return $data;
        }

        return $this->normalizedProductData($data);
    }
}
