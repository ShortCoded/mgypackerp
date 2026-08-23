<?php

namespace Modules\Core\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Core\Models\ItemUnit;
use Modules\Core\Models\Product;
use Modules\Core\Models\ProductComponent;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ProductComponentUnitOptionsService;

trait ValidatesProductComponentPayload
{
    use NormalizesNumericInput;

    /**
     * @return array<string, mixed>
     */
    protected function componentRules(): array
    {
        $calculationMethod = $this->componentStringValue(
            $this->input('calculation_method', ProductComponent::CalculationDirect),
        )
            ?: ProductComponent::CalculationDirect;
        $inputSource = $this->componentAuthoritativeInputSource(
            $calculationMethod,
            $this->input('input_source'),
            $this->input('quantity'),
            $this->input('percentage'),
        );
        $quantityRules = $inputSource === ProductComponent::InputWeight
            ? $this->componentValueRules($calculationMethod)
            : ['exclude'];
        $percentageRules = $calculationMethod === ProductComponent::CalculationPercentage
            && $inputSource === ProductComponent::InputPercentage
                ? ['bail', 'required', 'numeric', 'gt:0', 'regex:/^(?:\d{1,10}|\d{0,10}\.\d{1,8})$/']
                : ['exclude'];

        return [
            'component_product_doc_num' => ['required', 'string'],
            'unit_doc_num' => ['nullable', 'string'],
            'calculation_method' => ['required', 'string', Rule::in(ProductComponent::calculationMethods())],
            'quantity' => $quantityRules,
            'percentage' => $percentageRules,
            'reference_component_key' => ['nullable', 'uuid'],
            'input_source' => ['nullable', 'string', Rule::in(ProductComponent::inputSources())],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function prepareComponentForValidation(): void
    {
        $this->normalizeNumericInput(['quantity', 'percentage']);

        foreach (['component_product_doc_num', 'unit_doc_num', 'calculation_method', 'quantity', 'percentage', 'reference_component_key', 'input_source', 'notes'] as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $this->merge([$field => is_string($value) ? trim($value) : $value]);
            }
        }

        if (! $this->filled('calculation_method')) {
            $this->merge(['calculation_method' => ProductComponent::CalculationDirect]);
        }

        $calculationMethod = $this->componentStringValue($this->input('calculation_method'));
        $inputSource = $this->componentStringValue($this->input('input_source'));

        if ($calculationMethod === ProductComponent::CalculationPercentage
            && $inputSource === ProductComponent::InputPercentage
            && ! $this->componentCalculationValueIsPresent($this->input('percentage'))
            && $this->componentCalculationValueIsPresent($this->input('quantity'))
        ) {
            $this->merge([
                'percentage' => $this->input('quantity'),
                'quantity' => null,
            ]);
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

        $calculationMethod = $this->componentStringValue(
            $this->input('calculation_method', ProductComponent::CalculationDirect),
        );
        $unitDocNum = $this->componentStringValue($this->input('unit_doc_num'));

        if ($unitDocNum === '' && $this->componentUnitIsRequired($componentProduct, $calculationMethod)) {
            $validator->errors()->add('unit_doc_num', __('products.components.unit_required'));
        } elseif ($unitDocNum !== ''
            && ! $validator->errors()->has('unit_doc_num')
            && ! $this->componentUnitIsValidForProduct($componentProduct, $unitDocNum)
        ) {
            $validator->errors()->add('unit_doc_num', __('products.components.invalid_unit'));
        }

        if ($calculationMethod === ProductComponent::CalculationPercentage) {
            if (! $this->filled('reference_component_key')) {
                $validator->errors()->add('reference_component_key', __('products.components.reference_required'));
            }

            if (! $validator->errors()->has('input_source')
                && $this->componentAuthoritativeInputSource(
                    $calculationMethod,
                    $this->input('input_source'),
                    $this->input('quantity'),
                    $this->input('percentage'),
                ) === null
            ) {
                $validator->errors()->add('input_source', __('products.components.input_source_required'));
            }
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
        $calculationMethod = trim((string) ($data['calculation_method'] ?? ProductComponent::CalculationDirect))
            ?: ProductComponent::CalculationDirect;
        $inputSource = $this->componentAuthoritativeInputSource(
            $calculationMethod,
            $data['input_source'] ?? null,
            $this->input('quantity'),
            $this->input('percentage'),
        );

        $data['component_product_id'] = $componentProduct?->getKey();
        $data['unit_id'] = $unit?->getKey();
        $data['calculation_method'] = $calculationMethod;
        $data['quantity'] = $inputSource === ProductComponent::InputWeight
            ? app(NumericFormatService::class)->normalizeToScale($data['quantity'] ?? null, 8)
            : null;
        $data['percentage'] = $calculationMethod === ProductComponent::CalculationPercentage
            && $inputSource === ProductComponent::InputPercentage
                ? app(NumericFormatService::class)->normalizeToScale($data['percentage'] ?? null, 8)
                : null;
        $data['reference_component_key'] = $calculationMethod === ProductComponent::CalculationPercentage
            ? $this->blankToNull($data['reference_component_key'] ?? null)
            : null;
        $data['input_source'] = $inputSource;
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
            'calculation_method' => __('products.components.calculation_method'),
            'quantity' => __($this->componentValueLabelKey()),
            'percentage' => __('products.components.percentage'),
            'reference_component_key' => __('products.components.reference_component'),
            'notes' => __('products.components.notes'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $valueMessagePrefix = $this->componentValueMessagePrefix();

        return [
            'component_product_doc_num.required' => __('products.components.component_item_required'),
            'unit_doc_num.required' => __('products.components.unit_required'),
            'quantity.required' => __("products.components.{$valueMessagePrefix}_required"),
            'quantity.required_if' => __("products.components.{$valueMessagePrefix}_required"),
            'quantity.numeric' => __("products.components.{$valueMessagePrefix}_gt_zero"),
            'quantity.integer' => __('products.components.count_integer'),
            'quantity.gt' => __("products.components.{$valueMessagePrefix}_gt_zero"),
            'quantity.min' => __("products.components.{$valueMessagePrefix}_gt_zero"),
            'quantity.regex' => $valueMessagePrefix === 'count'
                ? __('products.components.count_integer')
                : __("products.components.{$valueMessagePrefix}_precision"),
            'percentage.required' => __('products.components.percentage_required'),
            'percentage.numeric' => __('products.components.percentage_gt_zero'),
            'percentage.gt' => __('products.components.percentage_gt_zero'),
            'percentage.regex' => __('products.components.percentage_precision'),
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
            ->productItems()
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
            ->materialItems()
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

    private function componentUnitIsRequired(Product $componentProduct, string $calculationMethod): bool
    {
        return $calculationMethod === ProductComponent::CalculationPercentage
            || app(ProductComponentUnitOptionsService::class)->options($componentProduct) !== [];
    }

    private function componentAuthoritativeInputSource(
        string $calculationMethod,
        mixed $inputSource,
        mixed $quantity,
        mixed $percentage,
    ): ?string {
        if ($calculationMethod !== ProductComponent::CalculationPercentage) {
            return ProductComponent::InputWeight;
        }

        $inputSource = $this->componentStringValue($inputSource);

        if (in_array($inputSource, ProductComponent::inputSources(), true)) {
            return $inputSource;
        }

        $hasQuantity = $this->componentCalculationValueIsPresent($quantity);
        $hasPercentage = $this->componentCalculationValueIsPresent($percentage);

        if ($hasQuantity === $hasPercentage) {
            return null;
        }

        return $hasQuantity
            ? ProductComponent::InputWeight
            : ProductComponent::InputPercentage;
    }

    private function componentCalculationValueIsPresent(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    /**
     * @return list<string>
     */
    private function componentValueRules(string $calculationMethod): array
    {
        if ($calculationMethod === ProductComponent::CalculationCount) {
            return ['bail', 'required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(?:\.0{1,8})?$/'];
        }

        return ['bail', 'required', 'numeric', 'gt:0', 'regex:/^(?:\d{1,10}|\d{0,10}\.\d{1,8})$/'];
    }

    private function componentValueLabelKey(): string
    {
        return match ($this->componentStringValue($this->input('calculation_method'))) {
            ProductComponent::CalculationPercentage => 'products.components.percentage_value',
            ProductComponent::CalculationQuantity => 'products.components.quantity_value',
            ProductComponent::CalculationCount => 'products.components.count_value',
            default => 'products.components.weight',
        };
    }

    private function componentValueMessagePrefix(): string
    {
        return match ($this->componentStringValue($this->input('calculation_method'))) {
            ProductComponent::CalculationPercentage => 'percentage',
            ProductComponent::CalculationQuantity => 'quantity',
            ProductComponent::CalculationCount => 'count',
            default => 'weight',
        };
    }

    private function componentStringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
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
