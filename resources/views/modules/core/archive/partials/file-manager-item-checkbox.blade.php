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
    <x-forms.input class="form-check-input js-file-manager-select"
        type="checkbox"
        value="{{ $item->doc_num }}"
        data-doc-num="{{ $item->doc_num }}"
        data-item-type="{{ $item->item_type }}"
        data-is-trashed="{{ $isTrashed ? '1' : '0' }}"
        :data-file-doc-num="$item->item_type === 'file' ? $item->doc_num : null"
        :data-folder-doc-num="$item->item_type === 'folder' ? $item->doc_num : null"
        aria-label="{{ __('archive.select_item') }}" />
</div>
@endif
