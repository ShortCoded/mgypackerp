@if ($canViewDetails ?? auth()->user()?->can('activity.logs.details'))
    <button type="button"
        class="btn btn-sm btn-falcon-default js-report-details"
        data-details-url="{{ route('admin.activity-logs.details', $activity->public_id) }}"
        title="{{ __('activity_logs.actions.details') }}">
        <span class="fas fa-eye"></span>
    </button>
@endif
