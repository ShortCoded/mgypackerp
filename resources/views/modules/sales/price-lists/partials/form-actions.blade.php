@php
    $isView = $mode === 'view';
    $isCreate = in_array($mode, ['create', 'clone'], true);
    $isTrashed = $record?->trashed() ?? false;
    $canList = auth()->user()?->can('price_lists.view');
    $canView = auth()->user()?->can('price_lists.view');
    $canEdit = auth()->user()?->can('price_lists.edit');
    $canClone = $isView && $record && ! $isTrashed && auth()->user()?->can('price_lists.clone');
    $canDelete = $isView && $record && ! $isTrashed && auth()->user()?->can('price_lists.delete');
    $canRestore = $isView && $record && $isTrashed && auth()->user()?->can('price_lists.restore');
    $canUseTrashed = ! $isTrashed || auth()->user()?->can('price_lists.view_trashed');
    $canPrint = $isView && $record && $canView && $canUseTrashed && auth()->user()?->can('price_lists.print');
    $canExport = $isView && $record && $canView && $canUseTrashed && auth()->user()?->can('price_lists.export');
    $exportOptions = [];
    if ($canPrint) {
        $exportOptions[] = ['label' => __('price_lists.actions.pdf'), 'url' => route('admin.sales.price-lists.pdf', $record), 'icon' => 'file-pdf', 'permission' => 'price_lists.print', 'newTab' => true];
    }
    if ($canExport) {
        $exportOptions[] = ['label' => __('price_lists.actions.excel'), 'url' => route('admin.sales.price-lists.export.xlsx', $record), 'icon' => 'file-excel', 'permission' => 'price_lists.export'];
        $exportOptions[] = ['label' => __('price_lists.actions.csv'), 'url' => route('admin.sales.price-lists.export.csv', $record), 'icon' => 'file-csv', 'permission' => 'price_lists.export'];
    }
    $canReview = $isView && $record && ! $isTrashed && ! $record->reviewed_at && auth()->user()?->can('price_lists.review');
    $canApprove = $isView && $record && ! $isTrashed && $record->reviewed_at && ! $record->approved_at && auth()->user()?->can('price_lists.approve');
    $mainSubmitAction = $isCreate ? 'save_new' : 'save';
    $shortcutTitles = [
        'back' => __('common.shortcuts.back'),
        'edit' => __('common.shortcuts.edit'),
        'save' => __('common.shortcuts.save'),
        'save_back' => __('common.shortcuts.save_back'),
        'save_edit' => __('common.shortcuts.save_edit'),
        'save_view' => __('common.shortcuts.save_view'),
    ];
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.price-lists.index') }}" data-shortcut-action="form.back" title="{{ $shortcutTitles['back'] }}" data-bs-title="{{ $shortcutTitles['back'] }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record && ! $isTrashed && $canEdit)
        <a class="btn btn-primary btn-sm" href="{{ route('admin.sales.price-lists.edit', $record) }}" data-shortcut-action="form.edit" title="{{ $shortcutTitles['edit'] }}" data-bs-title="{{ $shortcutTitles['edit'] }}">
            <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
        </a>
    @endif

    @if ($isView && $record && ! $isTrashed && $canEdit)
        <button type="button" class="btn btn-falcon-default btn-sm js-increase-price-list" data-doc-num="{{ $record->doc_num }}" data-increase-url="{{ route('admin.sales.price-lists.increase-by-percentage', $record) }}" data-success-url="{{ route('admin.sales.price-lists.show', $record) }}"><span class="fas fa-percent me-1"></span>{{ __('price_lists.actions.increase') }}</button>
    @endif

    @if ($canClone)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.price-lists.clone', $record) }}"><span class="fas fa-copy me-1"></span>{{ __('price_lists.actions.clone_record') }}</a>
    @endif

    @if ($isView && $record && $canView && $canUseTrashed)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.price-lists.history', $record) }}"><span class="fas fa-history me-1"></span>{{ __('price_lists.actions.history') }}</a>
    @endif

    @if ($exportOptions !== [])
        <x-admin.report.actions-toolbar
            :show-filters="false"
            :show-refresh="false"
            :export-options="$exportOptions" />
    @endif

    @if ($canReview)
        <button type="button" class="btn btn-info btn-sm js-price-list-lifecycle" data-action-url="{{ route('admin.sales.price-lists.review', $record) }}" data-success-url="{{ route('admin.sales.price-lists.show', $record) }}"><span class="fas fa-user-check me-1"></span>{{ __('price_lists.actions.review') }}</button>
    @endif
    @if ($canApprove)
        <button type="button" class="btn btn-success btn-sm js-price-list-lifecycle" data-action-url="{{ route('admin.sales.price-lists.approve', $record) }}" data-success-url="{{ route('admin.sales.price-lists.show', $record) }}"><span class="fas fa-check-circle me-1"></span>{{ __('price_lists.actions.approve') }}</button>
    @endif

    @if ($canRestore)
        <button type="button" class="btn btn-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.sales.price-lists.restore', $record) }}" data-success-url="{{ route('admin.sales.price-lists.show', $record) }}"><span class="fas fa-undo me-1"></span>{{ __('common.actions.restore') }}</button>
    @endif

    @if ($canDelete)
        <button type="button" class="btn btn-falcon-danger btn-sm js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.sales.price-lists.destroy', $record) }}" data-success-url="{{ route('admin.sales.price-lists.index') }}"><span class="fas fa-trash me-1"></span>{{ __('common.actions.delete') }}</button>
    @endif

    @unless ($isView)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-price-list-submit-action" data-submit-action="{{ $mainSubmitAction }}" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                @if ($canView)
                    <button class="dropdown-item js-price-list-submit-action" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ $shortcutTitles['save_view'] }}" data-bs-title="{{ $shortcutTitles['save_view'] }}">{{ __('common.actions.save_and_view') }}</button>
                @endif
                @if ($isCreate && $canEdit)
                    <button class="dropdown-item js-price-list-submit-action" type="submit" data-submit-action="save_edit" data-shortcut-action="form.save_edit" title="{{ $shortcutTitles['save_edit'] }}" data-bs-title="{{ $shortcutTitles['save_edit'] }}">{{ __('common.actions.save_and_edit') }}</button>
                @endif
                @if ($canList)
                    <button class="dropdown-item js-price-list-submit-action" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ $shortcutTitles['save_back'] }}" data-bs-title="{{ $shortcutTitles['save_back'] }}">{{ __('common.actions.save_and_back') }}</button>
                @endif
            </div>
        </div>
    @endunless
</div>
