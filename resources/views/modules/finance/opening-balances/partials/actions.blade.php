<div class="dropdown font-sans-serif position-static">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
        <span class="fas fa-ellipsis-h fs--1"></span>
    </button>
    <div class="dropdown-menu dropdown-menu-end border py-0">
        <div class="py-2">
            @can('opening_balances.view')
                <a class="dropdown-item" href="{{ route('admin.finance.opening-balances.show', $record->doc_num) }}">{{ __('common.actions.view') }}</a>
            @endcan
            @can('opening_balances.approve')
                @if(! $record->approved && ! $record->is_cancelled)
                    <button class="dropdown-item js-approve-opening-balance" type="button" data-url="{{ route('admin.finance.opening-balances.approve', $record->doc_num) }}">{{ __('opening_balances.actions.approve') }}</button>
                @endif
            @endcan
            @can('opening_balances.edit')
                <a class="dropdown-item @if($record->isLockedForEditing()) disabled @endif" href="{{ route('admin.finance.opening-balances.edit', $record->doc_num) }}">{{ __('common.actions.edit') }}</a>
            @endcan
            @can('opening_balances.clone')
                <a class="dropdown-item" href="{{ route('admin.finance.opening-balances.clone', $record->doc_num) }}">{{ __('common.actions.clone') }}</a>
            @endcan
            @if($record->trashed())
                @can('opening_balances.restore')
                    <button class="dropdown-item js-restore-record" type="button" data-doc-num="{{ $record->doc_num }}" data-restore-url="{{ route('admin.finance.opening-balances.restore', $record->doc_num) }}">{{ __('common.actions.restore') }}</button>
                @endcan
            @else
                @can('opening_balances.delete')
                    <button class="dropdown-item text-danger js-delete-record" type="button" data-doc-num="{{ $record->doc_num }}" data-delete-url="{{ route('admin.finance.opening-balances.destroy', $record->doc_num) }}" @disabled($record->isLockedForEditing())>{{ __('common.actions.delete') }}</button>
                @endcan
            @endif
        </div>
    </div>
</div>
