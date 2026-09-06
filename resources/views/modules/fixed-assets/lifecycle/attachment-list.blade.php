@php($visibleUsages = collect($usages ?? [])->filter(fn ($usage) => $usage->file))

@if($visibleUsages->isEmpty())
    <div class="text-center text-600 py-3 px-2">
        <span class="fas fa-paperclip text-300 fs-5 mb-2" aria-hidden="true"></span>
        <p class="mb-0">{{ __('fixed_assets.product.no_documents') }}</p>
    </div>
@else
    <div class="list-group list-group-flush fa-attachment-list">
        @foreach($visibleUsages as $usage)
            <div class="list-group-item px-0 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-2">
                <div class="d-flex align-items-start gap-2 min-w-0">
                    <span class="fas fa-paperclip text-500 mt-1" aria-hidden="true"></span>
                    <div class="fa-attachment-file-name">
                        <div class="fw-semibold text-break">{{ $usage->file->original_name }}</div>
                        <div class="small text-600" dir="ltr">{{ $usage->file->doc_num }}</div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 fa-attachment-actions">
                    @can('file_manager.view')@if($usage->file->isPreviewable())
                        <a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.file-manager.files.preview', $usage->file) }}">
                            <span class="fas fa-eye me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.preview_document') }}
                        </a>
                    @endif @endcan
                    @can('file_manager.download')
                        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.file-manager.files.download', $usage->file) }}">
                            <span class="fas fa-download me-1" aria-hidden="true"></span>{{ __('fixed_assets.product.download_document') }}
                        </a>
                    @endcan
                </div>
            </div>
        @endforeach
    </div>
@endif
