@php
    $settings = app(\Modules\Core\Services\SettingService::class);
    $fileSize = $document->size ? number_format(((int) $document->size) / 1024, 1).' KB' : __('common.empty_value');
    $documentType = $document->documentType?->name
        ?: (($document->document_type ?: null) ? __('hr.employees.documents.types.' . ($document->document_type ?: 'other')) : __('common.empty_value'));
    $archiveFile = $document->archiveFile;
@endphp

<tr data-document-doc-num="{{ $document->doc_num }}">
    <td class="white-space-nowrap">{{ $documentType }}</td>
    <td class="text-700">{{ $document->original_name }}</td>
    <td class="white-space-nowrap">{{ $document->file_label ?: __('common.empty_value') }}</td>
    <td class="white-space-nowrap">{{ $settings->formatDate($document->issue_date, '') }}</td>
    <td class="white-space-nowrap">{{ $fileSize }}</td>
    <td class="white-space-nowrap">{{ $settings->formatDate($document->expires_at, '') }}</td>
    <td class="white-space-nowrap">{{ $document->alert_before_expiry_days ?? __('common.empty_value') }}</td>
    <td>{{ $document->notes ?: __('common.empty_value') }}</td>
    <td class="white-space-nowrap text-end">
        @can('hr.employees.documents.view')
            @if ($archiveFile?->isPreviewable())
                <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.preview', $archiveFile->doc_num) }}" target="_blank" rel="noopener" title="{{ __('hr.employees.documents.actions.preview') }}" data-bs-title="{{ __('hr.employees.documents.actions.preview') }}">
                    <span class="fas fa-eye"></span>
                </a>
            @endif
            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.hr.employees.documents.download', [$employee->doc_num, $document->doc_num]) }}" title="{{ __('hr.employees.documents.actions.download') }}" data-bs-title="{{ __('hr.employees.documents.actions.download') }}">
                <span class="fas fa-download"></span>
            </a>
        @endcan
        @if (! $isView && ! $employee->trashed())
            @can('hr.employees.documents.delete')
                <button type="button" class="btn btn-falcon-danger btn-sm" data-hr-employee-document-delete-url="{{ route('admin.hr.employees.documents.destroy', [$employee->doc_num, $document->doc_num]) }}" data-document-doc-num="{{ $document->doc_num }}" title="{{ __('common.actions.delete') }}" data-bs-title="{{ __('common.actions.delete') }}">
                    <span class="fas fa-trash-alt"></span>
                </button>
            @endcan
        @endif
    </td>
</tr>
