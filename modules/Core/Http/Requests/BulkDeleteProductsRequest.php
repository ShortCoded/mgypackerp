<?php

namespace Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\Product;
use Modules\Core\Services\OperatingCompanyContextService;

class BulkDeleteProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permissionPrefix().'.delete');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'doc_nums' => ['required', 'array', 'min:1'],
            'doc_nums.*' => [
                'required',
                'string',
                'distinct',
                Rule::exists('products', 'doc_num')
                    ->where('company_id', app(OperatingCompanyContextService::class)->requireCompanyId($this))
                    ->where(function ($query): void {
                        $classification = Product::classificationForContext($this->productContext());

                        if ($classification !== null) {
                            $query->where('item_classification', $classification);

                            return;
                        }

                        $query
                            ->whereNull('item_classification')
                            ->orWhereNotIn('item_classification', Product::materialClassifications());
                    })
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    private function productContext(): string
    {
        $routeName = (string) ($this->route()?->getName() ?? '');

        return match (true) {
            str_starts_with($routeName, 'admin.raw-materials.') => Product::ContextRawMaterials,
            str_starts_with($routeName, 'admin.packaging-materials.') => Product::ContextPackagingMaterials,
            default => Product::ContextProducts,
        };
    }

    private function permissionPrefix(): string
    {
        return match ($this->productContext()) {
            Product::ContextRawMaterials => 'raw_materials',
            Product::ContextPackagingMaterials => 'packaging_materials',
            default => 'products',
        };
    }
}
