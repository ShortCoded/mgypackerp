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
        <h1>{{ $board->name }}</h1>
        <p>{{ __('task_boards.public.requires_access_code') }}</p>

        <form action="{{ route('public.task-boards.display.access', $board->public_token) }}" method="POST" novalidate>
            @csrf
            @if (($intendedDisplay ?? null) === 'user-display')
                <x-forms.input type="hidden" name="intended_display" value="user-display" />
            @endif
            <label for="access_code">{{ __('task_boards.attributes.access_code') }}</label>
            <x-forms.input id="access_code" name="access_code" type="password" value="{{ old('access_code') }}" autocomplete="current-password" autofocus />
            @error('access_code')
                <div class="access-error">{{ $message }}</div>
            @enderror
            <button type="submit">{{ __('task_boards.public.open_display') }}</button>
        </form>
    </main>
</body>
</html>
