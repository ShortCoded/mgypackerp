@php
    $settings = app(\Modules\Core\Services\SettingService::class);
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $fileSize = $document->size ? number_format(((int) $document->size) / 1024, 1).' KB' : __('common.empty_value');
    $documentType = $document->documentType?->name
        ?: (($document->document_type ?: null) ? __('hr.employees.documents.types.'.($document->document_type ?: 'other')) : __('common.empty_value'));
    $archiveFile = $document->archiveFile;
    $downloadRoute = $employee->trashed() ? 'admin.hr.employees.trashed.documents.download' : 'admin.hr.employees.documents.download';
    $employeeRouteKey = $employee->trashed() ? $employee->public_uuid : $employee->doc_num;
@endphp

<article class="card border hr-document-card js-hr-employee-document-card" data-document-doc-num="{{ $document->doc_num }}">
    <div class="card-header py-2 px-3 bg-body-tertiary d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h6 class="mb-0">
                {{ isset($documentSequence) ? __('hr.employees.documents.item_title', ['number' => $documentSequence]) : ($document->title ?: $documentType) }}
            </h6>
            <small class="text-600">{{ $documentType }}</small>
        </div>
        @can('hr.employees.documents.view')
            <div class="d-flex flex-wrap gap-2">
                @can('file_manager.view')
                    @if ($archiveFile?->isPreviewable())
                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.preview', $archiveFile->doc_num) }}" target="_blank" rel="noopener" title="{{ __('hr.employees.documents.actions.preview') }}" data-bs-title="{{ __('hr.employees.documents.actions.preview') }}">
                            <span class="fas fa-eye me-1"></span>{{ __('hr.employees.documents.actions.preview') }}
                        </a>
                    @endif
                @endcan
                <a class="btn btn-falcon-default btn-sm" href="{{ route($downloadRoute, [$employeeRouteKey, $document->doc_num]) }}" title="{{ __('hr.employees.documents.actions.download') }}" data-bs-title="{{ __('hr.employees.documents.actions.download') }}">
                    <span class="fas fa-download me-1"></span>{{ __('hr.employees.documents.actions.download') }}
                </a>
                @if (! $isView && ! $employee->trashed())
                    @can('hr.employees.documents.delete')
                        <button type="button" class="btn btn-falcon-danger btn-sm" data-hr-employee-document-delete-url="{{ route('admin.hr.employees.documents.destroy', [$employee->doc_num, $document->doc_num]) }}" data-document-doc-num="{{ $document->doc_num }}" title="{{ __('common.actions.delete') }}" data-bs-title="{{ __('common.actions.delete') }}">
                            <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
                        </button>
                    @endcan
                @endif
            </div>
        @endcan
    </div>
    <div class="card-body p-3">
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.document_number_text') }}</div>
                <div>{{ $document->document_number_text ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.title') }}</div>
                <div>{{ $document->title ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.original_name') }}</div>
                <div class="text-break">{{ $document->original_name ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.issue_date') }}</div>
                <div>{{ $settings->formatDate($document->issue_date, '') ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.expires_at') }}</div>
                <div>{{ $settings->formatDate($document->expires_at, '') ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.alert_before_expiry_days') }}</div>
                <div dir="ltr">{{ $document->alert_before_expiry_days === null ? __('common.empty_value') : $numbers->format($document->alert_before_expiry_days) }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.file_label') }}</div>
                <div>{{ $document->file_label ?: __('common.empty_value') }}</div>
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="text-600 fs-10 mb-1">{{ __('common.fields.file_size') }}</div>
                <div dir="ltr">{{ $fileSize }}</div>
            </div>
            <div class="col-12">
                <div class="text-600 fs-10 mb-1">{{ __('hr.employees.documents.attributes.notes') }}</div>
                <div class="text-break">{{ $document->notes ?: __('common.empty_value') }}</div>
            </div>
        </div>
    </div>
</article>
