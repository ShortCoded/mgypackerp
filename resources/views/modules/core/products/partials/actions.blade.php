@php
    use Modules\Core\Models\Product;

    $productContext = $productContext ?? Product::ContextProducts;
    $routePrefix = $productContext === Product::ContextRawMaterials ? 'admin.raw-materials.' : 'admin.products.';
    $permissionPrefix = $productContext === Product::ContextRawMaterials ? 'raw_materials' : 'products';
    $isTrashed = $product->trashed();
    $canView = auth()->user()?->can($permissionPrefix.'.view') && $product->doc_num !== null;
    $canEdit = auth()->user()?->can($permissionPrefix.'.edit');
    $canDelete = auth()->user()?->can($permissionPrefix.'.delete');
    $canRestore = $isTrashed && auth()->user()?->can($permissionPrefix.'.restore') && $product->doc_num !== null;
    $canClone = ! $isTrashed && auth()->user()?->can($permissionPrefix.'.clone') && $product->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || $canEdit || $canClone || $canDelete)) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route($routePrefix . 'show', $product->doc_num) }}" data-doc-num="{{ $product->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $product->doc_num }}" data-record-name="{{ $product->name }}" data-restore-url="{{ route($routePrefix . 'restore', $product->doc_num) }}">
                        {{ __('products.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route($routePrefix . 'show', $product->doc_num) }}" data-doc-num="{{ $product->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canEdit)
                    <a class="dropdown-item js-edit-record" href="{{ route($routePrefix . 'edit', $product->doc_num) }}" data-doc-num="{{ $product->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endif
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route($routePrefix . 'clone', $product->doc_num) }}" data-doc-num="{{ $product->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @if ($canDelete)
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $product->doc_num }}" data-record-name="{{ $product->name }}" data-url="{{ route($routePrefix . 'destroy', $product->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endif
            @endif
        </div>
    </div>
@endif
