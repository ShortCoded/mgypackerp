<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Http\Requests\Concerns\ValidatesProductComponentPayload;

class UpdateProductComponentRequest extends FormRequest
{
    use ValidatesProductComponentPayload;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('products.edit')
            && $this->productRecord() !== null
            && $this->componentRecord() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->componentRules();
    }

    protected function prepareForValidation(): void
    {
        $this->prepareComponentForValidation();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateComponentProduct($validator);
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

        return $this->normalizedComponentData($data);
    }
}
