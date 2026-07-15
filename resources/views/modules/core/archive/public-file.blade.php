<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('languages.available.'.app()->getLocale().'.dir', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $file->original_name }}</title>
    @php($isRtl = config('languages.available.'.app()->getLocale().'.dir', 'ltr') === 'rtl')
    <link href="{{ asset($isRtl ? 'assets/css/theme-rtl.min.css' : 'assets/css/theme.min.css') }}" rel="stylesheet">
    <link href="{{ asset($isRtl ? 'assets/css/user-rtl.min.css' : 'assets/css/user.min.css') }}" rel="stylesheet">
    <script defer src="{{ asset('vendors/fontawesome/all.min.js') }}"></script>
</head>
<body>
    <main class="main">
        <div class="container py-4">
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <div>
                        <h5 class="mb-1">{{ $file->original_name }}</h5>
                        <div class="fs-10 text-600" dir="ltr">{{ $file->doc_num }}</div>
                    </div>
                    @if ($downloadUrl)
                        <a class="btn btn-falcon-primary btn-sm" href="{{ $downloadUrl }}">
                            <span class="fas fa-download me-1"></span>{{ __('archive.download') }}
                        </a>
                    @endif
                </div>
                <div class="card-body">
                    @if ($previewUrl)
                        @if (str_starts_with((string) $file->mime_type, 'image/'))
                            <img class="img-fluid rounded border" src="{{ $previewUrl }}" alt="{{ $file->original_name }}">
                        @else
                            <iframe class="w-100 border rounded" src="{{ $previewUrl }}" title="{{ $file->original_name }}" style="min-height: 75vh;"></iframe>
                        @endif
                    @else
                        <div class="alert alert-info mb-0">{{ __('archive.not_previewable') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </main>
</body>
</html>
