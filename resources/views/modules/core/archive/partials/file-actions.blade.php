@php
    $canCreatePublicLink = (bool) ($canCreatePublicLink ?? false);
    $canViewPublicLink = (bool) ($canViewPublicLink ?? false);
    $canRevokePublicLink = (bool) ($canRevokePublicLink ?? false);
    $canManagePublicLink = $canCreatePublicLink || $canViewPublicLink || $canRevokePublicLink;
@endphp

@if ($file->isPreviewable() || $canDownload || $canDelete || $canManagePublicLink)
    <div class="dropstart font-sans-serif position-static d-inline-block">
        <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal float-end" type="button" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-reference="parent" aria-haspopup="true" aria-expanded="false" aria-label="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h fs-10"></span>
        </button>
        <div class="py-2 border dropdown-menu dropdown-menu-end">
            @if ($file->isPreviewable())
                <a class="dropdown-item" href="{{ $previewUrl }}" target="_blank" rel="noopener">
                    <span class="fas fa-eye me-2"></span>{{ __('archive.preview') }}
                </a>
            @endif
            @if ($canDownload)
                <a class="dropdown-item" href="{{ $downloadUrl }}">
                    <span class="fas fa-download me-2"></span>{{ __('archive.download') }}
                </a>
            @endif
            @if ($canManagePublicLink)
                <div class="dropdown-divider"></div>
                @if ($canCreatePublicLink)
                    <button type="button"
                        class="dropdown-item js-archive-public-link-create"
                        data-public-link-create-url="{{ $publicLinkCreateUrl ?? '' }}"
                        data-public-link-show-url="{{ $publicLinkShowUrl ?? '' }}"
                        data-public-link-revoke-url="{{ $publicLinkRevokeUrl ?? '' }}"
                        data-public-link-item-name="{{ $file->original_name }}"
                        data-public-link-item-type="{{ __('archive.file') }}">
                        <span class="fas fa-link me-2"></span>{{ __('archive.public_links.create_or_copy') }}
                    </button>
                @endif
                @if ($canViewPublicLink || $canRevokePublicLink)
                    <button type="button"
                        class="dropdown-item js-archive-public-link-manage"
                        data-public-link-create-url="{{ $canCreatePublicLink ? ($publicLinkCreateUrl ?? '') : '' }}"
                        data-public-link-show-url="{{ $publicLinkShowUrl ?? '' }}"
                        data-public-link-revoke-url="{{ $canRevokePublicLink ? ($publicLinkRevokeUrl ?? '') : '' }}"
                        data-public-link-item-name="{{ $file->original_name }}"
                        data-public-link-item-type="{{ __('archive.file') }}">
                        <span class="fas fa-share-alt me-2"></span>{{ __('archive.public_links.manage') }}
                    </button>
                @endif
            @endif
            @if ($canDelete)
                <div class="dropdown-divider"></div>
                <button type="button" class="dropdown-item text-danger js-archive-file-delete" data-archive-delete-url="{{ $deleteUrl }}" data-doc-num="{{ $file->doc_num }}">
                    {{ __('archive.delete') }}
                </button>
            @endif
        </div>
    </div>
@endif
