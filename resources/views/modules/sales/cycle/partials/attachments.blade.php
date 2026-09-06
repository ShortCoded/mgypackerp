@can('file_manager.view')
@php($documentFiles = ($attachmentRecord ?? null)?->exists ? \Modules\Core\Models\ArchiveFileUsage::query()->whereMorphedTo('usable', $attachmentRecord)->where('collection', 'sales_documents')->with('file')->get() : collect())
@if($documentFiles->isNotEmpty() || !($attachmentsReadonly ?? false))
<section class="card mb-3"><div class="card-header"><h6 class="mb-0">{{ __('Attachments') }}</h6></div><div class="card-body">
    @foreach($documentFiles as $usage)
        @if($usage->file)<div class="mb-2"><a href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}">{{ $usage->file->original_name }}</a></div>@endif
    @endforeach
    @unless($attachmentsReadonly ?? false)
        <form method="POST" class="js-sales-cycle-action" action="{{ route('admin.sales.document-attachments.store', [$attachmentKind, $attachmentRecord->doc_num]) }}">@csrf
        <button type="button" class="btn btn-falcon-default btn-sm js-sales-attachment-picker" data-remove-label="{{ __('Remove attachment') }}" data-file-picker data-picker-accept="document" data-picker-max="1" data-picker-title="{{ __('Choose attachment') }}" data-picker-collection="sales_documents" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"><span class="fas fa-paperclip me-1"></span>{{ __('Choose attachment') }}</button>
        <div class="js-sales-attachment-inputs"></div><ul class="list-group list-group-flush mt-2 js-sales-selected-attachments d-none"></ul>
        <button class="btn btn-primary btn-sm mt-2" type="submit">{{ __('Save attachments') }}</button></form>
        <x-file-picker-modal />
    @endunless
</div></section>
@endif
@pushOnce('scripts', 'sales-attachments')
<script src="{{ asset('assets/js/modules/Sales/sales-attachments.js').'?v='.filemtime(public_path('assets/js/modules/Sales/sales-attachments.js')) }}"></script>
@endPushOnce
@endcan
