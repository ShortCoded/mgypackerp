@can('branches.delete')
    @unless ($branch->trashed())
        <div class="form-check mb-0 d-flex align-items-center justify-content-center">
            <x-forms.input class="form-check-input js-record-select js-branch-row-checkbox" type="checkbox" value="{{ $branch->doc_num }}" data-doc-num="{{ $branch->doc_num }}" aria-label="{{ __('branches.select_record', ['record' => $branch->name]) }}" />
        </div>
    @endunless
@endcan
