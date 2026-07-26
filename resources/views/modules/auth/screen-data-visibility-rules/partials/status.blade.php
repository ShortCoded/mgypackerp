<span class="badge rounded-pill badge-subtle-{{ $record->is_active ? 'success' : 'secondary' }}">
    {{ __('screen_data_visibility_rules.statuses.'.($record->is_active ? 'active' : 'inactive')) }}
</span>
