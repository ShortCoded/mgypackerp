@extends('layouts.app')

@section('content')
    <div class="card mb-3">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-auto">
                    <h5 class="mb-0">{{ __('user_tasks.title') }}</h5>
                </div>
                <div class="col-auto">
                    @can('tasks.create')
                        <x-buttons.add-record :href="route('admin.tasks.create')" :label="__('user_tasks.create')" />
                    @endcan
                </div>
            </div>
        </div>
    </div>

    @can('tasks.document_number_settings.update')
        <div class="card mb-3">
            <div class="card-header">
                <button class="btn btn-link p-0" type="button" data-bs-toggle="collapse" data-bs-target="#document-number-settings">
                    {{ __('user_tasks.document_number_settings.title') }}
                </button>
            </div>
            <div id="document-number-settings" class="collapse">
                <div class="card-body">
                    <form id="user-tasks-document-number-settings-form" action="{{ route('admin.tasks.document-number-settings.update') }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div data-form-alert></div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="document-prefix">{{ __('user_tasks.document_number_settings.prefix') }}</label>
                                <input class="form-control" id="document-prefix" name="prefix" value="{{ $documentNumberSettings['prefix'] ?? '' }}">
                                <div class="invalid-feedback" data-error-for="prefix"></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="document-padding">{{ __('user_tasks.document_number_settings.padding') }}</label>
                                <input class="form-control" id="document-padding" name="padding" type="number" min="0" max="10" value="{{ $documentNumberSettings['padding'] ?? 5 }}">
                                <div class="invalid-feedback" data-error-for="padding"></div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button class="btn btn-primary" type="submit">{{ __('user_tasks.document_number_settings.save') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    @can('tasks.view_trashed')
        <div class="card mb-3">
            <div class="card-body py-2">
                <div class="row align-items-center g-2">
                    <div class="col-auto">
                        <label class="form-label mb-0" for="user_tasks_trash_filter">{{ __('common.trash.filter_label') }}</label>
                    </div>
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="user_tasks_trash_filter" name="trash_filter">
                            <option value="active">{{ __('companies.trash.active') }}</option>
                            <option value="trashed">{{ __('companies.trash.trashed') }}</option>
                            <option value="all">{{ __('companies.trash.all') }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    @endcan

    @can('tasks.bulk_delete')
        <div class="card mb-3 d-none" id="user-tasks-bulk-actions">
            <div class="card-body py-2">
                <div class="row align-items-center g-2">
                    <div class="col">
                        <span data-selected-count>0</span>
                        <span>{{ __('user_tasks.selected') }}</span>
                    </div>
                    <div class="col-auto">
                        <button class="btn btn-falcon-danger btn-sm" type="button" data-bulk-delete-url="{{ route('admin.tasks.bulk-delete') }}" data-shortcut-action="bulk-delete">
                            <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endcan

    <div class="card erp-datatable-card">
        <div class="card-body">
            <table class="table table-sm table-striped fs--1 mb-0 falcon-data-table erp-datatable" id="user-tasks-table" data-ajax-url="{{ route('admin.tasks.data') }}">
                <thead>
                    <tr>
                        <th data-orderable="false" data-searchable="false">
                            <span class="visually-hidden">{{ __('user_tasks.select_all') }}</span>
                        </th>
                        <th>{{ __('user_tasks.attributes.doc_num') }}</th>
                        <th>{{ __('user_tasks.attributes.title') }}</th>
                        <th>{{ __('user_tasks.attributes.type') }}</th>
                        <th>{{ __('user_tasks.attributes.status') }}</th>
                        <th>{{ __('user_tasks.attributes.priority') }}</th>
                        <th>{{ __('user_tasks.attributes.assigned_to') }}</th>
                        <th>{{ __('user_tasks.attributes.assigned_by') }}</th>
                        <th>{{ __('user_tasks.attributes.due_at') }}</th>
                        <th>{{ __('common.fields.created_at') }}</th>
                        <th data-orderable="false" data-searchable="false">{{ __('common.fields.actions') }}</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $coreUserTasksMessages = [
            'deleteConfirmTitle' => __('user_tasks.messages.delete_confirm_title'),
            'deleteConfirmText' => __('user_tasks.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('user_tasks.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('user_tasks.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('user_tasks.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('user_tasks.messages.bulk_delete_confirm_yes'),
            'deleted' => __('user_tasks.messages.deleted'),
            'bulkDeleted' => __('user_tasks.messages.bulk_deleted', ['count' => 0]),
            'settingsSaved' => __('user_tasks.document_number_settings.updated_successfully'),
            'noRecordsSelected' => __('user_tasks.messages.no_records_selected'),
            'noChanges' => __('common.messages.no_changes'),
            'saved' => __('common.messages.saved_successfully'),
            'validationFailed' => __('common.messages.validation_failed'),
            'unexpectedError' => __('common.messages.unexpected_error'),
            'cancel' => __('common.actions.cancel'),
            'confirm' => __('common.actions.confirm'),
        ];
    @endphp
    <script>
        window.coreUserTasksMessages = @json($coreUserTasksMessages);
    </script>
    <script src="{{ asset('assets/js/modules/Core/user-tasks.js') }}"></script>
@endpush
