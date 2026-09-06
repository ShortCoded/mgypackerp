@can('file_manager.view')
@php
    $attachmentCollection = $attachmentCollection ?? \Modules\Purchases\Services\ProcurementAttachmentService::OperationalCollection;
    $documentFiles = ($attachmentRecord ?? null)?->exists
        ? app(\Modules\Purchases\Services\ProcurementAttachmentService::class)->documents($attachmentRecord, $attachmentCollection)
        : collect();
@endphp
@if($documentFiles->isNotEmpty() || !($attachmentsReadonly ?? false))
<section class="card mb-3 js-procurement-attachment-scope"><div class="card-header py-2"><h6 class="mb-0">{{ __('Attachments') }}</h6></div><div class="card-body py-2">
    @if($documentFiles->isNotEmpty())<div class="list-group list-group-flush mb-3">
        @foreach($documentFiles as $usage)
            @if($usage->file)<div class="list-group-item px-0 d-flex flex-wrap align-items-center justify-content-between gap-2"><div><span class="fas fa-paperclip text-500 me-1"></span>{{ $usage->file->original_name }} <span class="text-600 fs-11" dir="ltr">{{ $usage->file->doc_num }}</span></div><div class="d-flex gap-2">@if($usage->file->isPreviewable())<a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.file-manager.files.preview', $usage->file->doc_num) }}">{{ __('Preview') }}</a>@endif @can('file_manager.download')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}">{{ __('Download') }}</a>@endcan</div></div>@endif
        @endforeach
    </div>@endif
    @unless($attachmentsReadonly ?? false)
        <button type="button" class="btn btn-falcon-default btn-sm js-procurement-attachment-picker" data-input-name="attachment_file_doc_nums[]" data-remove-label="{{ __('Remove attachment') }}" data-file-picker data-picker-accept="document" data-picker-max="20" data-picker-title="{{ __('Choose attachment') }}" data-picker-collection="{{ $attachmentCollection }}" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"><span class="fas fa-paperclip me-1"></span>{{ __('Choose attachment') }}</button>
        <div class="js-procurement-attachment-inputs"></div><ul class="list-group list-group-flush mt-2 js-procurement-selected-attachments d-none"></ul>
        @pushOnce('scripts', 'procurement-file-picker-modal')
            <x-file-picker-modal />
        @endPushOnce
    @endunless
</div></section>
@endif
@pushOnce('scripts', 'core-file-picker')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Core/file-picker.js') }}"></script>
@endPushOnce
@pushOnce('scripts', 'procurement-attachments')
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Purchases/procurement-attachments.js') }}"></script>
@endPushOnce
@endcan
