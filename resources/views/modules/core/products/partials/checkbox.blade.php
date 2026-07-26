@php
    use Modules\Core\Models\Product;

    $productContext = $productContext ?? Product::ContextProducts;
    $selectRecordTranslation = match ($productContext) {
        Product::ContextRawMaterials => 'products.raw_materials.select_record',
        Product::ContextPackagingMaterials => 'products.packaging_materials.select_record',
        default => 'products.select_record',
    };
    $permissionPrefix = match ($productContext) {
        Product::ContextRawMaterials => 'raw_materials',
        Product::ContextPackagingMaterials => 'packaging_materials',
        default => 'products',
    };
@endphp

@if (auth()->user()?->can($permissionPrefix.'.delete'))
    @unless ($product->trashed())
        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
            <input class="form-check-input js-record-select js-record-checkbox" type="checkbox" value="{{ $product->doc_num }}" data-doc-num="{{ $product->doc_num }}" aria-label="{{ __($selectRecordTranslation, ['record' => $product->doc_num]) }}">
        </div>
    @endunless
@endif
