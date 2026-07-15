@php
    $isView = $mode === 'view';
    $isCreateLike = in_array($mode, ['create', 'clone'], true);
    $isTrashed = $record?->trashed() ?? false;
    $canList = auth()->user()?->can('fund_transfers.view');
    $canEditRecord = $record && ! $isTrashed && ! $record->isLockedForEditing() && auth()->user()?->can('fund_transfers.edit');
    $canClone = $record && ! $isTrashed && auth()->user()?->can('fund_transfers.clone');
    $canDelete = $record && ! $isTrashed && $record->isDeletable() && auth()->user()?->can('fund_transfers.delete');
    $canRestore = $record && $isTrashed && $record->isDeletable() && auth()->user()?->can('fund_transfers.restore');
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.finance.fund-transfers.index') }}" data-shortcut-action="form.back" title="{{ __('common.shortcuts.back') }}" data-bs-title="{{ __('common.shortcuts.back') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if($isView && $record)
        @if(! $isTrashed)
            @if($canEditRecord)
                <a class="btn btn-primary btn-sm" href="{{ route('admin.finance.fund-transfers.edit', $record->doc_num) }}" data-shortcut-action="form.edit" title="{{ __('common.shortcuts.edit') }}" data-bs-title="{{ __('common.shortcuts.edit') }}">
                    <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
                </a>
            @endif
            @if($canClone)
                <a class="btn btn-falcon-default btn-sm js-clone-record" href="{{ route('admin.finance.fund-transfers.clone', $record->doc_num) }}" data-shortcut-action="form.clone" title="{{ __('common.shortcuts.clone') }}" data-bs-title="{{ __('common.shortcuts.clone') }}">
                    <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}
                </a>
            @endif
            @if($record->isDraft() && auth()->user()?->can('fund_transfers.approve'))
                <button type="button" class="btn btn-falcon-success btn-sm js-fund-transfer-approve" data-url="{{ route('admin.finance.fund-transfers.approve', $record->doc_num) }}">
                    {{ __('fund_transfers.actions.approve') }}
                </button>
            @endif
            @if(! $record->isCancelled() && auth()->user()?->can('fund_transfers.cancel'))
                <button type="button" class="btn btn-falcon-warning btn-sm js-fund-transfer-cancel" data-url="{{ route('admin.finance.fund-transfers.cancel', $record->doc_num) }}">
                    {{ __('fund_transfers.actions.cancel') }}
                </button>
            @endif
            @can('fund_transfers.print')
                <button type="button" class="btn btn-falcon-default btn-sm js-fund-transfer-print" data-url="{{ route('admin.finance.fund-transfers.print', $record->doc_num) }}">
                    <span class="fas fa-print me-1"></span>{{ __('fund_transfers.actions.print') }}
                </button>
            @endcan
            @if($canDelete)
                <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-shortcut-action="form.delete" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.finance.fund-transfers.destroy', $record->doc_num) }}" data-redirect-url="{{ route('admin.finance.fund-transfers.index') }}" title="{{ __('common.shortcuts.delete') }}" data-bs-title="{{ __('common.shortcuts.delete') }}">
                    <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
                </button>
            @endif
        @elseif($canRestore)
            <button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.finance.fund-transfers.restore', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('common.actions.restore') }}
            </button>
        @endif
    @endif

    @unless($isView)
        @if($isCreateLike)
            <div class="btn-group btn-group-sm">
                <button type="submit" class="btn btn-primary js-finance-submit-action" data-submit-action="save_new" title="{{ __('common.shortcuts.save_new') }}" data-bs-title="{{ __('common.shortcuts.save_new') }}">
                    <span class="fas fa-plus me-1"></span>{{ __('common.actions.save_and_new') }}
                </button>
                <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end py-2">
                    <button class="dropdown-item js-finance-submit-action" type="submit" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('common.shortcuts.save') }}" data-bs-title="{{ __('common.shortcuts.save') }}">
                        {{ __('common.actions.save') }}
                    </button>
                </div>
            </div>
        @else
            <button type="submit" class="btn btn-primary btn-sm js-finance-submit-action" data-submit-action="save" data-shortcut-action="form.save" title="{{ __('common.shortcuts.save') }}" data-bs-title="{{ __('common.shortcuts.save') }}">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
        @endif
    @endunless
</div>
