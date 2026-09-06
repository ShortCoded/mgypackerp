@can('file_manager.view')
@php($documentFiles = ($attachmentRecord ?? null)?->exists ? app(\Modules\Purchases\Services\ProcurementAttachmentService::class)->documents($attachmentRecord) : collect())
@if($documentFiles->isNotEmpty() || !($attachmentsReadonly ?? false))
<section class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Attachments') }}</h6></div><div class="card-body">
    @foreach($documentFiles as $usage)
        @if($usage->file)<div class="mb-2">@can('file_manager.download')<a href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}">{{ $usage->file->original_name }}</a>@else{{ $usage->file->original_name }}@endcan</div>@endif
    @endforeach
    @unless($attachmentsReadonly ?? false)
        <button type="button" class="btn btn-falcon-default btn-sm js-procurement-attachment-picker" data-remove-label="{{ __('Remove attachment') }}" data-file-picker data-picker-accept="document" data-picker-max="1" data-picker-title="{{ __('Choose attachment') }}" data-picker-collection="procurement_documents" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"><span class="fas fa-paperclip me-1"></span>{{ __('Choose attachment') }}</button>
        <div class="js-procurement-attachment-inputs"></div><ul class="list-group list-group-flush mt-2 js-procurement-selected-attachments d-none"></ul>
        <x-file-picker-modal />
    @endunless
</div></section>
@endif
@pushOnce('scripts', 'procurement-attachments')
<script src="{{ asset('assets/js/modules/Purchases/procurement-attachments.js').'?v='.filemtime(public_path('assets/js/modules/Purchases/procurement-attachments.js')) }}"></script>
@endPushOnce
@endcan
