<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\ValidatesProductPayload;

class UpdateProductRequest extends FormRequest
{
    use ValidatesProductPayload;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.edit')
            && $this->productRecordMatchesContext();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = $this->productRules();
        unset($rules['clone_source_token']);

        return $rules;
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
