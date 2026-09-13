@extends('layouts.app')

@section('title', __('journal_entries.title'))

@section('content')
    <div class="card erp-datatable-card" data-journal-entries-index>
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-auto">
                    <h5 class="mb-0">{{ __('journal_entries.title') }}</h5>
                </div>
                <div class="col-auto ms-auto d-flex align-items-center gap-2">
                    @can('journal_entries.view_trashed')
                        <label class="form-label mb-0 fs-10" for="journal_entries_trash_filter">{{ __('journal_entries.filters.records') }}</label>
                        <x-forms.select class="form-select form-select-sm w-auto" id="journal_entries_trash_filter">
                            <option value="active">{{ __('journal_entries.filters.active') }}</option>
                            <option value="trashed">{{ __('journal_entries.filters.trashed') }}</option>
                            <option value="all">{{ __('journal_entries.filters.all') }}</option>
                        </x-forms.select>
                    @endcan
                    <x-buttons.add-record :href="route('admin.accounting.journal-entries.create')" permission="journal_entries.create" />
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="journal-entries-table" class="table table-sm table-hover mb-0 data-table erp-datatable erp-datatable-wide erp-datatable-sticky-columns align-middle"
                            data-url="{{ route('admin.accounting.journal-entries.data') }}">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="no-sort no-colvis dt-select"></th>
                                    <th class="dt-code no-colvis">{{ __('journal_entries.attributes.doc_num') }}</th>
                                    <th class="dt-date">{{ __('journal_entries.attributes.entry_date') }}</th>
                                    <th class="dt-code">{{ __('journal_entries.attributes.reference_no') }}</th>
                                    <th>{{ __('journal_entries.attributes.status') }}</th>
                                    <th>{{ __('journal_entries.attributes.origin') }}</th>
                                    <th class="dt-number">{{ __('journal_entries.attributes.total_debit') }}</th>
                                    <th class="dt-number">{{ __('journal_entries.attributes.total_credit') }}</th>
                                    <th class="dt-text">{{ __('common.fields.created_by') }}</th>
                                    <th class="dt-date">{{ __('common.fields.created_at') }}</th>
                                    <th class="dt-text">{{ __('common.fields.updated_by') }}</th>
                                    <th class="dt-date">{{ __('common.fields.updated_at') }}</th>
                                    <th class="no-sort no-colvis dt-actions"></th>
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
    <script>
        window.journalEntryMessages = @json(__('journal_entries.js'));
        window.dataTableTranslations = @json(__('datatables'));
    </script>
    <script src="{{ asset('vendors/sweetalert2/sweetalert2.all.min.js') }}"></script>
    <script src="{{ asset('assets/js/modules/Accounting/journal-entries.js') }}"></script>
@endpush
