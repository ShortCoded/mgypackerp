<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;

trait ValidatesProductComponentPayload
{
    /**
     * @return array<string, mixed>
     */
    protected function componentRules(): array
    {
        return [
            'component_product_doc_num' => ['required', 'string'],
            'unit_doc_num' => ['required', 'string'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:9999999999.99999999', 'regex:/^(?:\d+|\d*\.\d{1,8})$/'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareComponentForValidation(): void
    {
        foreach (['component_product_doc_num', 'unit_doc_num', 'quantity', 'notes'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    protected function validateComponentProduct(Validator $validator): void
    {
        if ($validator->errors()->has('component_product_doc_num')) {
            return;
        }

        $product = $this->productRecord();
        $componentProduct = $this->componentProductRecord();

        if (! $product instanceof Product || ! $componentProduct instanceof Product) {
            $validator->errors()->add('component_product_doc_num', __('products.components.not_available'));

            return;
        }

        if ((int) $product->getKey() === (int) $componentProduct->getKey()) {
            $validator->errors()->add('component_product_doc_num', __('products.components.self_reference'));

            return;
        }

        if (! $validator->errors()->has('unit_doc_num')
            && ! $this->componentUnitIsValidForProduct($componentProduct, $this->input('unit_doc_num'))
        ) {
            $validator->errors()->add('unit_doc_num', __('products.components.invalid_unit'));
        }

        $duplicateQuery = ProductComponent::query()
            ->where('product_id', $product->getKey())
            ->where('component_product_id', $componentProduct->getKey());

        $component = $this->componentRecord();

        if ($component instanceof ProductComponent) {
            $duplicateQuery->whereKeyNot($component->getKey());
        }

        if ($duplicateQuery->exists()) {
            $validator->errors()->add('component_product_doc_num', __('products.components.duplicate'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizedComponentData(array $data): array
    {
        $componentProduct = $this->componentProductRecord();
        $unit = $componentProduct instanceof Product
            ? $this->componentUnitRecord($componentProduct, $data['unit_doc_num'] ?? null)
            : null;

        $data['component_product_id'] = $componentProduct?->getKey();
        $data['unit_id'] = $unit?->getKey();
        $data['quantity'] = number_format((float) $data['quantity'], 8, '.', '');
        $data['notes'] = $this->blankToNull($data['notes'] ?? null);

        unset($data['component_product_doc_num'], $data['unit_doc_num']);

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'component_product_doc_num' => __('products.components.component_item'),
            'unit_doc_num' => __('products.components.unit'),
            'quantity' => __('products.components.quantity'),
            'notes' => __('products.components.notes'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'component_product_doc_num.required' => __('products.components.component_item_required'),
            'unit_doc_num.required' => __('products.components.unit_required'),
            'quantity.required' => __('products.components.quantity_required'),
            'quantity.numeric' => __('products.components.quantity_gt_zero'),
            'quantity.gt' => __('products.components.quantity_gt_zero'),
            'quantity.regex' => __('products.components.quantity_precision'),
        ];
    }

    protected function productRecord(): ?Product
    {
        $docNum = trim((string) $this->route('product'));

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->forCompany($this->companyId())
            ->withoutRawMaterials()
            ->where('doc_num', $docNum)
            ->first();
    }

    protected function componentRecord(): ?ProductComponent
    {
        $product = $this->productRecord();
        $publicId = trim((string) $this->route('component'));

        if (! $product instanceof Product || $publicId === '') {
            return null;
        }

        return ProductComponent::query()
            ->forCompany($this->companyId())
            ->where('product_id', $product->getKey())
            ->where('public_id', $publicId)
            ->first();
    }

    private function componentProductRecord(): ?Product
    {
        $docNum = trim((string) $this->input('component_product_doc_num'));

        if ($docNum === '') {
            return null;
        }

        return Product::query()
            ->forCompany($this->companyId())
            ->active()
            ->with(['unit', 'equivalentUnit'])
            ->rawMaterials()
            ->where('doc_num', $docNum)
            ->first();
    }

    private function componentUnitRecord(Product $componentProduct, mixed $unitDocNum): ?ItemUnit
    {
        return app(ProductComponentUnitOptionsService::class)
            ->unitForProduct($componentProduct, $unitDocNum, $this->companyId());
    }

    private function componentUnitIsValidForProduct(Product $componentProduct, mixed $unitDocNum): bool
    {
        return app(ProductComponentUnitOptionsService::class)
            ->unitIsValidForProduct($componentProduct, $unitDocNum, $this->companyId());
    }

    private function companyId(): int
    {
        return app(OperatingCompanyContextService::class)->requireCompanyId($this);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
