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
        @include('modules.sales.quotations.partials.actions')
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
