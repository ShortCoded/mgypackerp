@php
    $erpAsset = app(\Modules\Core\Services\AssetVersionService::class);
@endphp
<script src="{{ $erpAsset->url('vendors/popper/popper.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/bootstrap/bootstrap.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/anchorjs/anchor.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/is/is.min.js') }}"></script>
<script defer src="{{ $erpAsset->url('vendors/fontawesome/all.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/lodash/lodash.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/list.js/list.min.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/theme.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/jquery/jquery.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net/dataTables.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net-bs5/dataTables.bootstrap5.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net-responsive/js/dataTables.responsive.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net-responsive-bs5/js/responsive.bootstrap5.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net-buttons/js/dataTables.buttons.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net-buttons-bs5/js/buttons.bootstrap5.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/datatables.net-buttons/js/buttons.colVis.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/dropzone/dropzone-min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/flatpickr/flatpickr.min.js') }}"></script>
<script src="{{ $erpAsset->url('vendors/select2/select2.full.min.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/datatables-defaults.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/numeric-input.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/client-context.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/alerts.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/page-cache-guard.js') }}"></script>
@auth
    @php
        $appSession = [
            'statusUrl' => route('session.status', [], false),
            'touchUrl' => route('session.touch', [], false),
            'identity' => app(\Modules\Core\Services\SessionIdentityService::class)->for(request()),
            'lifetimeSeconds' => max(1, (int) config('session.lifetime', 120)) * 60,
            'warningBeforeSeconds' => 0,
        ];
        $appShortcuts = [
            'globalSearchTitle' => __('common.shortcuts.global_search'),
            'tableSearchTitle' => __('common.shortcuts.table_search'),
        ];
        $appNumericInputMessages = __('common.numeric_input');
        $appSelect2 = [
            'perPage' => (int) config('select2.pagination.per_page', 25),
            'delay' => (int) config('select2.delay', 250),
            'minimumInputLength' => (int) config('select2.minimum_input_length', 0),
            'clearAllLabel' => __('common.actions.clear_all'),
            'messages' => [
                'errorLoading' => __('common.messages.unexpected_error'),
                'inputTooShort' => __('common.placeholders.search'),
                'loadingMore' => __('common.messages.loading'),
                'noResults' => __('common.messages.no_results_found'),
                'searching' => __('common.messages.searching'),
            ],
        ];
        $dateFormatService = app(\Modules\Core\Services\DateFormatService::class);
        $appDatePicker = [
            'dateFormat' => $dateFormatService->jsDateFormat(),
            'locale' => app()->getLocale(),
            'direction' => config('languages.available.' . app()->getLocale() . '.dir', 'ltr'),
            'phpDateFormat' => $dateFormatService->dateFormat(),
        ];
        $appArchive = [
            'maxFiles' => (int) config('archive.uploads.max_files', 100),
            'maxFileSizeMiB' => (int) config('archive.uploads.max_file_size_mib', 50),
            'parallelUploads' => (int) config('archive.uploads.parallel_uploads', 2),
            'acceptedFiles' => collect(config('archive.documents.allowed_extensions', config('archive.allowed_extensions', [])))->map(fn (string $extension): string => '.' . ltrim($extension, '.'))->implode(','),
            'messages' => [
                'tooManyFiles' => __('archive.too_many_files', ['count' => (int) config('archive.uploads.max_files', 100)]),
                'fileTooLarge' => __('archive.file_too_large', ['size' => (int) config('archive.uploads.max_file_size_mib', 50)]),
                'invalidFileType' => __('archive.invalid_file_type'),
                'unexpectedError' => __('common.messages.unexpected_error'),
                'deleteConfirmTitle' => __('archive.messages.delete_confirm_title'),
                'deleteConfirmText' => __('archive.messages.delete_confirm_text'),
                'deleteConfirmYes' => __('archive.messages.delete_confirm_yes'),
                'folderDeleteConfirmTitle' => __('archive.messages.folder_delete_confirm_title'),
                'folderDeleteConfirmText' => __('archive.messages.folder_delete_confirm_text'),
                'folderDeleteConfirmYes' => __('archive.messages.folder_delete_confirm_yes'),
                'renameFolderTitle' => __('archive.rename_folder'),
                'folderName' => __('archive.folder_name'),
                'noFilesSelected' => __('archive.no_files_selected'),
                'publicLinkMissing' => __('archive.public_links.missing'),
                'publicLinkCopied' => __('archive.public_links.copied'),
                'no' => __('common.actions.no'),
            ],
        ];
        $appNotifications = [
            'coordinationIdentity' => $appSession['identity'],
            'pollUrl' => route('admin.notifications.poll', [], false),
            'readUrl' => route('admin.notifications.read', ['notification' => '__NOTIFICATION__'], false),
            'readAllUrl' => route('admin.notifications.read-all', [], false),
            'intervalMs' => 45000,
            'hiddenIntervalMs' => 120000,
            'jitterMinMs' => 3000,
            'jitterMaxMs' => 10000,
            'messages' => [
                'empty' => __('notifications.empty'),
            ],
        ];
        $appNotificationSound = [
            'source' => $erpAsset->url('assets/sounds/notification.mp3'),
            'storageKey' => 'erp_notification_sound_enabled',
            'throttleMs' => 3000,
            'volume' => 0.32,
        ];
        $webPushConfigured = filled(config('webpush.vapid.subject'))
            && filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
        $appPushNotifications = [
            'enabled' => $appPwaSettings['enabled'] && $appPwaSettings['service_worker_enabled'] && $webPushConfigured,
            'publicKey' => $webPushConfigured ? config('webpush.vapid.public_key') : null,
            'coordinationIdentity' => hash_hmac(
                'sha256',
                'push-notifications:v1|' . (string) auth()->user()->getRouteKey(),
                (string) config('app.key'),
            ),
            'coordinationTtlMs' => 24 * 60 * 60 * 1000,
            'storeUrl' => route('admin.notifications.push-subscriptions.store', [], false),
            'destroyUrl' => route('admin.notifications.push-subscriptions.destroy', [], false),
            'messages' => [
                'enable' => __('notifications.push.enable'),
                'disable' => __('notifications.push.disable'),
                'enabled' => __('notifications.push.enabled'),
                'disabled' => __('notifications.push.disabled'),
                'denied' => __('notifications.push.denied'),
                'unavailable' => __('notifications.push.unavailable'),
                'failed' => __('notifications.push.failed'),
            ],
        ];
        $appNavigationSearch = [
            'searchUrl' => route('admin.navigation-search', [], false),
            'recentStoreUrl' => route('admin.navigation-search.recent.store', [], false),
            'recentClearUrl' => route('admin.navigation-search.recent.clear', [], false),
            'debounceMs' => 250,
            'messages' => [
                'search' => __('navigation_search.search'),
                'startTyping' => __('navigation_search.start_typing'),
                'noResults' => __('navigation_search.no_results'),
                'recent' => __('navigation_search.recent'),
                'results' => __('navigation_search.results'),
                'loading' => __('navigation_search.loading'),
                'openPage' => __('navigation_search.open_page'),
                'clearRecent' => __('navigation_search.clear_recent'),
                'keyboardHint' => __('navigation_search.keyboard_hint'),
            ],
        ];
        $appOperatingContextConfig = [
            'optionsUrl' => route('admin.operating-context.options', [], false),
            'selectUrl' => route('admin.operating-context.select', [], false),
            'current' => $appOperatingContext ?? ['company' => null, 'branch' => null, 'financial_period' => null, 'requires_selection' => true],
            'messages' => [
                'required' => __('operating_context.messages.required'),
                'saved' => __('operating_context.messages.saved'),
                'autoSelected' => __('operating_context.messages.auto_selected'),
                'unexpectedError' => __('common.messages.unexpected_error'),
                'validationFailed' => __('common.messages.validation_failed'),
                'notSelected' => __('operating_context.not_selected'),
                'noCompanies' => __('operating_context.no_companies'),
                'noBranches' => __('operating_context.no_branches'),
                'noFinancialPeriods' => __('operating_context.no_financial_periods'),
            ],
        ];
        $appAuthMessages = [
            'fallbackError' => __('auth.ajax.unexpected_error'),
            'validationSummary' => __('auth.login.validation_summary'),
            'tooManyAttempts' => __('auth.ajax.too_many_attempts'),
            'forbidden' => __('auth.ajax.forbidden'),
            'unauthenticated' => __('auth.ajax.unauthenticated'),
            'sessionExpiredTryAgain' => __('auth.ajax.session_expired_try_again'),
            'locked' => __('auth.lock_screen.locked'),
            'close' => __('auth.alerts.close'),
        ];
        $appAjaxErrors = [
            'loginUrl' => route('login', [], false),
            'loginLabel' => __('auth.buttons.login'),
            'refreshLabel' => __('auth_sessions.actions.refresh'),
            'messages' => [
                'authenticationRequired' => __('erp_errors.authentication_required'),
                'permissionDenied' => __('erp_errors.permission_denied'),
                'notFound' => __('erp_errors.not_found'),
                'conflict' => __('erp_errors.concurrent_update'),
                'sessionExpired' => __('erp_errors.session_expired'),
                'validationFailed' => __('erp_errors.validation_failed'),
                'rateLimited' => __('erp_errors.rate_limited'),
                'networkError' => __('common.messages.unexpected_error'),
                'unexpected' => __('common.messages.unexpected_error'),
            ],
        ];
    @endphp
    <script>
        window.AppSession = @json($appSession);
        window.AppShortcuts = @json($appShortcuts);
        window.AppNumericInputMessages = @json($appNumericInputMessages);
        window.AppSelect2 = @json($appSelect2);
        window.AppDatePicker = @json($appDatePicker);
        window.AppArchive = @json($appArchive);
        window.AppNotificationSoundConfig = @json($appNotificationSound);
        window.AppPushNotifications = @json($appPushNotifications);
        window.AppNotifications = @json($appNotifications);
        window.AppNavigationSearch = @json($appNavigationSearch);
        window.AppOperatingContext = @json($appOperatingContextConfig);
        window.authMessages = Object.assign({}, window.authMessages || {}, @json($appAuthMessages));
        window.authCsrf = Object.assign({}, window.authCsrf || {}, {
            refreshUrl: @json(route('auth.csrf-token', [], false))
        });
        window.AppAjaxErrorsConfig = @json($appAjaxErrors);
    </script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/ajax-errors.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Auth/helpers.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/session-timeout.js') }}"></script>
    @production
        <script src="{{ $erpAsset->url('assets/js/modules/Core/production-guard.js') }}"></script>
    @endproduction
    <script src="{{ $erpAsset->url('assets/js/modules/Core/shortcuts.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/select2-ajax.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/flatpickr-locales.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/date-picker.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/notification-sound.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/push-notifications.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/notifications.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/navigation-search.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/operating-context.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/contact-actions.js') }}"></script>
    <script src="{{ $erpAsset->url('assets/js/modules/Core/archive-uploader.js') }}"></script>
@endauth
<script src="{{ $erpAsset->url('assets/js/modules/Core/connectivity.js') }}"></script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/layout.js') }}"></script>
@php
    $appPwaSettings = $appPwaSettings ?? app(\Modules\Core\Services\PwaSettingsService::class)->settings();
@endphp
<script>
    window.AppPwaRuntime = {
        enabled: @json($appPwaSettings['enabled'] && $appPwaSettings['service_worker_enabled']),
        serviceWorkerUrl: @json(route('pwa.service-worker', [], false)),
        scope: @json($appPwaSettings['scope']),
        cachePrefix: 'erp-pwa-cache'
    };
</script>
<script src="{{ $erpAsset->url('assets/js/modules/Core/pwa-runtime.js') }}"></script>
