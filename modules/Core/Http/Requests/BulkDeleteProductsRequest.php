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
                        if ($this->productContext() === Product::ContextRawMaterials) {
                            $query->where('item_classification', Product::ClassificationRawMaterial);

                            return;
                        }

                        $query
                            ->whereNull('item_classification')
                            ->orWhere('item_classification', '<>', Product::ClassificationRawMaterial);
                    })
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    private function productContext(): string
    {
        $routeName = (string) ($this->route()?->getName() ?? '');

        return str_starts_with($routeName, 'admin.raw-materials.')
            ? Product::ContextRawMaterials
            : Product::ContextProducts;
    }

    private function permissionPrefix(): string
    {
        return $this->productContext() === Product::ContextRawMaterials
            ? 'raw_materials'
            : 'products';
    }
}
