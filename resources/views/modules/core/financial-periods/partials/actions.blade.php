@php
    $isTrashed = $financialPeriod->trashed();
    $canView = auth()->user()?->can('financial_periods.view') && $financialPeriod->doc_num !== null;
    $canRestore = $isTrashed
        && auth()->user()?->can('financial_periods.restore')
        && $financialPeriod->doc_num !== null;
    $canClone = ! $isTrashed
        && auth()->user()?->can('financial_periods.clone')
        && $financialPeriod->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || auth()->user()?->can('financial_periods.edit') || $canClone || auth()->user()?->can('financial_periods.delete'))) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.financial-periods.show', $financialPeriod->doc_num) }}" data-doc-num="{{ $financialPeriod->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $financialPeriod->doc_num }}" data-record-name="{{ $financialPeriod->name }}" data-restore-url="{{ route('admin.financial-periods.restore', $financialPeriod->doc_num) }}">
                        {{ __('financial_periods.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.financial-periods.show', $financialPeriod->doc_num) }}" data-doc-num="{{ $financialPeriod->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @can('financial_periods.edit')
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.financial-periods.edit', $financialPeriod->doc_num) }}" data-doc-num="{{ $financialPeriod->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endcan
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.financial-periods.clone', $financialPeriod->doc_num) }}" data-doc-num="{{ $financialPeriod->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @can('financial_periods.delete')
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger js-delete-record" data-doc-num="{{ $financialPeriod->doc_num }}" data-record-name="{{ $financialPeriod->name }}" data-url="{{ route('admin.financial-periods.destroy', $financialPeriod->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endcan
            @endif
        </div>
    </div>
@endif
