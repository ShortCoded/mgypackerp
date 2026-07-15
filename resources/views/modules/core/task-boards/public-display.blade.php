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
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $board->name }} - {{ __('task_boards.display_title') }}</title>
    <link href="{{ $erpAsset->url('assets/css/modules/Core/task-board-display.css') }}" rel="stylesheet">
</head>
<body class="task-board-display-screen">
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
                <div class="display-kicker">{{ __('task_boards.display_title') }}</div>
                <h1>{{ $board->name }}</h1>
                @if ($board->description)
                    <p>{{ $board->description }}</p>
                @endif
            </div>
        </div>

        <div class="display-controls">
            <div class="display-timestamp" style="display: none">
                <span>{{ __('task_boards.public.last_updated') }}</span>
                <strong id="task-board-last-updated">-</strong>
            </div>
            <button class="display-button" type="button" id="task-board-refresh">
                <span>{{ __('task_boards.public.refresh') }}</span>
            </button>
            <button class="display-button" type="button" id="task-board-fullscreen" style="display: none">
                <span>{{ __('task_boards.actions.fullscreen') }}</span>
            </button>
            <button class="display-button display-theme-toggle" type="button" id="task-board-theme-toggle" aria-live="polite">
                <span>{{ $theme === 'dark' ? __('task_boards.public.light_mode') : __('task_boards.public.dark_mode') }}</span>
            </button>
        </div>
    </header>

    <div class="display-error" id="task-board-display-error" hidden></div>

    <nav class="user-display-tabs task-display-tabs" id="task-board-user-tabs" role="tablist" aria-label="{{ __('task_boards.public.users') }}"></nav>

    <main class="display-main task-display-main">
        <section class="task-display-stage" id="task-board-display-stage" aria-live="polite"></section>
    </main>

    <script>
        window.taskBoardDisplayConfig = {
            dataUrl: @json(route('public.task-boards.display-data', $board->public_token)),
            changeStatusUrl: @json(route('public.task-boards.display.change-status', $board->public_token)),
            refreshInterval: {{ (int) $pollingInterval }},
            rotationInterval: {{ (int) $rotationInterval }},
            defaultTheme: @json($theme),
            storageKey: @json('task-board-display-theme.' . $board->public_token),
            csrfToken: @json(csrf_token()),
            labels: {
                refresh: @json(__('task_boards.public.refresh')),
                refreshing: @json(__('task_boards.public.refreshing')),
                refreshFailed: @json(__('task_boards.messages.display_refresh_failed')),
                empty: @json(__('task_boards.public.no_active_tasks')),
                noUsers: @json(__('task_boards.public.no_users')),
                noTasks: @json(__('task_boards.public.no_tasks')),
                noTasksForUser: @json(__('task_boards.public.no_tasks_for_user')),
                allTasks: @json(__('task_boards.public.all_tasks')),
                taskCount: @json(__('task_boards.public.task_count')),
                attachmentCount: @json(__('task_boards.public.attachment_count')),
                elapsed: @json(__('task_boards.public.elapsed')),
                previousTask: @json(__('task_boards.public.previous_task')),
                nextTask: @json(__('task_boards.public.next_task')),
                preview: @json(__('task_boards.public.preview')),
                download: @json(__('task_boards.public.download')),
                close: @json(__('task_boards.public.close')),
                lightMode: @json(__('task_boards.public.light_mode')),
                darkMode: @json(__('task_boards.public.dark_mode')),
                changeStatusError: @json(__('quick_tasks.messages.status_permission_denied')),
                statusChanged: @json(__('quick_tasks.messages.status_changed')),
                statusChangeFailed: @json(__('quick_tasks.messages.status_change_failed')),
                doneConfirmTitle: @json(__('quick_tasks.messages.status_done_confirm_title')),
                doneConfirmText: @json(__('quick_tasks.messages.status_done_confirm_text')),
                doneConfirmYes: @json(__('quick_tasks.messages.status_done_confirm_yes')),
            }
        };
    </script>
    <script src="{{ $erpAsset->url('vendors/jquery/jquery.min.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/task-board-display.js') }}"></script>
</body>
</html>
