@php
    $canList = auth()->user()?->can('financial_periods.view');
    $canSave = ! $isView;
    $canView = auth()->user()?->can('financial_periods.view');
    $canEdit = auth()->user()?->can('financial_periods.edit');
    $canCreate = auth()->user()?->can('financial_periods.create');
    $canClone = auth()->user()?->can('financial_periods.clone');
    $isTrashed = $record?->trashed() ?? false;
    $canRestore = $isView && $isTrashed && auth()->user()?->can('financial_periods.restore') && $record?->doc_num !== null;
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $mainSubmitAction = 'save';
    $mainShortcutTitle = __('common.shortcuts.save');
    $showSaveDropdown = ! $isCreateLike || $canView || $canEdit || $canList || $canClone;
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.financial-periods.index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record)
        @if (! $isTrashed && ! $record->is_closed && auth()->user()?->can('financial_periods.close'))
            <button type="submit" form="financial-period-close-form" class="btn btn-warning btn-sm" onclick='return confirm(@js(__('financial_periods.messages.close_confirm_text')))'>
                <span class="fas fa-lock me-1"></span>{{ __('financial_periods.actions.close') }}
            </button>
        @elseif (! $isTrashed && $record->is_closed && auth()->user()?->can('financial_periods.reopen'))
            <button type="submit" form="financial-period-reopen-form" class="btn btn-falcon-warning btn-sm" onclick='return confirm(@js(__('financial_periods.messages.reopen_confirm_text')))'>
                <span class="fas fa-lock-open me-1"></span>{{ __('financial_periods.actions.reopen') }}
            </button>
        @endif

        @if (! $isTrashed && $canEdit)
            <a class="btn btn-primary btn-sm" href="{{ route('admin.financial-periods.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
                <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
            </a>
        @endif

        @if (! $isTrashed && $canClone)
            <a class="btn btn-falcon-default btn-sm js-clone-record" href="{{ route('admin.financial-periods.clone', $record->doc_num) }}" data-shortcut-action="form.clone" title="{{ __('common.shortcuts.clone') }}" data-bs-title="{{ __('common.shortcuts.clone') }}">
                <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}
            </a>
        @endif

        @if ($canRestore)
            <button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-record-name="{{ $record->name }}" data-restore-url="{{ route('admin.financial-periods.restore', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('financial_periods.trash.restore') }}
            </button>
        @endif

        @if (! $isTrashed && auth()->user()?->can('financial_periods.delete'))
            <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-url="{{ route('admin.financial-periods.destroy', $record->doc_num) }}" data-redirect-url="{{ route('admin.financial-periods.index') }}" title="{{ __('common.shortcuts.delete') }}" data-bs-title="{{ __('common.shortcuts.delete') }}">
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
