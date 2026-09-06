@php
    $isView = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isTrashed = $record?->trashed() ?? false;
    $canList = auth()->user()?->can('quotations.view');
    $canView = auth()->user()?->can('quotations.view');
    $canEdit = auth()->user()?->can('quotations.edit');
    $canClone = auth()->user()?->can('quotations.clone');
    $canDelete = auth()->user()?->can('quotations.delete');
    $canRestore = $isView && $isTrashed && auth()->user()?->can('quotations.restore');
    $canEditRecord = $canEditRecord ?? true;
    $canDeleteRecord = $canDeleteRecord ?? true;
    $shortcutTitles = [
        'back' => __('common.shortcuts.back'),
        'save' => __('common.shortcuts.save'),
        'save_back' => __('common.shortcuts.save_back'),
        'save_clone' => __('common.shortcuts.save_clone'),
        'save_view' => __('common.shortcuts.save_view'),
    ];
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.index') }}" data-shortcut-action="form.back" title="{{ $shortcutTitles['back'] }}" data-bs-title="{{ $shortcutTitles['back'] }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record)
        @include('modules.sales.quotations.partials.actions')
    @endif

    @unless ($isView)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-quotation-submit-action" data-submit-action="save" data-shortcut-action="form.save" title="{{ $shortcutTitles['save'] }}" data-bs-title="{{ $shortcutTitles['save'] }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                @if ($canView)
                    <button class="dropdown-item js-quotation-submit-action" type="submit" data-submit-action="save_view" data-shortcut-action="form.save_view" title="{{ $shortcutTitles['save_view'] }}" data-bs-title="{{ $shortcutTitles['save_view'] }}">{{ __('common.actions.save_and_view') }}</button>
                @endif
                @if ($canList)
                    <button class="dropdown-item js-quotation-submit-action" type="submit" data-submit-action="save_back" data-shortcut-action="form.save_back" title="{{ $shortcutTitles['save_back'] }}" data-bs-title="{{ $shortcutTitles['save_back'] }}">{{ __('common.actions.save_and_back') }}</button>
                @endif
                @if ($canClone && ! $isCreateLike)
                    <button class="dropdown-item js-quotation-submit-action" type="submit" data-submit-action="save_clone" data-shortcut-action="form.save_clone" title="{{ $shortcutTitles['save_clone'] }}" data-bs-title="{{ $shortcutTitles['save_clone'] }}">{{ __('common.actions.save_and_clone') }}</button>
                @endif
            </div>
        </div>
    @endunless
</div>
