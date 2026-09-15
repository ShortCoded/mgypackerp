<div class="d-flex flex-wrap justify-content-end gap-2">
    @include('modules.finance.partials.form-actions', [
        'mode' => $mode,
        'record' => $record,
        'resource' => 'inventory.documents',
        'routePrefix' => 'admin.inventory.documents',
    ])
    @can('inventory.documents.post')
        <button class="btn btn-success btn-sm" type="submit" data-submit-action="post_and_view">
            <span class="fas fa-check me-1"></span>{{ __('inventory.movements.actions.post_and_view') }}
        </button>
    @endcan
</div>
