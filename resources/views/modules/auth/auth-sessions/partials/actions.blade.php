@php
    $sessionPublicId = trim((string) $session->public_id);
    $hasSafeRouteIdentifier = $sessionPublicId !== '';
    $hasForceLogoutPermission = (bool) ($canForceLogout ?? auth()->user()?->can('auth.sessions.force_logout'));
    $isCurrentBrowserSession = (bool) ($isCurrentSession ?? false);
    $isForceLogoutEligible = (bool) ($isForceLogoutEligible ?? app(\Modules\Auth\Services\UserPresenceService::class)->isFreshActiveSession($session));
    $canViewSessionDetails = (bool) ($canViewDetails ?? auth()->user()?->can('auth.sessions.details'));
    $canForceLogoutSession = $hasForceLogoutPermission
        && $hasSafeRouteIdentifier
        && ! $isCurrentBrowserSession
        && $isForceLogoutEligible;
    $showCurrentSessionNotice = $hasForceLogoutPermission
        && $hasSafeRouteIdentifier
        && $isCurrentBrowserSession
        && $isForceLogoutEligible;
    $canShowActions = $hasSafeRouteIdentifier && ($canViewSessionDetails || $canForceLogoutSession || $showCurrentSessionNotice);
@endphp

@if ($canShowActions)
    <div class="dropdown font-sans-serif">
        <button class="btn btn-sm btn-falcon-default dropdown-toggle dropdown-caret-none"
            type="button"
            data-bs-toggle="dropdown"
            data-boundary="viewport"
            aria-haspopup="true"
            aria-expanded="false"
            title="{{ __('common.fields.actions') }}">
            <span class="fas fa-ellipsis-h"></span>
        </button>
        <div class="dropdown-menu dropdown-menu-end border py-2">
            @if ($canViewSessionDetails)
                <button type="button"
                    class="dropdown-item js-report-details"
                    data-details-url="{{ route('admin.auth-sessions.details', $session->public_id) }}">
                    <span class="fas fa-eye me-2"></span>{{ __('auth_sessions.actions.details') }}
                </button>
            @endif

            @if ($canForceLogoutSession)
                <button type="button"
                    class="dropdown-item text-danger js-force-logout-session"
                    data-force-logout-url="{{ route('admin.auth-sessions.force-logout', $session->public_id) }}">
                    <span class="fas fa-sign-out-alt me-2"></span>{{ __('auth_sessions.actions.end_session') }}
                </button>
            @endif

            @if ($showCurrentSessionNotice)
                <button type="button"
                    class="dropdown-item disabled text-600"
                    disabled
                    title="{{ __('auth_sessions.messages.current_session_forbidden') }}">
                    <span class="fas fa-lock me-2"></span>{{ __('auth_sessions.messages.current_session_forbidden') }}
                </button>
            @endif
        </div>
    </div>
@endif
