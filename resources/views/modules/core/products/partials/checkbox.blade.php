@php
    use Modules\Core\Models\Product;

    $selectRecordTranslation = ($productContext ?? Product::ContextProducts) === Product::ContextRawMaterials
        ? 'products.raw_materials.select_record'
        : 'products.select_record';
    $permissionPrefix = ($productContext ?? Product::ContextProducts) === Product::ContextRawMaterials
        ? 'raw_materials'
        : 'products';
@endphp

@if (auth()->user()?->can($permissionPrefix.'.delete'))
    @unless ($product->trashed())
        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
            <input class="form-check-input js-record-select js-record-checkbox" type="checkbox" value="{{ $product->doc_num }}" data-doc-num="{{ $product->doc_num }}" aria-label="{{ __($selectRecordTranslation, ['record' => $product->doc_num]) }}">
        </div>
    @endunless
@endif
