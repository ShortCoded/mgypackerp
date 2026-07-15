@php
    use Modules\Core\Services\AssetVersionService;

    $erpAsset = app(AssetVersionService::class);
    $isRtl = app()->getLocale() === 'ar';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ __('task_boards.display_title') }}</title>
    <link href="{{ $erpAsset->url('assets/css/modules/Core/task-board-display.css') }}" rel="stylesheet">
</head>
<body class="task-board-access-screen">
    <main class="access-panel">
        <div class="display-kicker">{{ __('task_boards.display_title') }}</div>
        <h1>{{ __('task_boards.public.unavailable') }}</h1>
    </main>
</body>
</html>
