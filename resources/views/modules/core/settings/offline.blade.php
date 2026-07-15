<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $settings['direction'] === 'auto' ? config('languages.available.' . app()->getLocale() . '.dir', 'ltr') : $settings['direction'] }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="{{ $settings['theme_color'] }}">
    <title>{{ $settings['offline_title'] }}</title>
    <style>
        body { align-items: center; background: {{ $settings['background_color'] }}; color: #344050; display: flex; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; justify-content: center; margin: 0; min-height: 100vh; padding: 2rem; }
        main { max-width: 34rem; text-align: center; }
        h1 { font-size: clamp(1.75rem, 4vw, 2.5rem); margin: 0 0 .75rem; }
        p { line-height: 1.7; margin: 0; }
    </style>
</head>
<body>
    <main>
        <h1>{{ $settings['offline_title'] }}</h1>
        <p>{{ $settings['offline_message'] }}</p>
    </main>
</body>
</html>
