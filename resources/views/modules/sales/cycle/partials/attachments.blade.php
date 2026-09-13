@can('file_manager.view')
@php($documentFiles = ($attachmentRecord ?? null)?->exists ? \Modules\Core\Models\ArchiveFileUsage::query()->whereMorphedTo('usable', $attachmentRecord)->where('collection', 'sales_documents')->with('file')->get() : collect())
@if($documentFiles->isNotEmpty() || !($attachmentsReadonly ?? false))
<div class="card mb-3 erp-document-attachments-card" data-sales-attachments>
    <div class="card-body py-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2"><h6 class="mb-0">{{ __('Document attachments') }}</h6>@if($documentFiles->isNotEmpty())<span class="badge rounded-pill bg-200 text-700">{{ $documentFiles->count() }}</span>@endif</div>
            @unless($attachmentsReadonly ?? false)
                <form method="POST" class="js-sales-cycle-action d-flex flex-wrap gap-2" action="{{ route('admin.sales.document-attachments.store', [$attachmentKind, $attachmentRecord->doc_num]) }}">@csrf
                    <button type="button" class="btn btn-falcon-default btn-sm js-sales-attachment-picker" data-remove-label="{{ __('Remove attachment') }}" data-file-picker data-picker-accept="document" data-picker-max="20" data-picker-title="{{ __('Choose attachment') }}" data-picker-collection="sales_documents" data-picker-allow-upload="{{ auth()->user()?->can('file_manager.upload') ? 'true' : 'false' }}"><span class="fas fa-paperclip me-1"></span>{{ __('Choose attachment') }}</button>
                    <button class="btn btn-primary btn-sm" type="submit"><span class="fas fa-save me-1"></span>{{ __('Save attachments') }}</button>
                    <div class="js-sales-attachment-inputs"></div>
                </form>
            @endunless
        </div>
        @if($documentFiles->isNotEmpty())
            <div class="row g-2 mt-2">
                @foreach($documentFiles as $usage)
                    @if($usage->file)<div class="col-12 col-md-6 col-xl-4"><a class="border rounded-2 px-3 py-2 d-flex align-items-center gap-2 text-decoration-none h-100" href="{{ route('admin.file-manager.files.download', $usage->file->doc_num) }}"><span class="fas fa-file-alt text-primary"></span><span class="text-truncate" title="{{ $usage->file->original_name }}">{{ $usage->file->original_name }}</span><span class="fas fa-download text-500 ms-auto"></span></a></div>@endif
                @endforeach
            </div>
        @endif
        <ul class="list-group mt-2 mb-0 js-sales-selected-attachments d-none"></ul>
    </div>
</div>
@unless($attachmentsReadonly ?? false)
    <x-file-picker-modal />
@endunless
@endif
@pushOnce('scripts', 'sales-attachments')
<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Sales/sales-attachments.js') }}"></script>
@endPushOnce
@endcan
