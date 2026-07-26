@php
    use Modules\Core\Models\Product;

    $productContext = $productContext ?? Product::ContextProducts;
    $routePrefix = match ($productContext) {
        Product::ContextRawMaterials => 'admin.raw-materials.',
        Product::ContextPackagingMaterials => 'admin.packaging-materials.',
        default => 'admin.products.',
    };
    $permissionPrefix = match ($productContext) {
        Product::ContextRawMaterials => 'raw_materials',
        Product::ContextPackagingMaterials => 'packaging_materials',
        default => 'products',
    };
    $canList = auth()->user()?->can($permissionPrefix.'.view');
    $canSave = ! $isView;
    $canView = auth()->user()?->can($permissionPrefix.'.view');
    $canEdit = auth()->user()?->can($permissionPrefix.'.edit');
    $canCreate = auth()->user()?->can($permissionPrefix.'.create');
    $canClone = auth()->user()?->can($permissionPrefix.'.clone');
    $canDelete = auth()->user()?->can($permissionPrefix.'.delete');
    $isTrashed = $record?->trashed() ?? false;
    $canRestore = $isView && $isTrashed && auth()->user()?->can($permissionPrefix.'.restore') && $record?->doc_num !== null;
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $mainSubmitAction = 'save';
    $mainShortcutTitle = __('common.shortcuts.save');
    $showSaveDropdown = ! $isCreateLike || $canView || $canEdit || $canList || $canClone;
    $isHeaderActions = str_contains((string) ($class ?? ''), 'products-form-actions-header');
    $showRecordNavigation = $isHeaderActions && $mode === 'edit' && $record && $canEdit;
    $previousRecordUrl = $recordNavigation['previous'] ?? null;
    $nextRecordUrl = $recordNavigation['next'] ?? null;
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route($routePrefix . 'index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($showRecordNavigation)
        @if ($previousRecordUrl)
            <a class="btn btn-falcon-default btn-sm" href="{{ $previousRecordUrl }}" title="{{ __('products.navigation.previous_record') }}" data-bs-title="{{ __('products.navigation.previous_record') }}">
                <span class="fas fa-chevron-left me-1"></span>{{ __('products.navigation.previous_record') }}
            </a>
        @else
            <button class="btn btn-falcon-default btn-sm disabled" type="button" disabled title="{{ __('products.navigation.no_previous_record') }}" data-bs-title="{{ __('products.navigation.no_previous_record') }}">
                <span class="fas fa-chevron-left me-1"></span>{{ __('products.navigation.previous_record') }}
            </button>
        @endif

        @if ($nextRecordUrl)
            <a class="btn btn-falcon-default btn-sm" href="{{ $nextRecordUrl }}" title="{{ __('products.navigation.next_record') }}" data-bs-title="{{ __('products.navigation.next_record') }}">
                {{ __('products.navigation.next_record') }}<span class="fas fa-chevron-right ms-1"></span>
            </a>
        @else
            <button class="btn btn-falcon-default btn-sm disabled" type="button" disabled title="{{ __('products.navigation.no_next_record') }}" data-bs-title="{{ __('products.navigation.no_next_record') }}">
                {{ __('products.navigation.next_record') }}<span class="fas fa-chevron-right ms-1"></span>
            </button>
        @endif
    @endif

    @if ($isView && $record)
        @if (! $isTrashed && $canEdit)
            <a class="btn btn-primary btn-sm" href="{{ route($routePrefix . 'edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif

        @if (! $isTrashed && $canClone)
            <a class="btn btn-falcon-default btn-sm js-clone-record" href="{{ route($routePrefix . 'clone', $record->doc_num) }}" data-shortcut-action="form.clone" title="{{ __('common.shortcuts.clone') }}" data-bs-title="{{ __('common.shortcuts.clone') }}">
                <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}
            </a>
        @endif

        @if ($canRestore)
            <button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->name }}" data-restore-url="{{ route($routePrefix . 'restore', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('products.trash.restore') }}
            </button>
        @endif

        @if (! $isTrashed && $canDelete)
            <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-url="{{ route($routePrefix . 'destroy', $record->doc_num) }}" data-redirect-url="{{ route($routePrefix . 'index') }}" title="{{ __('common.shortcuts.delete') }}" data-bs-title="{{ __('common.shortcuts.delete') }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endif
    @endif

    @if ($canSave)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary" data-submit-action="{{ $mainSubmitAction }}" data-shortcut-action="form.save" title="{{ $mainShortcutTitle }}" data-bs-title="{{ $mainShortcutTitle }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            @if ($showSaveDropdown)
                <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end py-2">
                    @if (! $isCreateLike)
                        <button class="dropdown-item" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('common.shortcuts.save') }}" data-bs-title="{{ __('common.shortcuts.save') }}">{{ __('common.actions.save') }}</button>
                    @endif
                    @if ($canView)
                        <button class="dropdown-item" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ __('common.shortcuts.save_view') }}" data-bs-title="{{ __('common.shortcuts.save_view') }}">{{ __('common.actions.save_and_view') }}</button>
                    @endif
                    @if ($canEdit && $isCreateLike)
                        <button class="dropdown-item" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ __('common.shortcuts.save_edit') }}" data-bs-title="{{ __('common.shortcuts.save_edit') }}">{{ __('common.actions.save_and_edit') }}</button>
                    @endif
                    @if ($canList)
                        <button class="dropdown-item" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ __('common.shortcuts.save_back') }}" data-bs-title="{{ __('common.shortcuts.save_back') }}">{{ __('common.actions.save_and_back') }}</button>
                    @endif
                    @if ($canClone)
                        <button class="dropdown-item" type="submit" data-submit-action="save_clone" data-shortcut-action="form.save_clone" title="{{ __('common.shortcuts.save_clone') }}" data-bs-title="{{ __('common.shortcuts.save_clone') }}">{{ __('common.actions.save_and_clone') }}</button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
