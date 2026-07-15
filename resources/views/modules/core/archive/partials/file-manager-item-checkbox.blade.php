@php
    $trashFilter = $trashFilter ?? 'active';
    $isTrashed = (bool) ($item->is_trashed ?? false);
    $canSelect = match ($trashFilter) {
        'trashed' => $isTrashed && $item->item_type === 'file',
        'all' => false,
        default => ! $isTrashed,
    };
@endphp

@if ($canSelect)
<div class="form-check mb-0 d-flex align-items-center justify-content-center">
    <input class="form-check-input js-file-manager-select"
        type="checkbox"
        value="{{ $item->doc_num }}"
        data-doc-num="{{ $item->doc_num }}"
        data-item-type="{{ $item->item_type }}"
        data-is-trashed="{{ $isTrashed ? '1' : '0' }}"
        @if ($item->item_type === 'file') data-file-doc-num="{{ $item->doc_num }}" @endif
        @if ($item->item_type === 'folder') data-folder-doc-num="{{ $item->doc_num }}" @endif
        aria-label="{{ __('archive.select_item') }}">
</div>
@endif
