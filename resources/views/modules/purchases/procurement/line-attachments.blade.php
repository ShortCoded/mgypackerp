@can('file_manager.view')
    @php
        $attachmentLine = $attachmentLine ?? null;
        $lineAttachmentsReadonly = $lineAttachmentsReadonly ?? false;
        $attachmentCompanyId = (int) ($attachmentCompanyId ?? $attachmentLine?->company_id ?? 0);
        $lineFiles = $attachmentLine?->exists
            ? app(\Modules\Purchases\Services\ProcurementAttachmentService::class)->documents(
                $attachmentLine,
                \Modules\Purchases\Services\ProcurementAttachmentService::LineCollection,
                $attachmentCompanyId,
            )
            : collect();
        $lineAttachmentInputName = $lineAttachmentInputName ?? 'lines['.$index.'][attachment_file_doc_nums][]';
    @endphp

    <div class="js-procurement-attachment-scope procurement-line-attachments">
        @if ($lineFiles->isNotEmpty())
            <div class="d-flex flex-column gap-1 mb-1 js-procurement-existing-attachments">
                @foreach ($lineFiles as $usage)
                    @if ($usage->file)
                        <div class="d-flex align-items-center gap-1 small">
                            <span class="fas fa-paperclip text-500" aria-hidden="true"></span>
                            @if ($usage->file->isPreviewable())
                                <a target="_blank" rel="noopener" href="{{ route('admin.file-manager.files.preview', $usage->file->doc_num) }}">{{ $usage->file->original_name }}</a>
                            @elseif (auth()->user()?->can('file_manager.download'))
                                <a href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}">{{ $usage->file->original_name }}</a>
                            @else
                                <span>{{ $usage->file->original_name }}</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        @elseif ($lineAttachmentsReadonly)
            <span class="text-500">{{ __('No attachments') }}</span>
        @endif

        @unless ($lineAttachmentsReadonly)
            <button
                type="button"
                class="btn btn-link btn-sm p-0 js-procurement-attachment-picker"
                data-input-name="{{ $lineAttachmentInputName }}"
                data-remove-label="{{ __('Remove attachment') }}"
                data-file-picker
                data-picker-accept="document"
                data-picker-max="10"
                data-picker-title="{{ __('Choose line attachment') }}"
                data-picker-collection="{{ \Modules\Purchases\Services\ProcurementAttachmentService::LineCollection }}"
                data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"
            >
                <span class="fas fa-paperclip me-1" aria-hidden="true"></span>{{ __('Attach') }}
            </button>
            <div class="js-procurement-attachment-inputs"></div>
            <ul class="list-group list-group-flush mt-1 js-procurement-selected-attachments d-none"></ul>
        @endunless
    </div>
@endcan
