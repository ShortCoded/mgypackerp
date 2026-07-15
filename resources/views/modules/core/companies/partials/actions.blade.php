@php
    $isTrashed = $company->trashed();
    $canView = auth()->user()?->can('companies.view') && $company->doc_num !== null;
    $canRestore = $isTrashed
        && auth()->user()?->can('companies.restore')
        && $company->doc_num !== null;
    $canCreateCompany = $canCreateCompany ?? true;
    $canClone = ! $isTrashed
        && auth()->user()?->can('companies.clone')
        && $canCreateCompany
        && $company->doc_num !== null;
@endphp

@if ((! $isTrashed && ($canView || auth()->user()?->can('companies.edit') || $canClone || auth()->user()?->can('companies.delete'))) || ($isTrashed && ($canView || $canRestore)))
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($isTrashed)
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.companies.show', $company->doc_num) }}" data-doc-num="{{ $company->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @if ($canRestore)
                    @if ($canView)
                        <div class="dropdown-divider"></div>
                    @endif
                    <button type="button" class="dropdown-item text-success js-restore-record" data-doc-num="{{ $company->doc_num }}" data-record-name="{{ $company->name }}" data-restore-url="{{ route('admin.companies.restore', $company->doc_num) }}" data-company-restore-url="{{ route('admin.companies.restore', $company->doc_num) }}">
                        {{ __('companies.trash.restore') }}
                    </button>
                @endif
            @else
                @if ($canView)
                    <a class="dropdown-item" href="{{ route('admin.companies.show', $company->doc_num) }}" data-doc-num="{{ $company->doc_num }}">
                        {{ __('common.actions.view') }}
                    </a>
                @endif
                @can('companies.edit')
                    <a class="dropdown-item js-edit-record" href="{{ route('admin.companies.edit', $company->doc_num) }}" data-doc-num="{{ $company->doc_num }}">
                        {{ __('common.actions.edit') }}
                    </a>
                @endcan
                @if ($canClone)
                    <a class="dropdown-item js-clone-record" href="{{ route('admin.companies.clone', $company->doc_num) }}" data-doc-num="{{ $company->doc_num }}">
                        {{ __('common.actions.clone_record') }}
                    </a>
                @endif
                @can('companies.delete')
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item text-danger" data-doc-num="{{ $company->doc_num }}" data-company-delete-url="{{ route('admin.companies.destroy', $company->doc_num) }}">
                        {{ __('common.actions.delete') }}
                    </button>
                @endcan
            @endif
        </div>
    </div>
@endif
