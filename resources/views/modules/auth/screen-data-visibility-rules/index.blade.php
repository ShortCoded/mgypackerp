@extends('layouts.app')

@section('title', __('screen_data_visibility_rules.title'))

@section('content')
    <div class="card erp-datatable-card">
        <x-admin.crud-index-toolbar
            :title="__('screen_data_visibility_rules.title')"
            :add-route="route('admin.screen-data-visibility-rules.create')"
            add-permission="screen_data_visibility_rules.create"
            :show-trash-filter="auth()->user()?->can('screen_data_visibility_rules.view_trashed')"
            :show-bulk-actions="auth()->user()?->can('screen_data_visibility_rules.delete')"
            trash-filter-id="screen_visibility_rule_trash_filter"
            bulk-actions-class="screen-visibility-rule-bulk-actions-bar"
            bulk-action-label="{{ __('screen_data_visibility_rules.bulk_action') }}"
            toolbar-actions-class="screen-visibility-rule-toolbar-actions"
        />
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table class="table table-sm table-hover mb-0 data-table erp-datatable align-middle js-screen-visibility-rules-table"
                            id="screen-visibility-rules-table"
                            data-url="{{ route('admin.screen-data-visibility-rules.data', array_filter(['user' => request('user')])) }}"
                            data-bulk-delete-url="{{ route('admin.screen-data-visibility-rules.bulk-delete') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="no-sort all no-colvis dt-select" data-orderable="false" style="width:2.25rem">
                                        <div class="form-check mb-0 d-flex justify-content-center"><x-forms.input class="form-check-input js-record-select-all" id="select_all_records" type="checkbox" aria-label="{{ __('screen_data_visibility_rules.select_all') }}" /></div>
                                    </th>
                                    <th class="sort all no-colvis dt-code">{{ __('screen_data_visibility_rules.attributes.doc_num') }}</th>
                                    <th class="sort dt-text">{{ __('screen_data_visibility_rules.attributes.user_doc_num') }}</th>
                                    <th class="sort dt-text">{{ __('screen_data_visibility_rules.attributes.company') }}</th>
                                    <th class="sort dt-text">{{ __('screen_data_visibility_rules.attributes.module') }}</th>
                                    <th class="sort dt-text">{{ __('screen_data_visibility_rules.attributes.screen_key') }}</th>
                                    <th class="sort dt-text">{{ __('screen_data_visibility_rules.attributes.record_scope') }}</th>
                                    <th class="sort dt-number">{{ __('screen_data_visibility_rules.attributes.max_visible_records') }}</th>
                                    <th class="sort dt-text">{{ __('screen_data_visibility_rules.attributes.duration') }}</th>
                                    <th class="sort dt-status">{{ __('screen_data_visibility_rules.attributes.is_active') }}</th>
                                    <th class="sort dt-text">{{ __('common.fields.created_by') }}</th>
                                    <th class="sort dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="sort dt-text">{{ __('common.fields.updated_by') }}</th>
                                    <th class="sort dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="no-sort all no-colvis dt-actions"></th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    @php
        $screenDataVisibilityRuleMessages = [
            'deleteConfirmTitle' => __('screen_data_visibility_rules.messages.delete_confirm_title'),
            'deleteConfirmText' => __('screen_data_visibility_rules.messages.delete_confirm_text'),
            'deleteConfirmYes' => __('screen_data_visibility_rules.messages.delete_confirm_yes'),
            'bulkDeleteConfirmTitle' => __('screen_data_visibility_rules.messages.bulk_delete_confirm_title'),
            'bulkDeleteConfirmText' => __('screen_data_visibility_rules.messages.bulk_delete_confirm_text'),
            'bulkDeleteConfirmYes' => __('screen_data_visibility_rules.messages.bulk_delete_confirm_yes'),
            'restoreConfirmTitle' => __('screen_data_visibility_rules.messages.restore_confirm_title'),
            'restoreConfirmText' => __('screen_data_visibility_rules.messages.restore_confirm_text'),
            'restoreConfirmYes' => __('screen_data_visibility_rules.messages.restore_confirm_yes'),
            'noRowsSelected' => __('screen_data_visibility_rules.messages.no_rows_selected'),
            'unexpectedError' => __('auth.ajax.unexpected_error'),
            'no' => __('common.actions.no'),
        ];
    @endphp
    <script>
        window.screenDataVisibilityRuleMessages = @json($screenDataVisibilityRuleMessages);
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Auth/screen-data-visibility-rules.js') }}"></script>
@endpush
