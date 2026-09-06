<div class="dropstart font-sans-serif position-static d-inline-block">
    <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" aria-label="{{ __('common.fields.actions') }}"><span class="fas fa-ellipsis-h fs-10"></span></button>
    <div class="dropdown-menu dropdown-menu-end py-2">
        <a class="dropdown-item" href="{{ route($prefix.'.show', $record) }}">{{ __('common.actions.view') }}</a>
        @if(in_array($kind, ['sales_requests', 'sales_orders']) && in_array($record->status, ['draft', 'rejected', 'reopened']))
            @can($kind.'.edit')<a class="dropdown-item" href="{{ route($prefix.'.edit', $record) }}">{{ __('common.actions.edit') }}</a>@endcan
        @endif
        @can($kind.'.print')<a class="dropdown-item" href="{{ route($prefix.'.print', $record) }}" target="_blank">{{ __('common.actions.print') }}</a>@endcan
    </div>
</div>
