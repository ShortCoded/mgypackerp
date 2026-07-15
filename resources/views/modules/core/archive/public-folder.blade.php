<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('languages.available.'.app()->getLocale().'.dir', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $rootFolder->name }}</title>
    @php($isRtl = config('languages.available.'.app()->getLocale().'.dir', 'ltr') === 'rtl')
    <link href="{{ asset($isRtl ? 'assets/css/theme-rtl.min.css' : 'assets/css/theme.min.css') }}" rel="stylesheet">
    <link href="{{ asset($isRtl ? 'assets/css/user-rtl.min.css' : 'assets/css/user.min.css') }}" rel="stylesheet">
    <script defer src="{{ asset('vendors/fontawesome/all.min.js') }}"></script>
</head>
<body>
    <main class="main">
        <div class="container py-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-2">{{ $rootFolder->name }}</h5>
                    <nav aria-label="{{ __('archive.folders') }}">
                        <ol class="breadcrumb mb-0" style="--falcon-breadcrumb-divider: '/';">
                            @foreach ($folderBreadcrumbs as $breadcrumbFolder)
                                @php($isCurrent = $currentFolder->is($breadcrumbFolder))
                                <li class="breadcrumb-item {{ $isCurrent ? 'active' : '' }}" @if ($isCurrent) aria-current="page" @endif>
                                    @if ($isCurrent)
                                        {{ $breadcrumbFolder->name }}
                                    @else
                                        <a href="{{ route('public.archive.folders.show', ['token' => $token, 'folder' => $breadcrumbFolder->doc_num]) }}">{{ $breadcrumbFolder->name }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    </nav>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th>{{ __('common.fields.name') }}</th>
                                    <th>{{ __('archive.type') }}</th>
                                    <th>{{ __('archive.size') }}</th>
                                    <th class="text-end">{{ __('common.fields.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($folders as $folder)
                                    <tr>
                                        <td>
                                            <a class="fw-semibold text-decoration-none" href="{{ route('public.archive.folders.show', ['token' => $token, 'folder' => $folder->doc_num]) }}">
                                                {{ $folder->name }}
                                            </a>
                                            <div class="fs-11 text-600" dir="ltr">{{ $folder->doc_num }}</div>
                                        </td>
                                        <td><span class="badge rounded-pill badge-subtle-warning">{{ __('archive.folder') }}</span></td>
                                        <td>{{ __('common.messages.not_available') }}</td>
                                        <td class="text-end">
                                            <a class="btn btn-falcon-default btn-sm" href="{{ route('public.archive.folders.show', ['token' => $token, 'folder' => $folder->doc_num]) }}">{{ __('archive.preview') }}</a>
                                        </td>
                                    </tr>
                                @empty
                                @endforelse
                                @forelse ($files as $file)
                                    <tr>
                                        <td>
                                            <span class="fw-semibold">{{ $file->original_name }}</span>
                                            <div class="fs-11 text-600" dir="ltr">{{ $file->doc_num }}</div>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill badge-subtle-info">{{ $file->extension ? mb_strtoupper($file->extension) : __('archive.file') }}</span>
                                        </td>
                                        <td>{{ number_format(((int) $file->size_bytes) / 1024, 1) }} KiB</td>
                                        <td class="text-end">
                                            @if ($file->isPreviewable())
                                                <a class="btn btn-falcon-default btn-sm" href="{{ route('public.archive.folders.files.preview', [$token, $file->doc_num]) }}" target="_blank" rel="noopener">{{ __('archive.preview') }}</a>
                                            @endif
                                            @if ($allowDownload)
                                                <a class="btn btn-falcon-primary btn-sm" href="{{ route('public.archive.folders.files.download', [$token, $file->doc_num]) }}">{{ __('archive.download') }}</a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                @endforelse
                                @if ($folders->isEmpty() && $files->isEmpty())
                                    <tr>
                                        <td colspan="4" class="text-center text-600 py-4">{{ __('archive.empty') }}</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>
