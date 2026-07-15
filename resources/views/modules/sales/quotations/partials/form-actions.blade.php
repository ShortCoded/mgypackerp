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
@endphp

<div class="d-flex flex-wrap justify-content-end gap-2 {{ $class ?? '' }}">
    @if ($canList)
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.index') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    @endif

    @if ($isView && $record)
        @if (! $isTrashed)
            @if ($canEdit && $canEditRecord)
                <a class="btn btn-primary btn-sm" href="{{ route('admin.sales.quotations.edit', $record->doc_num) }}">
                    <span class="fas fa-edit me-1"></span>{{ __('common.actions.edit') }}
                </a>
            @endif
            @if ($canClone)
                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.quotations.clone', $record->doc_num) }}">
                    <span class="fas fa-copy me-1"></span>{{ __('common.actions.clone_record') }}
                </a>
            @endif
            @if ($canDelete && $canDeleteRecord)
                <button type="button" class="btn btn-falcon-default text-danger btn-sm js-delete-record" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.sales.quotations.destroy', $record->doc_num) }}" data-redirect-url="{{ route('admin.sales.quotations.index') }}">
                    <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
                </button>
            @endif
        @elseif ($canRestore)
            <button type="button" class="btn btn-falcon-default text-success btn-sm js-restore-record" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.sales.quotations.restore', $record->doc_num) }}">
                <span class="fas fa-undo me-1"></span>{{ __('common.actions.restore') }}
            </button>
        @endif
    @endif

    @unless ($isView)
        <div class="btn-group btn-group-sm">
            <button type="submit" class="btn btn-primary js-quotation-submit-action" data-submit-action="save">
                <span class="fas fa-save me-1"></span>{{ __('common.actions.save_data') }}
            </button>
            <button class="btn btn-primary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="visually-hidden">{{ __('common.fields.actions') }}</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end py-2">
                @if ($canView)
                    <button class="dropdown-item js-quotation-submit-action" type="submit" data-submit-action="save_view">{{ __('common.actions.save_and_view') }}</button>
                @endif
                @if ($canList)
                    <button class="dropdown-item js-quotation-submit-action" type="submit" data-submit-action="save_back">{{ __('common.actions.save_and_back') }}</button>
                @endif
                @if ($canClone && ! $isCreateLike)
                    <button class="dropdown-item js-quotation-submit-action" type="submit" data-submit-action="save_clone">{{ __('common.actions.save_and_clone') }}</button>
                @endif
            </div>
        </div>
    @endunless
</div>
