@php
    $isView = $mode === 'view';
    $isCreate = $mode === 'create';
    $isTrashed = $record?->trashed() ?? false;
    $canList = auth()->user()?->can('price_lists.view');
    $canView = auth()->user()?->can('price_lists.view');
    $canEdit = auth()->user()?->can('price_lists.edit');
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
