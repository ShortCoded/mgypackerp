<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $direction ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $reportTitle ?? $title ?? __('reports.report_title') }}</title>
    @include('reports.partials.styles')
</head>
<body>
    <main>
        @if ($limited ?? false)
            <div class="report-warning">{{ __('reports.pdf_limited') }}</div>
        @endif
        @yield('report')
    </main>
</body>
</html>
