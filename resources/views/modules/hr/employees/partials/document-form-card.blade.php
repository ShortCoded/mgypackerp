@php
    $document = $document ?? null;
    $documentIndex = (string) $documentIndex;
    $documentType = $document?->documentType;
    $archiveFile = $document?->archiveFile;
    $documentTypeText = $documentType ? trim(implode(' / ', array_filter([$documentType->name, $documentType->doc_num]))) : '';
    $archiveFileName = $archiveFile?->original_name ?? $document?->original_name ?? '';
    $hasPersistedDocument = (bool) $document?->getKey();
    $hasAttachment = $archiveFileName !== '';
    $itemNumber = is_numeric($documentIndex) ? ((int) $documentIndex + 1) : '__NUMBER__';
@endphp

<article class="card border hr-document-card js-hr-document-row" data-document-index="{{ $documentIndex }}">
    <div class="card-header py-2 px-3 bg-body-tertiary d-flex align-items-center justify-content-between gap-3">
        <div class="min-w-0">
            <h6 class="mb-0 js-hr-document-sequence">{{ __('hr.employees.documents.item_title', ['number' => $itemNumber]) }}</h6>
            @if ($hasPersistedDocument)
                <small class="text-600">{{ $document->title ?: $archiveFileName }}</small>
            @endif
        </div>
        @if (! $hasPersistedDocument || $canDeleteDocuments)
            <button type="button" class="btn btn-falcon-danger btn-sm flex-shrink-0 js-hr-document-remove" title="{{ __('common.actions.delete') }}" data-bs-title="{{ __('common.actions.delete') }}">
                <span class="fas fa-trash-alt me-1"></span>{{ __('common.actions.delete') }}
            </button>
        @endif
    </div>

    <div class="card-body p-3">
        @if ($hasPersistedDocument)
            <input type="hidden" name="documents[{{ $documentIndex }}][id]" value="{{ $document->getKey() }}">
        @endif
        <input type="hidden" name="documents[{{ $documentIndex }}][_delete]" value="0" class="js-hr-document-delete-flag">
        <input type="hidden" name="documents[{{ $documentIndex }}][sort_order]" value="{{ $document?->sort_order ?? (is_numeric($documentIndex) ? $documentIndex : 0) }}">

        <div class="row g-3 align-items-start">
            <div class="col-12 col-md-6 col-lg-4">
                <x-forms.label :for="'hr-document-type-'.$documentIndex" :label="__('hr.employees.documents.attributes.document_type_doc_num')" required />
                <div class="hr-select2-inline-control">
                    <select id="hr-document-type-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][document_type_doc_num]" class="form-select js-select2-ajax" data-url="{{ $documentTypeSelect['url'] }}" data-placeholder="{{ __('hr.employees.documents.attributes.document_type_doc_num') }}" data-allow-clear="true" required>
                        @if ($documentType)
                            <option value="{{ $documentType->doc_num }}" selected>{{ $documentTypeText }}</option>
                        @endif
                    </select>
                    @if (($documentTypeSelect['can_create'] ?? false) && ($documentTypeSelect['create_url'] ?? null))
                        <a class="btn btn-falcon-default btn-sm" href="{{ $documentTypeSelect['create_url'] }}" target="_blank" rel="noopener" title="{{ __('hr.inline_lookup.add_new') }}" data-bs-title="{{ __('hr.inline_lookup.add_new') }}">
                            <span class="fas fa-plus"></span>
                            <span class="visually-hidden">{{ __('hr.inline_lookup.add_new') }}</span>
                        </a>
                    @endif
                </div>
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.document_type_doc_num"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label" for="hr-document-number-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.document_number_text') }}</label>
                <input id="hr-document-number-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][document_number_text]" type="text" class="form-control" maxlength="120" value="{{ $document?->document_number_text }}">
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.document_number_text"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label" for="hr-document-title-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.title') }}</label>
                <input id="hr-document-title-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][title]" type="text" class="form-control js-hr-document-title" maxlength="255" value="{{ $document?->title }}">
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.title"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label" for="hr-document-issue-date-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.issue_date') }}</label>
                <input id="hr-document-issue-date-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][issue_date]" type="text" class="form-control js-date-picker" value="{{ $documentDateValue($document, 'issue_date') }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.issue_date"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label" for="hr-document-expiry-date-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.expires_at') }}</label>
                <input id="hr-document-expiry-date-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][expires_at]" type="text" class="form-control js-date-picker" value="{{ $documentDateValue($document, 'expires_at') }}" placeholder="{{ __('common.placeholders.select_date') }}" data-date-format="{{ $dateFormatService->jsDateFormat() }}">
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.expires_at"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label" for="hr-document-warning-days-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.alert_before_expiry_days') }}</label>
                <x-forms.numeric-input
                    :id="'hr-document-warning-days-'.$documentIndex"
                    :name="'documents['.$documentIndex.'][alert_before_expiry_days]'"
                    :value="$document?->alert_before_expiry_days"
                    :scale="0"
                    min="0"
                    max="3650"
                    step="1"
                    class="text-center"
                />
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.alert_before_expiry_days"></div>
            </div>

            <div class="col-12 col-lg-8">
                <x-forms.label :for="'hr-document-file-display-'.$documentIndex" :label="__('hr.employees.documents.attributes.archive_file_doc_num')" required />
                <div class="hr-document-attachment-panel">
                    <input id="hr-document-file-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][archive_file_doc_num]" type="hidden" value="{{ $archiveFile?->doc_num ?? '' }}" class="js-hr-document-file-input">
                    <div class="input-group hr-document-file-control">
                        <span class="input-group-text"><span class="fas fa-paperclip"></span></span>
                        <input id="hr-document-file-display-{{ $documentIndex }}" type="text" class="form-control js-hr-document-file-display" value="{{ $archiveFileName }}" placeholder="{{ __('hr.employees.documents.no_file_selected') }}" readonly>
                        @can('file_manager.view')
                            <button type="button"
                                class="btn btn-falcon-default js-hr-document-file-picker"
                                data-file-picker
                                data-picker-accept="document"
                                data-picker-title="{{ __('hr.employees.actions.select_document_file') }}"
                                data-picker-target-input="#hr-document-file-{{ $documentIndex }}"
                                data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
                                data-picker-allow-create-folder="{{ auth()->user()?->can('file_manager.folders.create') ? 'true' : 'false' }}">
                                <span class="js-hr-document-file-picker-label">{{ $hasAttachment ? __('hr.employees.documents.actions.replace') : __('hr.employees.actions.select_document_file') }}</span>
                            </button>
                        @endcan
                        <button type="button" class="btn btn-falcon-default text-danger js-hr-document-file-clear @if (! $hasAttachment) d-none @endif" title="{{ __('hr.employees.documents.actions.remove_attachment') }}" data-bs-title="{{ __('hr.employees.documents.actions.remove_attachment') }}">
                            <span class="fas fa-times"></span>
                        </button>
                    </div>
                    @if ($hasPersistedDocument && $canViewDocuments)
                        <div class="mt-2 d-flex flex-wrap gap-2 js-hr-document-existing-actions @if (! $hasAttachment) d-none @endif">
                            @can('file_manager.view')
                                @if ($archiveFile?->isPreviewable())
                                    <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.preview', $archiveFile->doc_num) }}" target="_blank" rel="noopener">
                                        <span class="fas fa-eye me-1"></span>{{ __('hr.employees.documents.actions.preview') }}
                                    </a>
                                @endif
                            @endcan
                            <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.hr.employees.documents.download', [$employee->doc_num, $document->doc_num]) }}">
                                <span class="fas fa-download me-1"></span>{{ __('hr.employees.documents.actions.download') }}
                            </a>
                        </div>
                    @endif
                </div>
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.archive_file_doc_num"></div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label" for="hr-document-file-label-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.file_label') }}</label>
                <input id="hr-document-file-label-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][file_label]" type="text" class="form-control" maxlength="80" value="{{ $document?->file_label }}">
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.file_label"></div>
            </div>

            <div class="col-12">
                <label class="form-label" for="hr-document-notes-{{ $documentIndex }}">{{ __('hr.employees.documents.attributes.notes') }}</label>
                <textarea id="hr-document-notes-{{ $documentIndex }}" name="documents[{{ $documentIndex }}][notes]" class="form-control" rows="2">{{ $document?->notes }}</textarea>
                <div class="invalid-feedback d-block" data-error-for="documents.{{ $documentIndex }}.notes"></div>
            </div>
        </div>
    </div>
</article>
