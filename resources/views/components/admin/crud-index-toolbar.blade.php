@props([
    'title',
    'addRoute' => null,
    'addPermission' => null,
    'addLabel' => null,
    'showTrashFilter' => false,
    'showBulkActions' => false,
    'trashFilterId' => 'trash_filter',
    'bulkActionsClass' => '',
    'bulkActionLabel' => __('common.bulk_action'),
    'toolbarActionsClass' => '',
])

<div class="card-header">
    <div class="row flex-between-center">
        <div class="col-6 col-sm-auto d-flex align-items-center pe-0">
            <h5 class="py-2 mb-0 fs-9 text-nowrap py-xl-0">{{ $title }}</h5>
        </div>
        <div class="col-6 col-sm-auto ms-auto text-end ps-0 d-flex justify-content-end align-items-center gap-2 {{ $toolbarActionsClass }}">
            @if ($showTrashFilter)
                <div class="gap-2 d-flex align-items-center">
                    <label class="mb-0 form-label text-700 fs-10" for="{{ $trashFilterId }}">{{ __('common.trash.filter_label') }}</label>
                    <select class="w-auto form-select form-select-sm" id="{{ $trashFilterId }}">
                        <option value="active">{{ __('common.trash.active') }}</option>
                        <option value="trashed">{{ __('common.trash.trashed') }}</option>
                        <option value="all">{{ __('common.trash.all') }}</option>
                    </select>
                </div>
            @endif
            @if ($showBulkActions)
                <div class="d-none align-items-center gap-2 {{ $bulkActionsClass }}" id="bulk_actions_bar">
                    <span class="badge rounded-pill badge-subtle-primary" id="bulk_selected_count">0</span>
                    <select class="w-auto form-select form-select-sm" id="bulk_action_select" aria-label="{{ $bulkActionLabel }}">
                        <option value="delete">{{ __('common.actions.delete') }}</option>
                    </select>
                    <button type="button" class="btn btn-falcon-danger btn-sm" id="bulk_action_apply" data-label="{{ __('common.actions.apply') }}" title="{{ __('common.shortcuts.bulk_apply') }}" data-bs-title="{{ __('common.shortcuts.bulk_apply') }}" disabled>
                        <span class="fas fa-check" data-fa-transform="shrink-3 down-2"></span><span class="d-none d-sm-inline-block ms-1">{{ __('common.actions.apply') }}</span>
                    </button>
                </div>
            @endif
            @if ($addRoute)
                <x-buttons.add-record :href="$addRoute" :permission="$addPermission" :label="$addLabel" />
            @endif
            {{ $slot }}
        </div>
    </div>
</div>
