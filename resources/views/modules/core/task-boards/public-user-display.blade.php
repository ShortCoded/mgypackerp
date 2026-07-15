@php
    use Modules\Core\Services\AssetVersionService;

    $erpAsset = app(AssetVersionService::class);
    $isRtl = app()->getLocale() === 'ar';
    $companyName = trim((string) ($branding['name'] ?? ''));
    $companyLogoUrl = trim((string) ($branding['logo_url'] ?? ''));
    $theme = in_array($displayTheme ?? 'light', ['light', 'dark'], true) ? $displayTheme : 'light';
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}" data-theme="{{ $theme }}" data-default-theme="{{ $theme }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>{{ $board->name }} - {{ __('task_boards.user_display_title') }}</title>
    <link href="{{ $erpAsset->url('assets/css/modules/Core/task-board-display.css') }}" rel="stylesheet">
</head>
<body class="task-board-display-screen task-board-user-display-screen">
    <header class="display-header">
        <div class="display-identity">
            <div class="display-brand">
                @if ($companyLogoUrl !== '')
                    <img src="{{ $companyLogoUrl }}" alt="{{ $companyName !== '' ? $companyName : __('task_boards.attributes.logo') }}">
                @endif
                @if ($companyName !== '')
                    <div>
                        <span>{{ __('task_boards.attributes.company_name') }}</span>
                        <strong>{{ $companyName }}</strong>
                    </div>
                @endif
            </div>

            <div class="display-heading">
                <div class="display-kicker">{{ __('task_boards.user_display_title') }}</div>
                <h1>{{ $board->name }}</h1>
                @if ($board->description)
                    <p>{{ $board->description }}</p>
                @endif
            </div>
        </div>

        <div class="display-controls">
            <div class="display-timestamp" style="display: none">
                <span>{{ __('task_boards.public.last_updated') }}</span>
                <strong id="task-user-display-last-updated">-</strong>
            </div>
            <button class="display-button" type="button" id="task-user-display-refresh">
                <span>{{ __('task_boards.public.refresh') }}</span>
            </button>
            <button class="display-button" type="button" id="task-user-display-fullscreen" style="display: none">
                <span>{{ __('task_boards.actions.fullscreen') }}</span>
            </button>
            <button class="display-button display-theme-toggle" type="button" id="task-user-display-theme-toggle" aria-live="polite">
                <span>{{ $theme === 'dark' ? __('task_boards.public.light_mode') : __('task_boards.public.dark_mode') }}</span>
            </button>
        </div>
    </header>

    <div class="display-error" id="task-user-display-error" hidden></div>

    <nav class="user-display-tabs" id="task-user-tabs" role="tablist" aria-label="{{ __('task_boards.public.users') }}"></nav>

    <main class="display-main user-display-main">
        <section class="user-display-stage" id="task-user-display-stage" aria-live="polite"></section>
    </main>

    <script>
        window.taskBoardUserDisplayConfig = {
            dataUrl: @json(route('public.task-boards.user-display-data', $board->public_token)),
            refreshInterval: {{ (int) $refreshInterval }},
            rotationInterval: {{ (int) $rotationInterval }},
            defaultTheme: @json($theme),
            storageKey: @json('task-board-user-display-theme.' . $board->public_token),
            labels: {
                refresh: @json(__('task_boards.public.refresh')),
                refreshing: @json(__('task_boards.public.refreshing')),
                refreshFailed: @json(__('task_boards.messages.display_refresh_failed')),
                noUsers: @json(__('task_boards.public.no_users')),
                noTasks: @json(__('task_boards.public.no_tasks')),
                noTasksForUser: @json(__('task_boards.public.no_tasks_for_user')),
                assignedUser: @json(__('quick_tasks.attributes.assigned_to')),
                taskCount: @json(__('task_boards.public.task_count')),
                attachmentCount: @json(__('task_boards.public.attachment_count')),
                elapsed: @json(__('task_boards.public.elapsed')),
                lightMode: @json(__('task_boards.public.light_mode')),
                darkMode: @json(__('task_boards.public.dark_mode'))
            }
        };
    </script>
    <script src="{{ $erpAsset->url('vendors/jquery/jquery.min.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/task-board-user-display.js') }}"></script>
</body>
</html>
