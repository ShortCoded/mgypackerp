@if ($canViewDetails ?? auth()->user()?->can('auth.logs.details'))
    <button type="button"
        class="btn btn-sm btn-falcon-default js-report-details"
        data-details-url="{{ route('admin.auth-logs.details', $authLog->public_id) }}"
        title="{{ __('auth_logs.actions.details') }}">
        <span class="fas fa-eye"></span>
    </button>
@endif
